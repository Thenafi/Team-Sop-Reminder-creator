<?php
/**
 * cron.php — Heartbeat script. Run via cron every 2 minutes.
 * 
 * Phase 1: Discover new accepted reservations for enabled properties.
 *          Calculate random send times and insert into DB.
 * Phase 2: Send any reminders whose scheduled_at has passed.
 * 
 * Cron entry (cPanel):
 *   ∗/2 ∗ ∗ ∗ ∗ php /path/to/cron.php >> /path/to/sop_cron.log 2>&1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hospitable_api.php';
require_once __DIR__ . '/shift_scheduler.php';
require_once __DIR__ . '/slack.php';

if (php_sapi_name() !== 'cli') {
    // If running via a browser, ensure it doesn't time out or stop if the tab is closed
    set_time_limit(0);
    ignore_user_abort(true);
    // Format the output clearly
    echo "<pre>\n";
}

logMessage("=== CRON START ===");

$db = getDB();
$config = loadConfig();

// ═══════════════════════════════════════════════════════════════
// PHASE 1: Discover new reservations
// ═══════════════════════════════════════════════════════════════
logMessage("Phase 1: Discovering new reservations...");

// Get enabled property UUIDs from config
$enabledProperties = [];
$sops = $config['sops'] ?? [];

// Build a unique master list of ALL properties referenced in ANY SOP, tracking the MAX scan days needed for each
$propertyScanDays = [];
foreach ($sops as $sop) {
    $sopScanDays = (int) ($sop['scan_days_ahead'] ?? 2);
    if (!empty($sop['properties']) && is_array($sop['properties'])) {
        foreach ($sop['properties'] as $pid) {
            if (!isset($propertyScanDays[$pid])) {
                $propertyScanDays[$pid] = $sopScanDays;
            } else {
                $propertyScanDays[$pid] = max($propertyScanDays[$pid], $sopScanDays);
            }
        }
    }
}
$activePropertyUuids = array_keys($propertyScanDays);

if (empty($sops) || empty($activePropertyUuids)) {
    logMessage("No active SOPs or assigned properties found in config. Skipping Phase 1.");
} else {
    logMessage("Active SOPs: " . count($sops) . " | Unique Properties to Scan: " . count($activePropertyUuids));

    $allReservations = [];

    // Fetch accepted reservations for each unique active property
    foreach ($activePropertyUuids as $uuid) {
        $prop = $config['properties'][$uuid] ?? [];
        $scanDaysAhead = $propertyScanDays[$uuid];
        
        // Build timezone-aware start and end dates
        $tz = $prop['timezone'] ?? '-0500';
        try {
            $dtz = new DateTimeZone($tz);
        } catch (Exception $e) {
            $dtz = new DateTimeZone('UTC');
        }

        $startDt = new DateTime('yesterday', $dtz);
        $endDt = new DateTime("today + $scanDaysAhead days", $dtz);

        $startDate = $startDt->format('Y-m-d');
        $endDate = $endDt->format('Y-m-d');

        logMessage("Fetching reservations for property $uuid from $startDate to $endDate (TZ: $tz)");
        $propReservations = fetchReservations($uuid, $startDate, $endDate);
        
        // Tag with property info in case API omits it
        foreach ($propReservations as &$res) {
            // Assign property id manually so we know where it came from
            if (empty($res['properties'])) {
                $res['properties'] = [
                    ['id' => $uuid, 'name' => $prop['name'] ?? '']
                ];
            }
        }
        unset($res);

        $allReservations = array_merge($allReservations, $propReservations);
    }

    $newCount = 0;
    $reservationSyncLog = [];
    
    // Process every reservation against every applicable SOP
    foreach ($allReservations as $res) {
        $reservationId = $res['id'];

        // Extract property info early to match against SOPs
        $propertyId = '';
        $propertyName = '';
        if (!empty($res['properties'])) {
            $propertyId = $res['properties'][0]['id'] ?? '';
            $propertyName = $res['properties'][0]['name'] ?? '';
        }

        if (!$propertyId) {
            logMessage("WARNING: Reservation $reservationId missing property ID. Skipping.");
            $reservationSyncLog[$reservationId] = [
                'guest' => 'Unknown', 'property' => 'Unknown', 'platform' => 'Unknown', 'check_in' => 'Unknown',
                'sops' => ['ALL' => 'Skipped (Missing property ID)']
            ];
            continue;
        }

        // Apply config-level property name if available
        if (isset($config['properties'][$propertyId]['name'])) {
            $propertyName = $config['properties'][$propertyId]['name'] ?: $propertyName;
        }

        // Extract guest name
        $guestName = 'Unknown Guest';
        if (isset($res['guest'])) {
            $firstName = $res['guest']['first_name'] ?? '';
            $lastName = $res['guest']['last_name'] ?? '';
            $guestName = trim("$firstName $lastName") ?: 'Unknown Guest';
        }

        // Extract dates and IDs
        $checkIn = $res['check_in'] ?? $res['arrival_date'] ?? null;
        $checkOut = $res['check_out'] ?? $res['departure_date'] ?? null;
        $conversationId = $res['conversation_id'] ?? '';
        $platformId = $res['platform_id'] ?? $res['code'] ?? '';
        $platform = $res['platform'] ?? ''; // e.g. airbnb, booking, vrbo

        if (!$checkIn || !$checkOut) {
            logMessage("WARNING: Reservation $reservationId missing check-in/out. Skipping.");
            $reservationSyncLog[$reservationId] = [
                'guest' => $guestName, 'property' => $propertyName, 'platform' => $platform, 'check_in' => 'Unknown',
                'sops' => ['ALL' => 'Skipped (Missing check-in/out)']
            ];
            continue;
        }

        if (!isset($reservationSyncLog[$reservationId])) {
            $reservationSyncLog[$reservationId] = [
                'guest' => $guestName,
                'property' => $propertyName,
                'check_in' => $checkIn,
                'platform' => $platform,
                'sops' => []
            ];
        }

        // Find all SOPs assigned to this property
        foreach ($sops as $sop) {
            $sopId = $sop['id'] ?? 'unknown_sop';
            if (!in_array($propertyId, $sop['properties'] ?? [])) {
                $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Skipped (Property not assigned)";
                continue; // This SOP doesn't apply to this property
            }

            $platformFilterMode = $sop['platform_filter_mode'] ?? 'include';
            if ($platformFilterMode === 'exclude') {
                if (!empty($sop['platforms'])) {
                    $isExcluded = false;
                    $normalizedResPlatform = strtolower(trim($platform));
                    // If 'all' is explicitly checked in exclude mode, it excludes everything.
                    if (in_array('all', $sop['platforms'])) {
                        $isExcluded = true;
                    } else {
                        foreach ($sop['platforms'] as $sp) {
                            if (strtolower(trim($sp)) === $normalizedResPlatform) {
                                $isExcluded = true;
                                break;
                            }
                        }
                    }
                    if ($isExcluded) {
                        $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Skipped (Platform excluded)";
                        continue; // This SOP is excluded for this platform
                    }
                }
            } else {
                // Include mode (default)
                if (!empty($sop['platforms']) && !in_array('all', $sop['platforms'])) {
                    $platMatches = false;
                    $normalizedResPlatform = strtolower(trim($platform));
                    foreach ($sop['platforms'] as $sp) {
                        if (strtolower(trim($sp)) === $normalizedResPlatform) {
                            $platMatches = true;
                            break;
                        }
                    }
                    if (!$platMatches) {
                        $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Skipped (Platform not included)";
                        continue; // This SOP doesn't apply to this platform
                    }
                }
            }

            // Check if this specific (reservation, sop) tuple exists
            $stmt = $db->prepare("SELECT id FROM reminders WHERE reservation_id = ? AND sop_id = ?");
            $stmt->execute([$reservationId, $sopId]);
            if ($stmt->fetch()) {
                $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Skipped (Already scheduled)";
                continue; // Already scheduled
            }
            
            $reminderHours = (int) ($sop['reminder_hours_before'] ?? env('REMINDER_HOURS_BEFORE', 12));
            $sopMessage = $sop['sop_message'] ?? 'No SOP message defined.';

            // Calculate random send time
            $schedule = calculateRandomSendTime($checkIn, $reminderHours);
            logMessage("Reservation $reservationId ($sopId): " . $schedule['debug']);

            // Convert scheduled timestamp to MySQL datetime
            $scheduledAt = date('Y-m-d H:i:s', $schedule['timestamp']);
            // Store check-in/out as plain dates (no time) to avoid timezone-shifted midnight
            // rolling the date over by a day. The API returns date-only strings — treat them as such.
            $checkInFormatted = date('Y-m-d', strtotime($checkIn . ' noon UTC'));
            $checkOutFormatted = date('Y-m-d', strtotime($checkOut . ' noon UTC'));

            // Insert into DB
            try {
                $stmt = $db->prepare("
                    INSERT INTO reminders 
                        (reservation_id, platform_id, property_id, property_name, guest_name, 
                         check_in, check_out, conversation_id, sop_id, sop_message, scheduled_at, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ");
                $stmt->execute([
                    $reservationId,
                    $platformId,
                    $propertyId,
                    $propertyName,
                    $guestName,
                    $checkInFormatted,
                    $checkOutFormatted,
                    $conversationId,
                    $sopId,
                    $sopMessage,
                    $scheduledAt,
                ]);
                $newCount++;

                if ($schedule['immediate']) {
                    logMessage("→ IMMEDIATE send scheduled for $guestName at $propertyName (SOP: {$sop['name']})");
                } else {
                    logMessage("→ Scheduled for $scheduledAt: $guestName at $propertyName (SOP: {$sop['name']})");
                }
                $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Scheduled for $scheduledAt";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    logMessage("Reservation $reservationId ($sopId) already exists in DB (race condition). Skipping.");
                    $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Skipped (Already in DB race condition)";
                } else {
                    logMessage("ERROR: Could not insert reservation $reservationId ($sopId): " . $e->getMessage());
                    $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "ERROR: " . $e->getMessage();
                }
            }
        }
    }

    logMessage("Phase 1 complete. $newCount new reminders scheduled.");

    // Write daily sync log
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $dailyLogFile = $logDir . '/pulled_reservations_' . date('Y-m-d') . '.log';
    $logOutput = "=== RUN: " . date('Y-m-d H:i:s') . " (Found " . count($allReservations) . " total reservations) ===\n";
    foreach ($reservationSyncLog as $id => $data) {
        $logOutput .= "Res ID: {$id} | Guest: {$data['guest']} | Prop: {$data['property']} | Platform: {$data['platform']} | Check-in: {$data['check_in']}\n";
        if (empty($data['sops'])) {
             $logOutput .= "  -> (No matching SOPs evaluated)\n";
        } else {
            foreach ($data['sops'] as $sName => $outcome) {
                $logOutput .= "  -> SOP: {$sName} => {$outcome}\n";
            }
        }
    }
    $logOutput .= "\n";
    file_put_contents($dailyLogFile, $logOutput, FILE_APPEND);
    
    // Cleanup old logs (> 7 days)
    $files = glob($logDir . '/pulled_reservations_*.log');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) > 7 * 24 * 3600)) {
            @unlink($file);
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// PHASE 2: Check for modified check-in times (edge case)
// ═══════════════════════════════════════════════════════════════
if (env('API_MODE', 'live') === 'live') {
    logMessage("Phase 2: Checking for modified check-in times...");

    // Get all scheduled (not yet sent) reminders
    $stmt = $db->prepare("SELECT * FROM reminders WHERE status = 'scheduled'");
    $stmt->execute();
    $scheduledReminders = $stmt->fetchAll();

    if (!empty($scheduledReminders)) {
        // Build a map of Phase 1 results for quick lookup
        $resMap = [];
        foreach ($allReservations as $res) {
            $resMap[$res['id']] = $res;
        }

        foreach ($scheduledReminders as $reminder) {
            $resId = $reminder['reservation_id'];
            $apiRes = null;

            // 1. Check if it was already fetched in Phase 1
            if (isset($resMap[$resId])) {
                $apiRes = $resMap[$resId];
            } else {
                // 2. If it's due soon (or past due), but wasn't in Phase 1 (out of window), fetch it specifically
                $isDueSoon = strtotime($reminder['scheduled_at']) <= (time() + 600); // due now or in next 10 mins
                if ($isDueSoon) {
                    logMessage("Reservation $resId was NOT in scan window but is due. Fetching specifically...");
                    $apiRes = fetchReservation($resId);
                }
            }

            if ($apiRes) {
                // Verify status first
                $status = $apiRes['status'] ?? $apiRes['reservation_status']['current']['category'] ?? 'unknown';
                if ($status !== 'accepted') {
                    logMessage("Reservation $resId is no longer accepted (status: $status). Marking failed.");
                    $stmt = $db->prepare("UPDATE reminders SET status = 'failed', error_message = ? WHERE id = ?");
                    $stmt->execute(["Reservation no longer accepted (status: $status)", $reminder['id']]);
                    continue;
                }

                $apiCheckIn = date('Y-m-d', strtotime(($apiRes['check_in'] ?? $apiRes['arrival_date']) . ' noon UTC'));
                $dbCheckIn = date('Y-m-d', strtotime($reminder['check_in'] . ' noon UTC'));

                if ($apiCheckIn !== $dbCheckIn) {
                    logMessage("Check-in changed for reservation $resId: DB=$dbCheckIn → API=$apiCheckIn. Recalculating...");

                    $sopId = $reminder['sop_id'];
                    $reminderHours = env('REMINDER_HOURS_BEFORE', 12);
                    foreach ($sops as $sop) {
                        if ($sop['id'] === $sopId) {
                            $reminderHours = (int) ($sop['reminder_hours_before'] ?? env('REMINDER_HOURS_BEFORE', 12));
                            break;
                        }
                    }

                    $schedule = calculateRandomSendTime($apiCheckIn, $reminderHours);
                    $newScheduledAt = date('Y-m-d H:i:s', $schedule['timestamp']);

                    $stmt = $db->prepare("UPDATE reminders SET check_in = ?, scheduled_at = ? WHERE id = ?");
                    $stmt->execute([date('Y-m-d', strtotime($apiCheckIn . ' noon UTC')), $newScheduledAt, $reminder['id']]);
                    logMessage("→ Rescheduled to $newScheduledAt");
                }
            }
        }
    } else {
        logMessage("Skipping check-in time verification: No scheduled reminders.");
    }
}

// ═══════════════════════════════════════════════════════════════
// PHASE 3: Send due reminders
// ═══════════════════════════════════════════════════════════════
logMessage("Phase 3: Checking for due reminders...");

$now = date('Y-m-d H:i:s');
$stmt = $db->prepare("SELECT * FROM reminders WHERE status = 'scheduled' AND scheduled_at <= ?");
$stmt->execute([$now]);
$dueReminders = $stmt->fetchAll();

logMessage("Found " . count($dueReminders) . " due reminders.");

foreach ($dueReminders as $reminder) {
    $reservationId = $reminder['reservation_id'];
    $sopId = $reminder['sop_id'];

    // ── Use fresh template if available in config ──
    $sopMessage = $reminder['sop_message']; // fallback
    foreach ($sops as $sop) {
        if (($sop['id'] ?? '') === $sopId) {
            $sopMessage = $sop['sop_message'] ?: $sopMessage;
            break;
        }
    }

    $messageText = buildReminderMessage($reminder, $sopMessage);
    logMessage("Sending reminder for reservation $reservationId...");

    $result = sendSlackMessage($messageText);

    if ($result['ok']) {
        // ✅ Success
        $stmt = $db->prepare("UPDATE reminders SET status = 'sent', sent_at = NOW() WHERE id = ?");
        $stmt->execute([$reminder['id']]);
        logMessage("✅ Sent successfully: " . $reminder['guest_name'] . " at " . $reminder['property_name']);
    } else {
        // ❌ Failure
        $errorMsg = $result['error'] ?? 'Unknown error';
        $stmt = $db->prepare("UPDATE reminders SET status = 'failed', error_message = ? WHERE id = ?");
        $stmt->execute([$errorMsg, $reminder['id']]);
        logMessage("❌ FAILED: " . $reminder['guest_name'] . " — $errorMsg");

        // Send failure alert
        sendFailureAlert($reminder, $errorMsg);
    }

    // Rate limit: 1 second between messages
    sleep(1);
}

logMessage("=== CRON END ===\n");
