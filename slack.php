<?php
/**
 * slack.php — Slack message sender via Bot token.
 * 
 * Sends SOP reminder messages and failure alerts to Slack.
 */

require_once __DIR__ . '/config.php';

/**
 * Send a message to Slack via chat.postMessage API.
 * 
 * @param string $text  The message text (supports mrkdwn)
 * @return array ['ok' => bool, 'error' => string|null]
 */
function sendSlackMessage($text) {
    $token = env('SLACK_BOT_TOKEN');
    $channel = env('SLACK_CHANNEL_ID');

    if (empty($token) || empty($channel)) {
        logMessage("ERROR: SLACK_BOT_TOKEN or SLACK_CHANNEL_ID not set in .env");
        return ['ok' => false, 'error' => 'Missing Slack credentials in .env'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://slack.com/api/chat.postMessage',
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'channel' => $channel,
            'text' => $text,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]),
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logMessage("ERROR: Slack cURL error: $error");
        return ['ok' => false, 'error' => "cURL: $error"];
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['ok'])) {
        logMessage("ERROR: Invalid Slack response: $response");
        return ['ok' => false, 'error' => 'Invalid Slack response'];
    }

    if (!$data['ok']) {
        logMessage("ERROR: Slack API error: " . ($data['error'] ?? 'unknown'));
        return ['ok' => false, 'error' => $data['error'] ?? 'unknown'];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Shorten a URL using the is.gd API.
 * Falls back to the original URL if shortening fails.
 * 
 * @param string $url  The URL to shorten
 * @return string  Shortened URL, or original on failure
 */
function shortenUrl($url) {
    $apiUrl = 'https://is.gd/create.php?format=simple&url=' . urlencode($url);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200 || empty($response) || strpos($response, 'Error:') === 0) {
        logMessage("WARNING: is.gd shortening failed for $url — using original. Response: $response");
        return $url;
    }

    return trim($response);
}

/**
 * Build the SOP reminder message for Slack.
 * 
 * Format:
 *   guest_name ⌂ property_name
 *   check_in_date → check_out_date
 *   platform_id (Accepted)
 *   hospitable_thread_link
 *   submess-<conversation_id>
 *   ---
 *   <SOP message>
 *   <!here>
 * 
 * @param array  $reminder    Row from the reminders table
 * @param string $sopMessage  The SOP text from config
 * @return string Formatted Slack message
 */
function buildReminderMessage($reminder, $sopMessage) {
    $threadLink = 'https://my.hospitable.com/inbox/thread/' . $reminder['conversation_id'];
    $shortLink = shortenUrl($threadLink);

    $checkIn = date('D, M j, Y', strtotime($reminder['check_in']));
    $checkOut = date('D, M j, Y', strtotime($reminder['check_out']));

    $platformId = $reminder['platform_id'] ?? '';
    $conversationId = $reminder['conversation_id'] ?? '';

    $msg = $reminder['guest_name'] . " ⌂ " . $reminder['property_name'] . "\n";
    $msg .= $checkIn . " → " . $checkOut . "\n";
    $msg .= $platformId . " (Accepted)\n";
    $msg .= $shortLink . "\n";
    $msg .= "subsmess-" . $conversationId . "\n";
    $msg .= "---\n";
    $msg .= $sopMessage . "\n";
    $msg .= "Tracker: 09009b39-61c9-4903-89d0-9e414b780a53\n";
    $msg .= "<!here>";

    return $msg;
}

/**
 * Send a failure alert to Slack mentioning the configured user.
 * 
 * @param array  $reminder  Row from the reminders table
 * @param string $error     Error description
 */
function sendFailureAlert($reminder, $error) {
    $failureUserId = env('SLACK_FAILURE_USER_ID', 'U03S5GQ2CDP');

    $msg = ":warning: *SOP Reminder FAILED*\n";
    $msg .= ":house: Property: " . $reminder['property_name'] . "\n";
    $msg .= ":bust_in_silhouette: Guest: " . $reminder['guest_name'] . "\n";
    $msg .= ":calendar: Check-in: " . date('M j, Y g:i A', strtotime($reminder['check_in'])) . "\n";
    $msg .= ":x: Error: " . $error . "\n";
    $msg .= "<@" . $failureUserId . ">";

    // Best-effort: don't fail silently if alert itself fails
    $result = sendSlackMessage($msg);
    if (!$result['ok']) {
        logMessage("CRITICAL: Could not send failure alert to Slack: " . ($result['error'] ?? 'unknown'));
    }
}
