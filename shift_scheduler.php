<?php
/**
 * shift_scheduler.php — 2-Shift Random Time Calculator.
 * 
 * Implements the "Smart Bridge" scheduling logic:
 * 1. Calculate the target time (check-in minus X hours)
 * 2. Find which 4-hour shift the target falls into
 * 3. Create an 8-hour window (target shift + 1 adjacent shift)
 * 4. Pick a random shift from those 2, then a random minute within it
 * 5. Handle last-minute bookings (≤30 min before check-in → immediate)
 * 
 * All calculations are done in BDT (UTC+6).
 */

require_once __DIR__ . '/config.php';

// BDT timezone
define('TEAM_TIMEZONE', 'Asia/Dhaka');

/**
 * Get all shift boundaries for a given day, in Unix timestamps.
 * Shifts are 4-hour blocks starting at SHIFT_START_HOUR.
 * 
 * @param string $dateStr  Date string (Y-m-d) in BDT
 * @return array  Array of ['start' => timestamp, 'end' => timestamp] for each shift
 */
function getShiftsForDay($dateStr) {
    $shiftStart = (int) env('SHIFT_START_HOUR', 6);
    $shiftDuration = (int) env('SHIFT_DURATION_HOURS', 4);
    $shiftsPerDay = (int) (24 / $shiftDuration);

    $tz = new DateTimeZone(TEAM_TIMEZONE);
    $shifts = [];

    for ($i = 0; $i < $shiftsPerDay; $i++) {
        $hour = ($shiftStart + ($i * $shiftDuration)) % 24;

        $start = new DateTime($dateStr, $tz);
        $start->setTime($hour, 0, 0);

        // If the shift wraps past midnight (e.g., 22:00), the start might need to be on the previous day
        // But we handle this by generating shifts for the current AND next day
        $end = clone $start;
        $end->modify("+{$shiftDuration} hours");

        $shifts[] = [
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'label' => $start->format('H:i') . '-' . $end->format('H:i'),
        ];
    }

    return $shifts;
}

/**
 * Find the shift that contains a given timestamp.
 * Searches across 2 days (today and tomorrow) to handle edge cases.
 * 
 * @param int $timestamp  Unix timestamp
 * @return array|null  The shift ['start', 'end', 'label'] or null
 */
function findShiftForTimestamp($timestamp) {
    $tz = new DateTimeZone(TEAM_TIMEZONE);
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone($tz);

    // Check today and yesterday (to handle overnight shifts)
    $dates = [
        $dt->format('Y-m-d'),
        (clone $dt)->modify('-1 day')->format('Y-m-d'),
    ];

    foreach ($dates as $date) {
        $shifts = getShiftsForDay($date);
        foreach ($shifts as $shift) {
            if ($timestamp >= $shift['start'] && $timestamp < $shift['end']) {
                return $shift;
            }
        }
    }

    return null;
}

/**
 * Get adjacent shifts (before and after) a given shift.
 * 
 * @param array $targetShift  The target shift ['start', 'end']
 * @return array ['before' => shift|null, 'after' => shift|null]
 */
function getAdjacentShifts($targetShift) {
    $shiftDuration = (int) env('SHIFT_DURATION_HOURS', 4);
    $durationSec = $shiftDuration * 3600;

    // The shift before starts at (target_start - duration)
    $beforeStart = $targetShift['start'] - $durationSec;
    $before = [
        'start' => $beforeStart,
        'end' => $targetShift['start'],
        'label' => date('H:i', $beforeStart) . '-' . date('H:i', $targetShift['start']),
    ];

    // The shift after starts at target_end
    $afterEnd = $targetShift['end'] + $durationSec;
    $after = [
        'start' => $targetShift['end'],
        'end' => $afterEnd,
        'label' => date('H:i', $targetShift['end']) . '-' . date('H:i', $afterEnd),
    ];

    return ['before' => $before, 'after' => $after];
}

/**
 * Calculate a random send time for a reminder.
 * 
 * Algorithm:
 * 1. target = checkIn - reminderHoursBefore
 * 2. Find target's shift
 * 3. Get the adjacent shift (before or after)
 * 4. Pick a random shift from [target_shift, one_adjacent]
 * 5. Pick a random second within that shift
 * 6. If the result is ≤30 min before check-in or in the past → IMMEDIATE
 * 
 * @param string $checkInDatetime  Check-in datetime string (ISO 8601 / parseable)
 * @param int    $reminderHoursBefore  Hours before check-in to target
 * @return array ['timestamp' => int, 'immediate' => bool, 'debug' => string]
 */
function calculateRandomSendTime($checkInDatetime, $reminderHoursBefore = null) {
    if ($reminderHoursBefore === null) {
        $reminderHoursBefore = (int) env('REMINDER_HOURS_BEFORE', 12);
    }

    $tz = new DateTimeZone(TEAM_TIMEZONE);

    // Parse check-in time
    $checkIn = new DateTime($checkInDatetime);
    $checkIn->setTimezone($tz);
    $checkInTimestamp = $checkIn->getTimestamp();

    $now = time();

    // Step 1: Calculate target time
    $targetTimestamp = $checkInTimestamp - ($reminderHoursBefore * 3600);

    // Step 2: If target is already in the past, send immediately
    if ($targetTimestamp <= $now) {
        return [
            'timestamp' => $now,
            'immediate' => true,
            'debug' => "Target time is in the past. Sending immediately.",
        ];
    }

    // Step 3: Find which shift the target falls into
    $targetShift = findShiftForTimestamp($targetTimestamp);
    if (!$targetShift) {
        // Fallback: just use the target time directly
        return [
            'timestamp' => $targetTimestamp,
            'immediate' => false,
            'debug' => "Could not determine shift. Using exact target time.",
        ];
    }

    // Step 4: Get adjacent shifts
    $adjacent = getAdjacentShifts($targetShift);

    // Step 5: Pick randomly between target shift and one adjacent shift
    // Randomly choose before or after adjacent shift
    $adjacentShift = (mt_rand(0, 1) === 0) ? $adjacent['before'] : $adjacent['after'];

    // Pick randomly between target shift and the chosen adjacent shift
    $candidateShifts = [$targetShift, $adjacentShift];
    $chosenShift = $candidateShifts[mt_rand(0, 1)];

    // Step 6: Pick a random second within the chosen shift
    $randomTimestamp = mt_rand($chosenShift['start'], $chosenShift['end'] - 1);

    // Step 7: Safety checks
    // If the random time is in the past, adjust to now
    if ($randomTimestamp <= $now) {
        $randomTimestamp = $now + 60; // 1 minute from now
    }

    // If the random time is ≤30 min before check-in, send immediately
    $thirtyMinBefore = $checkInTimestamp - (30 * 60);
    if ($randomTimestamp >= $thirtyMinBefore) {
        return [
            'timestamp' => $now,
            'immediate' => true,
            'debug' => "Scheduled time ($randomTimestamp) is within 30 min of check-in. Sending immediately.",
        ];
    }

    $scheduledDt = new DateTime('@' . $randomTimestamp);
    $scheduledDt->setTimezone($tz);

    return [
        'timestamp' => $randomTimestamp,
        'immediate' => false,
        'debug' => sprintf(
            "CheckIn: %s | Target: %s | TargetShift: %s | ChosenShift: %s | Scheduled: %s",
            $checkIn->format('Y-m-d H:i'),
            date('Y-m-d H:i', $targetTimestamp),
            $targetShift['label'],
            $chosenShift['label'],
            $scheduledDt->format('Y-m-d H:i')
        ),
    ];
}
