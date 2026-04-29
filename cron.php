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

// ─── Condition helper ──────────────────────────────────────────
/**
 * Evaluate a simple comparison condition.
 *
 * @param string $operator  One of: any | lt | lte | eq | gte | gt
 * @param int    $actual    The real measured value (nights, lead-time days, …)
 * @param int    $threshold The configured N value to compare against
 * @return bool  true = condition passes (SOP should fire)
 */
function evaluateCondition(string $operator, int $actual, int $threshold): bool
{
    switch ($operator) {
        case 'any': return true;
        case 'lt':  return $actual <  $threshold;
        case 'lte': return $actual <= $threshold;
        case 'eq':  return $actual === $threshold;
        case 'gte': return $actual >= $threshold;
        case 'gt':  return $actual >  $threshold;
        default:    return true; // unknown operator → don't block
    }
}

function getReservationAnchorRaw(array $reservation, string $anchor): ?string
{
    if ($anchor === 'check_out') {
        return $reservation['check_out'] ?? $reservation['departure_date'] ?? null;
    }

    return $reservation['check_in'] ?? $reservation['arrival_date'] ?? null;
}

function getReservationPropertyId(array $reservation): string
{
    if (!empty($reservation['properties'])) {
        return $reservation['properties'][0]['id'] ?? '';
    }

    return '';
}

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

// Build a unique master list of ALL properties referenced in ANY SOP, tracking the MAX scan days needed per anchor.
$propertyScanDays = [];
foreach ($sops as $sop) {
    // Immediate-mode SOPs need to scan far ahead to catch all new bookings
    $timing = getSopScheduleTiming($sop);
    $anchor = $timing['anchor'];
    $sopScanDays = !empty($sop['send_immediately']) ? 365 : (int) ($sop['scan_days_ahead'] ?? 2);
    if (!empty($sop['properties']) && is_array($sop['properties'])) {
        foreach ($sop['properties'] as $pid) {
            if (!isset($propertyScanDays[$pid])) {
                $propertyScanDays[$pid] = ['check_in' => 0, 'check_out' => 0];
            }
            $propertyScanDays[$pid][$anchor] = max($propertyScanDays[$pid][$anchor], $sopScanDays);
        }
    }
}
$activePropertyUuids = array_keys($propertyScanDays);
$allReservations = [];

