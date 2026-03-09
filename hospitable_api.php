<?php
/**
 * hospitable_api.php — Hospitable v2 API client.
 * 
 * Provides functions to fetch properties and reservations.
 * When API_MODE=mock, routes requests to mock_api.php instead.
 */

require_once __DIR__ . '/config.php';

define('HOSPITABLE_BASE_URL', 'https://public.api.hospitable.com/v2');

/**
 * Make an authenticated GET request to the Hospitable API (or mock).
 */
function hospitable_request($endpoint, $params = []) {
    $apiMode = env('API_MODE', 'live');

    if ($apiMode === 'mock') {
        return mock_request($endpoint, $params);
    }

    $token = env('HOSPITABLE_API_TOKEN');
    if (empty($token)) {
        logMessage("ERROR: HOSPITABLE_API_TOKEN not set in .env");
        return null;
    }

    $url = HOSPITABLE_BASE_URL . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logMessage("ERROR: cURL error: $error");
        return null;
    }
    if ($httpCode !== 200) {
        logMessage("ERROR: Hospitable API returned HTTP $httpCode for $endpoint");
        logMessage("Response: $response");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Route request to the local mock API.
 */
function mock_request($endpoint, $params = []) {
    $action = '';
    if (strpos($endpoint, '/properties') !== false) {
        $action = 'properties';
    } elseif (strpos($endpoint, '/reservations') !== false) {
        $action = 'reservations';
    }

    $mockUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/mock_api.php?action=' . $action;

    // For CLI usage, just include the mock file directly
    if (php_sapi_name() === 'cli') {
        return mock_get_data($action);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $mockUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Fetch all properties from Hospitable API. Auto-paginates.
 * Returns array of property objects.
 */
function fetchProperties() {
    $allProperties = [];
    $page = 1;

    do {
        $result = hospitable_request('/properties', [
            'per_page' => 100,
            'page' => $page,
        ]);

        if (!$result || !isset($result['data'])) {
            logMessage("WARNING: Could not fetch properties page $page");
            break;
        }

        $allProperties = array_merge($allProperties, $result['data']);

        $lastPage = $result['meta']['last_page'] ?? 1;
        $page++;
    } while ($page <= $lastPage);

    logMessage("Fetched " . count($allProperties) . " properties from API");
    return $allProperties;
}

/**
 * Fetch accepted reservations for a given property UUID.
 * 
 * @param string $propertyUuid Property UUID string
 * @param string $startDate YYYY-MM-DD
 * @param string $endDate YYYY-MM-DD
 * @return array Array of reservation objects
 */
function fetchReservations($propertyUuid, $startDate, $endDate) {
    if (empty($propertyUuid)) {
        logMessage("No property UUID provided for fetchReservations");
        return [];
    }

    $allReservations = [];
    $page = 1;

    do {
        // Build query params manually for array params
        $queryParts = [];
        $queryParts[] = 'properties[]=' . urlencode($propertyUuid);
        $queryParts[] = 'status[]=accepted';
        $queryParts[] = 'include=guest,properties';
        $queryParts[] = 'start_date=' . urlencode($startDate);
        $queryParts[] = 'end_date=' . urlencode($endDate);
        $queryParts[] = 'per_page=100';
        $queryParts[] = 'page=' . $page;

        $queryString = implode('&', $queryParts);

        // Use direct URL construction for array params
        $apiMode = env('API_MODE', 'live');
        if ($apiMode === 'mock') {
            $result = mock_request('/reservations', []);
        } else {
            $token = env('HOSPITABLE_API_TOKEN');
            $url = HOSPITABLE_BASE_URL . '/reservations?' . $queryString;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                logMessage("ERROR: cURL error fetching reservations: $error");
                break;
            }
            if ($httpCode !== 200) {
                logMessage("ERROR: Reservations API returned HTTP $httpCode");
                logMessage("Response: $response");
                break;
            }
            $result = json_decode($response, true);
        }

        if (!$result || !isset($result['data'])) {
            logMessage("WARNING: Could not fetch reservations page $page");
            break;
        }

        $allReservations = array_merge($allReservations, $result['data']);

        $lastPage = $result['meta']['last_page'] ?? 1;
        $page++;
    } while ($page <= $lastPage);

    logMessage("Fetched " . count($allReservations) . " accepted reservations");
    return $allReservations;
}

/**
 * Re-check a single reservation's status from the API.
 * Used before sending to verify it's still accepted.
 * 
 * @param string $reservationId
 * @param string $propertyUuid
 * @return string|null The current status category, or null on failure
 */
function checkReservationStatus($reservationId, $propertyUuid) {
    $result = hospitable_request('/reservations', [
        'properties[]' => $propertyUuid,
        'per_page' => 1,
    ]);

    if (!$result || !isset($result['data'])) {
        return null;
    }

    // Search for our specific reservation
    foreach ($result['data'] as $res) {
        if ($res['id'] === $reservationId) {
            return $res['reservation_status']['current']['category'] ?? null;
        }
    }

    return null;
}
