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
            continue;
        }

        // Find all SOPs assigned to this property
        foreach ($sops as $sop) {
            $sopId = $sop['id'] ?? 'unknown_sop';
            if (!in_array($propertyId, $sop['properties'] ?? [])) {
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
                        continue; // This SOP doesn't apply to this platform
                    }
                }
            }

            // Check if this specific (reservation, sop) tuple exists
            $stmt = $db->prepare("SELECT id FROM reminders WHERE reservation_id = ? AND sop_id = ?");
            $stmt->execute([$reservationId, $sopId]);
            if ($stmt->fetch()) {
                continue; // Already scheduled
            }
            
            $reminderHours = (int) ($sop['reminder_hours_before'] ?? env('REMINDER_HOURS_BEFORE', 12));
            $sopMessage = $sop['sop_message'] ?? 'No SOP message defined.';

            // Calculate random send time
            $schedule = calculateRandomSendTime($checkIn, $reminderHours);
            logMessage("Reservation $reservationId ($sopId): " . $schedule['debug']);

            // Convert scheduled timestamp to MySQL datetime
            $scheduledAt = date('Y-m-d H:i:s', $schedule['timestamp']);
            $checkInFormatted = date('Y-m-d H:i:s', strtotime($checkIn));
            $checkOutFormatted = date('Y-m-d H:i:s', strtotime($checkOut));

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
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    logMessage("Reservation $reservationId ($sopId) already exists in DB (race condition). Skipping.");
                } else {
                    logMessage("ERROR: Could not insert reservation $reservationId ($sopId): " . $e->getMessage());
                }
            }
        }
    }

    logMessage("Phase 1 complete. $newCount new reminders scheduled.");
}

// ═══════════════════════════════════════════════════════════════
// PHASE 2: Send due reminders
// ═══════════════════════════════════════════════════════════════
logMessage("Phase 2: Checking for due reminders...");

$now = date('Y-m-d H:i:s');
$stmt = $db->prepare("SELECT * FROM reminders WHERE status = 'scheduled' AND scheduled_at <= ?");
$stmt->execute([$now]);
$dueReminders = $stmt->fetchAll();

logMessage("Found " . count($dueReminders) . " due reminders.");

foreach ($dueReminders as $reminder) {
    $reservationId = $reminder['reservation_id'];
    $propertyId = $reminder['property_id'];

    // ── Edge case: Re-verify reservation is still accepted ──
    // (skip re-check in mock mode to avoid unnecessary API calls)
    if (env('API_MODE', 'live') === 'live' && $propertyId) {
        $currentStatus = checkReservationStatus($reservationId, $propertyId);
        
        if ($currentStatus === null) {
            logMessage("API Error: Could not fetch status for reservation $reservationId. Skipping to prevent sending in error.");
            $stmt = $db->prepare("UPDATE reminders SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->execute(["Could not verify reservation status with API", $reminder['id']]);
            continue;
        }

        if ($currentStatus !== 'accepted') {
            logMessage("Reservation $reservationId is no longer accepted (status: $currentStatus). Skipping.");
            $stmt = $db->prepare("UPDATE reminders SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->execute(["Reservation no longer accepted (status: $currentStatus)", $reminder['id']]);
            continue;
        }
    }

    // ── Build and send the Slack message ──
    $sopMessage = $reminder['sop_message']; // Fetched directly from the new column!

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

// ═══════════════════════════════════════════════════════════════
// PHASE 3: Check for modified check-in times (edge case)
// ═══════════════════════════════════════════════════════════════
if (env('API_MODE', 'live') === 'live' && !empty($activePropertyUuids)) {
    logMessage("Phase 3: Checking for modified check-in times...");

    // Get all scheduled (not yet sent) reminders
    $stmt = $db->prepare("SELECT * FROM reminders WHERE status = 'scheduled'");
    $stmt->execute();
    $scheduledReminders = $stmt->fetchAll();

    if (!empty($scheduledReminders) && !empty($allReservations)) {
        // We already fetched $allReservations in Phase 1 for all active properties
        // within their respective scan windows. Let's build a map for quick lookup.
        $resMap = [];
        foreach ($allReservations as $res) {
            $resMap[$res['id']] = $res;
        }

        foreach ($scheduledReminders as $reminder) {
            // Only check reservations that were returned in Phase 1's scan window
            if (isset($resMap[$reminder['reservation_id']])) {
                $apiRes = $resMap[$reminder['reservation_id']];
                $apiCheckIn = date('Y-m-d H:i:s', strtotime($apiRes['check_in'] ?? $apiRes['arrival_date']));
                $dbCheckIn = $reminder['check_in'];

                if ($apiCheckIn !== $dbCheckIn) {
                    logMessage("Check-in changed for reservation " . $reminder['reservation_id'] .
                        ": DB=$dbCheckIn → API=$apiCheckIn. Recalculating...");

                    $propertyId = $reminder['property_id'];
                    $sopId = $reminder['sop_id'];
                    
                    // Find the SOP to get reminder_hours_before
                    $reminderHours = env('REMINDER_HOURS_BEFORE', 12); // fallback
                    foreach ($sops as $sop) {
                        if ($sop['id'] === $sopId) {
                            $reminderHours = (int) ($sop['reminder_hours_before'] ?? env('REMINDER_HOURS_BEFORE', 12));
                            break;
                        }
                    }

                    $schedule = calculateRandomSendTime($apiCheckIn, $reminderHours);
                    $newScheduledAt = date('Y-m-d H:i:s', $schedule['timestamp']);

                    $stmt = $db->prepare("UPDATE reminders SET check_in = ?, scheduled_at = ? WHERE id = ?");
                    $stmt->execute([$apiCheckIn, $newScheduledAt, $reminder['id']]);
                    logMessage("→ Rescheduled to $newScheduledAt");
                }
            }
        }
    } else {
        logMessage("Skipping check-in time verification: No scheduled reminders or no reservations fetched in Phase 1.");
    }
}

logMessage("=== CRON END ===\n");