if (empty($sops) || empty($activePropertyUuids)) {
    logMessage("No active SOPs or assigned properties found in config. Skipping Phase 1.");
} else {
    logMessage("Active SOPs: " . count($sops) . " | Unique Properties to Scan: " . count($activePropertyUuids));

    $allReservations = [];

    // Fetch accepted reservations for each unique active property and required anchor.
    foreach ($activePropertyUuids as $uuid) {
        $prop = $config['properties'][$uuid] ?? [];
        
        // Build timezone-aware start and end dates
        $tz = $prop['timezone'] ?? '-0500';
        try {
            $dtz = new DateTimeZone($tz);
        } catch (Exception $e) {
            $dtz = new DateTimeZone('UTC');
        }

        foreach (['check_in' => 'checkin', 'check_out' => 'checkout'] as $anchor => $dateQuery) {
            $scanDaysAhead = (int) ($propertyScanDays[$uuid][$anchor] ?? 0);
            if ($scanDaysAhead <= 0) {
                continue;
            }

            $startDt = new DateTime('yesterday', $dtz);
            $endDt = new DateTime("today + $scanDaysAhead days", $dtz);

            $startDate = $startDt->format('Y-m-d');
            $endDate = $endDt->format('Y-m-d');

            logMessage("Fetching reservations for property $uuid from $startDate to $endDate (TZ: $tz, date_query=$dateQuery)");
            $propReservations = fetchReservations($uuid, $startDate, $endDate, $dateQuery);
            
            // Tag with property info in case API omits it
            foreach ($propReservations as &$res) {
                if (empty($res['properties'])) {
                    $res['properties'] = [
                        ['id' => $uuid, 'name' => $prop['name'] ?? '']
                    ];
                }
                $dedupeKey = $res['id'] ?? null;
                if ($dedupeKey) {
                    $allReservations[$dedupeKey] = $res;
                } else {
                    $allReservations[] = $res;
                }
            }
            unset($res);
        }
    }
    $allReservations = array_values($allReservations);

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
        // Always take the first 10 chars (YYYY-MM-DD) — the API may return full ISO
        // datetime strings whose UTC representation crosses midnight and shifts the date.
        $checkInRaw  = $res['check_in']  ?? $res['arrival_date']   ?? null;
        $checkOutRaw = $res['check_out'] ?? $res['departure_date'] ?? null;
        $checkIn  = $checkInRaw  ? substr($checkInRaw,  0, 10) : null;
        $checkOut = $checkOutRaw ? substr($checkOutRaw, 0, 10) : null;
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

            // ── Conditional Filters ───────────────────────────────
            // 1. Lead-time filter: days between booking_date and check_in
            $leadTimeOp  = $sop['lead_time_operator'] ?? 'any';
            if ($leadTimeOp !== 'any') {
                $leadTimeVal     = (int) ($sop['lead_time_value'] ?? 0);
                $bookingDateRaw  = $res['booking_date'] ?? null;
                if ($bookingDateRaw && $checkIn) {
                    $bookingTs  = strtotime(substr($bookingDateRaw, 0, 10));
                    $checkInTs  = strtotime($checkIn);
                    $leadDays   = (int) round(($checkInTs - $bookingTs) / 86400);
                } else {
                    $leadDays = 0;
                }
                if (!evaluateCondition($leadTimeOp, $leadDays, $leadTimeVal)) {
                    $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] =
                        "Skipped (Lead-time {$leadDays}d does not satisfy {$leadTimeOp} {$leadTimeVal}d)";
                    logMessage("Reservation $reservationId ({$sop['name']}): SKIPPED — lead-time {$leadDays}d not {$leadTimeOp} {$leadTimeVal}d");
                    continue;
                }
            }

            // 2. Stay-length filter: number of nights in the reservation
            $nightsOp = $sop['nights_operator'] ?? 'any';
            if ($nightsOp !== 'any') {
                $nightsVal    = (int) ($sop['nights_value'] ?? 1);
                $actualNights = (int) ($res['nights'] ?? 0);
                if ($actualNights === 0 && $checkIn && $checkOut) {
                    // Fallback: calculate from dates if 'nights' field is missing
                    $actualNights = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
                }
                if (!evaluateCondition($nightsOp, $actualNights, $nightsVal)) {
                    $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] =
                        "Skipped (Nights {$actualNights} does not satisfy {$nightsOp} {$nightsVal})";
                    logMessage("Reservation $reservationId ({$sop['name']}): SKIPPED — {$actualNights} nights not {$nightsOp} {$nightsVal}");
                    continue;
                }
            }

            // 3. Days-until-check-in filter: days from TODAY (midnight) to check_in date
            $daysToInOp = $sop['days_to_checkin_operator'] ?? 'any';
            if ($daysToInOp !== 'any' && $checkIn) {
                $daysToInVal    = (int) ($sop['days_to_checkin_value'] ?? 0);
                $todayMidnight  = strtotime('today midnight');
                $checkInMidnight = strtotime($checkIn);
                $daysToCheckIn  = (int) floor(($checkInMidnight - $todayMidnight) / 86400);
                if (!evaluateCondition($daysToInOp, $daysToCheckIn, $daysToInVal)) {
                    $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] =
                        "Skipped (Days to check-in {$daysToCheckIn}d does not satisfy {$daysToInOp} {$daysToInVal}d)";
                    logMessage("Reservation $reservationId ({$sop['name']}): SKIPPED — {$daysToCheckIn}d to check-in not {$daysToInOp} {$daysToInVal}d");
                    continue;
                }
            }

            // 4. Days-until-check-out filter: days from TODAY (midnight) to check_out date
            $daysToOutOp = $sop['days_to_checkout_operator'] ?? 'any';
            if ($daysToOutOp !== 'any' && $checkOut) {
                $daysToOutVal    = (int) ($sop['days_to_checkout_value'] ?? 0);
                $todayMidnight   = $todayMidnight ?? strtotime('today midnight');
                $checkOutMidnight = strtotime($checkOut);
                $daysToCheckOut  = (int) floor(($checkOutMidnight - $todayMidnight) / 86400);
                if (!evaluateCondition($daysToOutOp, $daysToCheckOut, $daysToOutVal)) {
                    $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] =
                        "Skipped (Days to check-out {$daysToCheckOut}d does not satisfy {$daysToOutOp} {$daysToOutVal}d)";
                    logMessage("Reservation $reservationId ({$sop['name']}): SKIPPED — {$daysToCheckOut}d to check-out not {$daysToOutOp} {$daysToOutVal}d");
                    continue;
                }
            }
            // ── End Conditional Filters ───────────────────────────

            $sendImmediately = !empty($sop['send_immediately']);
            $timing = getSopScheduleTiming($sop);
            $anchorRaw = getReservationAnchorRaw($res, $timing['anchor']);
            if (!$anchorRaw) {
                $reservationSyncLog[$reservationId]['sops'][$sop['name'] ?? $sopId] = "Skipped (Missing {$timing['anchor']} datetime)";
                continue;
            }
            $sopMessage = $sop['sop_message'] ?? 'No SOP message defined.';

            // Calculate send time — immediate mode bypasses the shift scheduler entirely
            if ($sendImmediately) {
                $schedule = [
                    'timestamp' => time(),
                    'immediate' => true,
                    'debug' => 'Send Immediately mode enabled. Scheduling for now.',
                ];
            } else {
                $propertyTimezone = $config['properties'][$propertyId]['timezone'] ?? null;
                $schedule = calculateReminderSendTime($anchorRaw, $timing, $propertyTimezone);
            }
            logMessage("Reservation $reservationId ($sopId): " . $schedule['debug']);

            // Convert scheduled timestamp to MySQL datetime
            $scheduledAt = date('Y-m-d H:i:s', $schedule['timestamp']);
            // $checkIn/$checkOut are already plain YYYY-MM-DD strings (sliced above).
            // Store them directly — no strtotime needed, no timezone shift risk.
            $propertyTimezone = $config['properties'][$propertyId]['timezone'] ?? null;
            $checkInFormatted  = formatReservationDateTimeForStorage($checkInRaw, $propertyTimezone) ?? $checkIn;
            $checkOutFormatted = formatReservationDateTimeForStorage($checkOutRaw, $propertyTimezone) ?? $checkOut;

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
// PHASE 2: Check for modified anchor times (edge case)
// ═══════════════════════════════════════════════════════════════
if (env('API_MODE', 'live') === 'live') {
    logMessage("Phase 2: Checking for modified anchor times...");

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
                // Fetch scheduled reservations that were outside the current discovery window so unsent reminders can be rescheduled.
                logMessage("Reservation $resId was NOT in scan window. Fetching specifically for schedule verification...");
                $apiRes = fetchReservation($resId);
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

                // Slice to YYYY-MM-DD — same reason as Phase 1; avoid UTC midnight shift.
                $sopId = $reminder['sop_id'];
                $matchedSop = null;
                foreach ($sops as $sop) {
                    if (($sop['id'] ?? '') === $sopId) {
                        $matchedSop = $sop;
                        break;
                    }
                }

                if (!$matchedSop) {
                    continue;
                }

                $propertyId = $reminder['property_id'] ?? getReservationPropertyId($apiRes);
                $propertyTimezone = $config['properties'][$propertyId]['timezone'] ?? null;
                $timing = getSopScheduleTiming($matchedSop);

                $apiCheckInRaw = $apiRes['check_in'] ?? $apiRes['arrival_date'] ?? '';
                $apiCheckOutRaw = $apiRes['check_out'] ?? $apiRes['departure_date'] ?? '';
                $apiCheckIn = formatReservationDateTimeForStorage($apiCheckInRaw, $propertyTimezone) ?? substr($apiCheckInRaw, 0, 10);
                $apiCheckOut = formatReservationDateTimeForStorage($apiCheckOutRaw, $propertyTimezone) ?? substr($apiCheckOutRaw, 0, 10);
                $dbCheckIn = $reminder['check_in'];
                $dbCheckOut = $reminder['check_out'];

                $anchorChanged = $timing['anchor'] === 'check_out'
                    ? $apiCheckOut !== $dbCheckOut
                    : $apiCheckIn !== $dbCheckIn;

                if ($anchorChanged) {
                    $oldAnchor = $timing['anchor'] === 'check_out' ? $dbCheckOut : $dbCheckIn;
                    $newAnchor = $timing['anchor'] === 'check_out' ? $apiCheckOut : $apiCheckIn;
                    logMessage("{$timing['anchor']} changed for reservation $resId: DB=$oldAnchor API=$newAnchor. Recalculating...");

                    $anchorRaw = getReservationAnchorRaw($apiRes, $timing['anchor']);

                    $schedule = calculateReminderSendTime($anchorRaw, $timing, $propertyTimezone);
                    $newScheduledAt = date('Y-m-d H:i:s', $schedule['timestamp']);

                    $stmt = $db->prepare("UPDATE reminders SET check_in = ?, check_out = ?, scheduled_at = ? WHERE id = ?");
                    $stmt->execute([$apiCheckIn, $apiCheckOut, $newScheduledAt, $reminder['id']]);
                    logMessage("Rescheduled to $newScheduledAt");
                }
            }
        }
    } else {
        logMessage("Skipping anchor time verification: No scheduled reminders.");
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
