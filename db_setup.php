<?php
/**
 * db_setup.php — Run once to create the MySQL tables.
 * 
 * Usage: php db_setup.php
 */

require_once __DIR__ . '/config.php';

$db = getDB();

$sql = "
CREATE TABLE IF NOT EXISTS reminders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id  VARCHAR(255) NOT NULL,
    platform_id     VARCHAR(255) DEFAULT '',
    property_id     VARCHAR(255) NOT NULL,
    property_name   VARCHAR(255) DEFAULT '',
    guest_name      VARCHAR(255) DEFAULT '',
    check_in        DATETIME NOT NULL,
    check_out       DATETIME NOT NULL,
    conversation_id VARCHAR(255) DEFAULT '',
    sop_id          VARCHAR(255) NOT NULL,
    sop_message     TEXT NOT NULL,
    scheduled_at    DATETIME NOT NULL,
    sent_at         DATETIME DEFAULT NULL,
    status          ENUM('scheduled','sent','failed') DEFAULT 'scheduled',
    error_message   TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reservation_sop (reservation_id, sop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $db->exec($sql);
    echo "✅ Table 'reminders' created successfully.\n";
} catch (PDOException $e) {
    echo "❌ Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Database setup complete.\n";
