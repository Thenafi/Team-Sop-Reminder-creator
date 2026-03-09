<?php
// Set default application timezone to match the team's local time
date_default_timezone_set('Asia/Dhaka');

/**
 * config.php — Environment loader, DB connection, and helpers.
 * 
 * This file is included by all other scripts. It:
 * 1. Loads .env variables
 * 2. Creates a shared PDO MySQL connection
 * 3. Provides config.json read/write helpers
 * 4. Provides HTTP Basic Auth check
 */

// ─── Load .env ───────────────────────────────────────────────
function loadEnv($path) {
    if (!file_exists($path)) {
        die(".env file not found at: $path\nCopy .env.example to .env and fill in your values.");
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

$basePath = __DIR__;
loadEnv($basePath . '/.env');

// ─── Helper: get env with default ────────────────────────────
function env($key, $default = '') {
    return isset($_ENV[$key]) && $_ENV[$key] !== '' ? $_ENV[$key] : $default;
}

// ─── Database Connection ─────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $host = env('DB_HOST', 'localhost');
        $name = env('DB_NAME', 'sop_reminders');
        $user = env('DB_USER');
        $pass = env('DB_PASS');
        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$name;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// ─── Config.json Read/Write (with file locking) ──────────────
function loadConfig() {
    global $basePath;
    $file = $basePath . '/config.json';
    if (!file_exists($file)) {
        return ['properties' => [], 'sops' => []];
    }
    $fp = fopen($file, 'r');
    if (!$fp) return ['properties' => [], 'sops' => []];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return $data ?: ['properties' => [], 'sops' => []];
}

function saveConfig($data) {
    global $basePath;
    $file = $basePath . '/config.json';
    $fp = fopen($file, 'w');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $result = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result !== false;
}

// ─── HTTP Basic Auth ─────────────────────────────────────────
function requireAuth() {
    $user = env('AUTH_USER', 'admin');
    $pass = env('AUTH_PASS', 'changeme');

    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($providedUser !== $user || $providedPass !== $pass) {
        header('WWW-Authenticate: Basic realm="SOP Reminder Admin"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Access denied.';
        exit;
    }
}

// ─── Logging ─────────────────────────────────────────────────
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
}
