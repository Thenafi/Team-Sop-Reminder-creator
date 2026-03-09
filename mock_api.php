<?php
/**
 * mock_api.php — Mock Hospitable API for testing.
 * 
 * Returns hardcoded JSON matching the real API response shape.
 * Edit the test data below to simulate different scenarios.
 * 
 * Usage:
 *   Set API_MODE=mock in .env
 *   The system will route API calls here instead of Hospitable.
 * 
 * When accessed via HTTP: ?action=properties or ?action=reservations
 * When accessed via CLI: call mock_get_data('properties') or mock_get_data('reservations')
 */

/**
 * Get mock data for a given action.
 * Edit the arrays below to add/change test data.
 */
function mock_get_data($action) {
    // ─── EDIT YOUR TEST DATA HERE ───────────────────────────

    // Test properties — add/remove as needed
    $properties = [
        [
            'id' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Test Villa Seaside',
            'public_name' => 'Test Villa Seaside',
            'picture' => 'https://example.com/villa1.jpg',
            'timezone' => '+0600',
            'listed' => true,
            'currency' => 'USD',
            'checkin' => '15:00',
            'checkout' => '11:00',
            'summary' => 'A test villa by the sea',
            'description' => 'Beautiful test property',
            'address' => [
                'street' => '123 Test St',
                'city' => 'Dhaka',
                'postcode' => '1000',
                'country' => 'BD',
                'country_name' => 'Bangladesh',
                'display' => '123 Test St, Dhaka',
                'coordinates' => ['latitude' => '23.8103', 'longitude' => '90.4125'],
            ],
            'amenities' => ['wifi', 'pool'],
            'capacity' => ['max' => 8, 'bedrooms' => 3, 'beds' => 4, 'bathrooms' => 2],
            'room_details' => [],
            'house_rules' => ['pets_allowed' => false, 'smoking_allowed' => false],
            'tags' => [],
            'property_type' => 'villa',
            'room_type' => 'Entire Home',
            'calendar_restricted' => false,
        ],
        [
            'id' => '22222222-2222-2222-2222-222222222222',
            'name' => 'Test Mountain Cabin',
            'public_name' => 'Test Mountain Cabin',
            'picture' => 'https://example.com/cabin1.jpg',
            'timezone' => '+0600',
            'listed' => true,
            'currency' => 'USD',
            'checkin' => '14:00',
            'checkout' => '10:00',
            'summary' => 'A cozy test cabin',
            'description' => 'Mountain retreat test property',
            'address' => [
                'street' => '456 Mountain Rd',
                'city' => 'Sylhet',
                'postcode' => '3100',
                'country' => 'BD',
                'country_name' => 'Bangladesh',
                'display' => '456 Mountain Rd, Sylhet',
                'coordinates' => ['latitude' => '24.8949', 'longitude' => '91.8687'],
            ],
            'amenities' => ['wifi', 'fireplace'],
            'capacity' => ['max' => 4, 'bedrooms' => 2, 'beds' => 2, 'bathrooms' => 1],
            'room_details' => [],
            'house_rules' => ['pets_allowed' => true, 'smoking_allowed' => false],
            'tags' => [],
            'property_type' => 'cabin',
            'room_type' => 'Entire Home',
            'calendar_restricted' => false,
        ],
        [
            'id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Test City Apartment',
            'public_name' => 'Test City Apartment',
            'picture' => 'https://example.com/apt1.jpg',
            'timezone' => '+0600',
            'listed' => true,
            'currency' => 'USD',
            'checkin' => '16:00',
            'checkout' => '11:00',
            'summary' => 'A modern test apartment',
            'description' => 'City center test property',
            'address' => [
                'street' => '789 City Ave',
                'city' => 'Chittagong',
                'postcode' => '4000',
                'country' => 'BD',
                'country_name' => 'Bangladesh',
                'display' => '789 City Ave, Chittagong',
                'coordinates' => ['latitude' => '22.3569', 'longitude' => '91.7832'],
            ],
            'amenities' => ['wifi', 'gym'],
            'capacity' => ['max' => 2, 'bedrooms' => 1, 'beds' => 1, 'bathrooms' => 1],
            'room_details' => [],
            'house_rules' => ['pets_allowed' => false, 'smoking_allowed' => false],
            'tags' => [],
            'property_type' => 'apartment',
            'room_type' => 'Entire Home',
            'calendar_restricted' => false,
        ],
    ];

    // Test reservations — edit check_in/check_out to test different timing scenarios
    // Tip: set check-in close to NOW to test "last-minute booking" → immediate send
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $dayAfter = date('Y-m-d', strtotime('+2 days'));
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $nextWeekPlus3 = date('Y-m-d', strtotime('+10 days'));

    $reservations = [
        [
            'id' => 'aaaa-bbbb-cccc-dddd-000000000001',
            'code' => 'HM100001',
            'platform' => 'airbnb',
            'platform_id' => 'HMAX100001',
            'booking_date' => date('Y-m-d\TH:i:s\Z'),
            // Standard booking: Tomorrow 3pm
            'arrival_date' => $tomorrow . 'T15:00:00+06:00',
            'departure_date' => $dayAfter . 'T11:00:00+06:00',
            'check_in' => $tomorrow . 'T15:00:00+06:00',
            'check_out' => $dayAfter . 'T11:00:00+06:00',
            'nights' => 1,
            'reservation_status' => [
                'current' => ['category' => 'accepted', 'sub_category' => null],
                'history' => [],
            ],
            'conversation_id' => '11111111-abcd-1111-1234-111111111111',
            'last_message_at' => date('Y-m-d\TH:i:s\Z'),
            'guests' => ['total' => 2, 'adult_count' => 2, 'child_count' => 0, 'infant_count' => 0, 'pet_count' => 0],
            'guest' => ['first_name' => 'Alice', 'last_name' => 'Smith'],
            'properties' => [
                ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Test Villa Seaside'],
            ],
        ],
        [
            'id' => 'aaaa-bbbb-cccc-dddd-000000000002',
            'code' => 'HM100002',
            'platform' => 'booking',
            'platform_id' => 'BKG-100002',
            'booking_date' => date('Y-m-d\TH:i:s\Z'),
            // Next week booking
            'arrival_date' => $nextWeek . 'T14:00:00+06:00',
            'departure_date' => $nextWeekPlus3 . 'T10:00:00+06:00',
            'check_in' => $nextWeek . 'T14:00:00+06:00',
            'check_out' => $nextWeekPlus3 . 'T10:00:00+06:00',
            'nights' => 3,
            'reservation_status' => [
                'current' => ['category' => 'accepted', 'sub_category' => null],
                'history' => [],
            ],
            'conversation_id' => '22222222-abcd-2222-1234-222222222222',
            'last_message_at' => date('Y-m-d\TH:i:s\Z'),
            'guests' => ['total' => 4, 'adult_count' => 2, 'child_count' => 2, 'infant_count' => 0, 'pet_count' => 0],
            'guest' => ['first_name' => 'Bob', 'last_name' => 'Jones'],
            'properties' => [
                ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Test Mountain Cabin'],
            ],
        ],
        [
            'id' => 'aaaa-bbbb-cccc-dddd-000000000003',
            'code' => 'HM100003',
            'platform' => 'vrbo',
            'platform_id' => 'VRBO-100003',
            'booking_date' => date('Y-m-d\TH:i:s\Z'),
            // Imminent booking: Check-in is exactly 12 hours from now
            'arrival_date' => date('Y-m-d\TH:i:s+06:00', strtotime('+12 hours')),
            'departure_date' => date('Y-m-d\TH:i:s+06:00', strtotime('+2 days 12 hours')),
            'check_in' => date('Y-m-d\TH:i:s+06:00', strtotime('+12 hours')),
            'check_out' => date('Y-m-d\TH:i:s+06:00', strtotime('+2 days 12 hours')),
            'nights' => 2,
            'reservation_status' => [
                'current' => ['category' => 'accepted', 'sub_category' => null],
                'history' => [],
            ],
            'conversation_id' => '33333333-abcd-3333-1234-333333333333',
            'last_message_at' => date('Y-m-d\TH:i:s\Z'),
            'guests' => ['total' => 2, 'adult_count' => 2, 'child_count' => 0, 'infant_count' => 0, 'pet_count' => 0],
            'guest' => ['first_name' => 'Charlie', 'last_name' => 'Brown'],
            'properties' => [
                ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Test Villa Seaside'],
            ],
        ],
        [
            'id' => 'aaaa-bbbb-cccc-dddd-000000000004',
            'code' => 'HM100004',
            'platform' => 'direct',
            'platform_id' => 'DIR-100004',
            'booking_date' => date('Y-m-d\TH:i:s\Z'),
            // Last-minute booking: check-in is only 1 hour from now -> should trigger immediate send
            'arrival_date' => date('Y-m-d\TH:i:s+06:00', strtotime('+1 hour')),
            'departure_date' => date('Y-m-d\TH:i:s+06:00', strtotime('+1 day 1 hour')),
            'check_in' => date('Y-m-d\TH:i:s+06:00', strtotime('+1 hour')),
            'check_out' => date('Y-m-d\TH:i:s+06:00', strtotime('+1 day 1 hour')),
            'nights' => 1,
            'reservation_status' => [
                'current' => ['category' => 'accepted', 'sub_category' => null],
                'history' => [],
            ],
            'conversation_id' => '44444444-abcd-4444-1234-444444444444',
            'last_message_at' => date('Y-m-d\TH:i:s\Z'),
            'guests' => ['total' => 1, 'adult_count' => 1, 'child_count' => 0, 'infant_count' => 0, 'pet_count' => 0],
            'guest' => ['first_name' => 'David', 'last_name' => 'Urgent'],
            'properties' => [
                ['id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Test City Apartment'],
            ],
        ],
        [
            'id' => 'aaaa-bbbb-cccc-dddd-000000000005',
            'code' => 'HM100005',
            'platform' => 'airbnb',
            'platform_id' => 'HMAX100005',
            'booking_date' => date('Y-m-d\TH:i:s\Z'),
            // Check-in in 48 hours
            'arrival_date' => date('Y-m-d\TH:i:s+06:00', strtotime('+48 hours')),
            'departure_date' => date('Y-m-d\TH:i:s+06:00', strtotime('+5 days')),
            'check_in' => date('Y-m-d\TH:i:s+06:00', strtotime('+48 hours')),
            'check_out' => date('Y-m-d\TH:i:s+06:00', strtotime('+5 days')),
            'nights' => 3,
            'reservation_status' => [
                'current' => ['category' => 'accepted', 'sub_category' => null],
                'history' => [],
            ],
            'conversation_id' => '55555555-abcd-5555-1234-555555555555',
            'last_message_at' => date('Y-m-d\TH:i:s\Z'),
            'guests' => ['total' => 6, 'adult_count' => 4, 'child_count' => 2, 'infant_count' => 0, 'pet_count' => 0],
            'guest' => ['first_name' => 'Elena', 'last_name' => 'Future'],
            'properties' => [
                ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Test Mountain Cabin'],
            ],
        ]
    ];

    // ─── END OF TEST DATA ──────────────────────────────────

    if ($action === 'properties') {
        return [
            'data' => $properties,
            'links' => ['first' => '#', 'last' => '#', 'prev' => null, 'next' => null],
            'meta' => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'per_page' => 100, 'to' => count($properties), 'total' => count($properties)],
        ];
    }

    if ($action === 'reservations') {
        return [
            'data' => $reservations,
            'links' => ['first' => '#', 'last' => '#', 'prev' => null, 'next' => null],
            'meta' => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'per_page' => 100, 'to' => count($reservations), 'total' => count($reservations)],
        ];
    }

    return ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]];
}

// ─── HTTP handler (when accessed via browser/curl) ─────────
if (php_sapi_name() !== 'cli' || (isset($argv) && basename($argv[0]) === 'mock_api.php')) {
    $action = $_GET['action'] ?? '';
    header('Content-Type: application/json');
    echo json_encode(mock_get_data($action));
    exit;
}
