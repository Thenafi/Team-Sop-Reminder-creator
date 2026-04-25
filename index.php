<?php
/**
 * index.php — Web UI for SOP Reminder Configuration.
 * 
 * Features:
 * - HTTP Basic Auth (credentials from .env)
 * - Tickable property list (fetched from Hospitable API)
 * - SOP message editor per property
 * - Reminder hours override per property
 * - Reminders dashboard (scheduled/sent/failed)
 * - All settings saved to config.json
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hospitable_api.php';

// ─── AJAX Handlers ───────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sync_properties') {
    requireAuth();
    header('Content-Type: application/json');
    try {
        $apiProperties = fetchProperties();
        $config = loadConfig();
        $platforms = [];
        foreach ($apiProperties as $prop) {
            $uuid = $prop['id'];
            $config['properties'][$uuid] = [
                'name' => $prop['name'] ?? $prop['public_name'] ?? 'Unnamed',
                'timezone' => $prop['timezone'] ?? '-0500'
            ];
            if (!empty($prop['listings'])) {
                foreach ($prop['listings'] as $l) {
                    if (!empty($l['platform'])) {
                        $p = trim($l['platform']);
                        $platforms[$p] = $p;
                    }
                }
            }
        }
        
        // Retain existing platforms plus newly discovered ones
        $existingPlatforms = $config['platforms'] ?? [];
        $mergedPlatforms = array_unique(array_merge($existingPlatforms, array_keys($platforms)));
        sort($mergedPlatforms);
        $config['platforms'] = $mergedPlatforms;
        
        saveConfig($config);
        echo json_encode(['success' => true, 'properties' => $config['properties'], 'platforms' => $config['platforms']]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── Auth ────────────────────────────────────────────────────
requireAuth();

// ─── Handle POST (Save config) ──────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $config = loadConfig();
        $postedSops = $_POST['sops'] ?? [];

        // Ensure sops array exists
        $config['sops'] = [];

        foreach ($postedSops as $index => $sopData) {
            // Generate ID if missing
            $sopId = !empty($sopData['id']) ? $sopData['id'] : 'sop_' . uniqid();
            
            $sendImmediately = !empty($sopData['send_immediately']);
            $remHours = (int) ($sopData['reminder_hours_before'] ?: env('REMINDER_HOURS_BEFORE', 12));
            $scanDays = (int) ($sopData['scan_days_ahead'] ?: 2);
            
            // Backend correction: Ensure scan_days_ahead is large enough to cover the reminder window
            if ($sendImmediately) {
                // Immediate mode: scan the full year to catch all new bookings
                $scanDays = 365;
            } else {
                $minDaysNeeded = (int) ceil($remHours / 24);
                if ($scanDays < $minDaysNeeded) {
                    $scanDays = $minDaysNeeded;
                }
            }

            // ── Conditions ──
            $leadTimeOp    = $sopData['lead_time_operator']       ?? 'any';
            $leadTimeVal   = (int) ($sopData['lead_time_value']   ?? 0);
            $nightsOp      = $sopData['nights_operator']          ?? 'any';
            $nightsVal     = (int) ($sopData['nights_value']      ?? 0);
            $daysToInOp    = $sopData['days_to_checkin_operator']  ?? 'any';
            $daysToInVal   = (int) ($sopData['days_to_checkin_value']  ?? 0);
            $daysToOutOp   = $sopData['days_to_checkout_operator'] ?? 'any';
            $daysToOutVal  = (int) ($sopData['days_to_checkout_value'] ?? 0);

            $config['sops'][] = [
                'id' => $sopId,
                'name' => trim($sopData['name'] ?? 'Unnamed SOP'),
                'sop_message' => trim($sopData['sop_message'] ?? ''),
                'send_immediately' => $sendImmediately,
                'reminder_hours_before' => $remHours,
                'scan_days_ahead' => $scanDays,
                'properties' => $sopData['properties'] ?? [], // Array of enabled property UUIDs for this SOP
                'platforms' => $sopData['platforms'] ?? [], // Array of enabled platforms for this SOP
                'platform_filter_mode' => $sopData['platform_filter_mode'] ?? 'include', // include or exclude
                // Conditional rules
                'lead_time_operator'       => $leadTimeOp,    // any | lt | lte | eq | gte | gt
                'lead_time_value'          => $leadTimeVal,   // days between booking_date and check_in
                'nights_operator'          => $nightsOp,      // any | lt | lte | eq | gte | gt
                'nights_value'             => $nightsVal,     // reservation nights count
                'days_to_checkin_operator' => $daysToInOp,   // any | lt | lte | eq | gte | gt
                'days_to_checkin_value'    => $daysToInVal,  // days from NOW to check_in
                'days_to_checkout_operator'=> $daysToOutOp,  // any | lt | lte | eq | gte | gt
                'days_to_checkout_value'   => $daysToOutVal, // days from NOW to check_out
            ];
        }

        if (saveConfig($config)) {
            $message = 'Configuration saved successfully.';
            $messageType = 'success';
        } else {
            $message = 'Error saving configuration.';
            $messageType = 'error';
        }
    }

    if ($action === 'run_db_setup') {
        try {
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
            $db->exec($sql);
            $message = 'Database table created successfully.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ─── Load data ───────────────────────────────────────────────
$config = loadConfig();

// Ensure structure exists
if (!isset($config['properties'])) $config['properties'] = [];
if (!isset($config['sops'])) $config['sops'] = [];
if (!isset($config['platforms'])) $config['platforms'] = ['airbnb', 'booking', 'vrbo', 'direct', 'manual'];

// Fetch reminders from DB and check if table exists
$reminders = [];
$dbExists = false;
try {
    $db = getDB();
    $stmt = $db->query("SELECT 1 FROM reminders LIMIT 1");
    if ($stmt !== false) {
        $dbExists = true;
        $stmt = $db->query("SELECT * FROM reminders ORDER BY scheduled_at DESC LIMIT 50");
        $reminders = $stmt->fetchAll();
    }
} catch (Exception $e) {
    // DB might not be set up yet
}

$defaultReminderHours = (int) env('REMINDER_HOURS_BEFORE', 12);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOP Reminder Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --bg: #0f1117;
            --surface: #1a1d27;
            --surface-hover: #22263a;
            --border: #2a2e3d;
            --border-light: #353a4d;
            --text: #e4e6ed;
            --text-muted: #8b8fa3;
            --text-dim: #5d6175;
            --primary: #6366f1;
            --primary-hover: #818cf8;
            --primary-subtle: rgba(99, 102, 241, 0.12);
            --accent-green: #22c55e;
            --accent-green-bg: rgba(34, 197, 94, 0.1);
            --accent-amber: #f59e0b;
            --accent-amber-bg: rgba(245, 158, 11, 0.1);
            --accent-red: #ef4444;
            --accent-red-bg: rgba(239, 68, 68, 0.08);
            --accent-blue: #3b82f6;
            --accent-blue-bg: rgba(59, 130, 246, 0.1);
            --radius: 8px;
            --radius-lg: 12px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            padding: 24px;
            max-width: 960px;
            margin: 0 auto;
        }

        /* ─── Header ──────────────────────────── */
        .page-header {
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }
        .page-header .subtitle {
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 400;
        }

        /* ─── Flash Messages ──────────────────── */
        .msg {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .msg.success {
            background: var(--accent-green-bg);
            color: var(--accent-green);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        .msg.error {
            background: var(--accent-red-bg);
            color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        /* ─── Loading Spinner ─────────────────── */
        #api-loading-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--accent-amber-bg);
            color: var(--accent-amber);
            border: 1px solid rgba(245, 158, 11, 0.15);
            padding: 10px 16px;
            border-radius: var(--radius);
            font-size: 0.82rem;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .spinner {
            width: 14px; height: 14px;
            border: 2px solid var(--accent-amber);
            border-bottom-color: transparent;
            border-radius: 50%;
            display: inline-block;
            animation: rotation 1s linear infinite;
        }
        @keyframes rotation {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ─── Info Box ────────────────────────── */
        .info-box {
            background: var(--accent-blue-bg);
            border: 1px solid rgba(59, 130, 246, 0.15);
            border-radius: var(--radius);
            padding: 12px 16px;
            font-size: 0.8rem;
            color: var(--accent-blue);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .info-box strong { color: #60a5fa; }

        /* ─── Section Headers ─────────────────── */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 28px 0 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .section-header h2 {
            font-size: 1.05rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--text);
        }
        .section-header .count-badge {
            background: var(--primary-subtle);
            color: var(--primary);
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }

        /* ─── SOP Cards ───────────────────────── */
        .sop-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 12px;
            overflow: hidden;
            transition: border-color 0.2s ease;
        }
        .sop-card:hover { border-color: var(--border-light); }

        .sop-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s ease;
        }
        .sop-card-header:hover { background: var(--surface-hover); }

        .sop-card-header .left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }
        .sop-card-header .chevron {
            color: var(--text-dim);
            font-size: 0.75rem;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }
        .sop-card.expanded .sop-card-header .chevron { transform: rotate(90deg); }

        .sop-card-header .sop-title {
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sop-card-header .sop-meta {
            color: var(--text-muted);
            font-size: 0.72rem;
            white-space: nowrap;
        }
        .sop-card-header .right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .sop-card-body {
            display: none;
            padding: 0 18px 18px;
        }
        .sop-card.expanded .sop-card-body { display: block; }

        /* ─── Field groups ────────────────────── */
        .field-group {
            margin-bottom: 16px;
        }
        .field-group-title {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-dim);
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        .field-row {
            margin-bottom: 14px;
        }
        .field-row:last-child { margin-bottom: 0; }

        label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-muted);
        }
        .field-hint {
            font-size: 0.7rem;
            color: var(--text-dim);
            margin-top: 3px;
            line-height: 1.4;
        }

        /* ─── Inputs ──────────────────────────── */
        input[type="text"],
        input[type="number"],
        select,
        textarea {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-family: inherit;
            font-size: 0.85rem;
            padding: 8px 12px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            outline: none;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-subtle);
        }
        input[type="text"] { width: 100%; }
        input[type="number"] { width: 100px; }
        textarea {
            width: 100%;
            min-height: 80px;
            resize: vertical;
            line-height: 1.5;
        }
        select {
            width: 100%;
            cursor: pointer;
            appearance: auto;
        }

        /* ─── Inline fields (side by side) ────── */
        .inline-fields {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .inline-fields .field-row {
            flex: 1;
            min-width: 140px;
        }

        /* ─── Toggle / Switch ─────────────────── */
        .toggle-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 14px;
        }
        .toggle-row .toggle-info { flex: 1; }
        .toggle-row .toggle-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
            display: block;
        }
        .toggle-row .toggle-sub {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--border-light);
            border-radius: 24px;
            transition: background 0.2s ease;
        }
        .switch .slider::before {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            left: 3px; bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s ease;
        }
        .switch input:checked + .slider { background: var(--accent-green); }
        .switch input:checked + .slider::before { transform: translateX(18px); }

        /* ─── Checkbox Lists ──────────────────── */
        .checkbox-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 4px;
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 10px;
            border-radius: var(--radius);
            max-height: 200px;
            overflow-y: auto;
            margin-top: 6px;
        }
        .checkbox-list::-webkit-scrollbar { width: 6px; }
        .checkbox-list::-webkit-scrollbar-track { background: transparent; }
        .checkbox-list::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }

        .checkbox-item {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 6px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.1s ease;
            color: var(--text);
        }
        .checkbox-item:hover { background: var(--surface-hover); }

        .checkbox-item input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
        }

        /* ─── Search + action bar ─────────────── */
        .search-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
        }
        .search-bar input[type="text"] {
            flex: 1;
            font-size: 0.78rem;
            padding: 6px 10px;
        }

        /* ─── Buttons ─────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border: none;
            border-radius: var(--radius);
            font-size: 0.82rem;
            cursor: pointer;
            font-weight: 600;
            font-family: inherit;
            transition: all 0.15s ease;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary {
            background: var(--surface);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover {
            background: var(--surface-hover);
            color: var(--text);
            border-color: var(--border-light);
        }
        .btn-sm { padding: 5px 12px; font-size: 0.75rem; }
        .btn-danger {
            background: transparent;
            color: var(--accent-red);
            border: 1px solid transparent;
            font-size: 0.75rem;
        }
        .btn-danger:hover {
            background: var(--accent-red-bg);
            border-color: rgba(239, 68, 68, 0.15);
        }

        /* ─── Immediate badge ─────────────────── */
        .badge-immediate {
            background: var(--accent-green-bg);
            color: var(--accent-green);
            border: 1px solid rgba(34, 197, 94, 0.2);
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ─── Timing fields collapse ──────────── */
        .timing-fields { transition: opacity 0.2s ease; }
        .timing-fields.disabled {
            opacity: 0.35;
            pointer-events: none;
        }

        /* ─── Condition rows ──────────────────── */
        .condition-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 10px;
        }
        .condition-row:last-child { margin-bottom: 0; }
        .condition-row .cond-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            min-width: 120px;
            flex-shrink: 0;
        }
        .condition-row select {
            flex: 1;
            width: auto;
            min-width: 170px;
            font-size: 0.8rem;
            padding: 6px 10px;
        }
        .condition-row input[type="number"] {
            width: 80px;
            font-size: 0.8rem;
            padding: 6px 10px;
        }
        .condition-value-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .condition-value-wrap .unit-label {
            font-size: 0.75rem;
            color: var(--text-dim);
            white-space: nowrap;
        }
        .cond-value-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .scan-hint {
            width: 100%;
            margin-top: 5px;
            padding: 0 2px;
            display: none;
            line-height: 1.4;
        }

        /* ─── Save bar ────────────────────────── */
        .save-bar {
            margin-top: 24px;
            padding: 16px 0;
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .save-bar .btn-primary { font-size: 1rem; padding: 12px 28px; }

        /* ─── Reminders Table ─────────────────── */
        .table-wrapper {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        th, td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: var(--bg);
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--surface-hover); }

        .status-scheduled { color: var(--accent-amber); font-weight: 600; }
        .status-sent { color: var(--accent-green); font-weight: 600; }
        .status-failed { color: var(--accent-red); font-weight: 600; }

        /* ─── Empty State ─────────────────────── */
        .empty-state {
            text-align: center;
            padding: 32px 20px;
            color: var(--text-dim);
            font-size: 0.85rem;
        }

        /* ─── DB Setup ────────────────────────── */
        .db-setup-banner {
            background: var(--accent-amber-bg);
            border: 1px solid rgba(245, 158, 11, 0.15);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .db-setup-banner span {
            color: var(--accent-amber);
            font-size: 0.82rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>🏠 SOP Reminder Manager</h1>
        <p class="subtitle">Hospitable → Slack Smart Bridge (Multi-SOP v2)</p>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>">
            <?= $messageType === 'success' ? '✅' : '❌' ?>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <strong>How it works:</strong> Create multiple SOPs below. Each SOP has its own message, reminder time, and assigned properties.
        The system handles scheduling guests for all their associated SOPs.
    </div>

    <div id="api-loading-banner">
        <span class="spinner"></span>
        Fetching latest properties from Hospitable API...
    </div>

    <!-- ═══ DB Setup Button ═══ -->
    <?php if (!$dbExists): ?>
    <div class="db-setup-banner">
        <span>⚠️ Database table not detected.</span>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="run_db_setup">
            <button type="submit" class="btn btn-secondary btn-sm"
                    onclick="return confirm('Create/verify database table?')">
                ⚙️ Setup Database
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ═══ SOP Builder ═══ -->
    <form method="POST" id="sop-form">
        <input type="hidden" name="action" value="save_config">
        
        <div class="section-header">
            <h2>Your SOPs <span class="count-badge"><?= count($config['sops']) ?></span></h2>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addSop()">+ Add SOP</button>
        </div>

        <div id="sop-container">
            <?php foreach ($config['sops'] as $index => $sop): ?>
                <?php
                    $isImmediate = !empty($sop['send_immediately']);
                    $propCount = count($sop['properties'] ?? []);
                ?>
                <div class="sop-card expanded" id="sop-block-<?= $index ?>">
                    <!-- Card Header -->
                    <div class="sop-card-header" onclick="toggleSopCard(this)">
                        <div class="left">
                            <span class="chevron">▶</span>
                            <span class="sop-title"><?= htmlspecialchars($sop['name'] ?? 'Unnamed SOP') ?></span>
                            <?php if ($isImmediate): ?>
                                <span class="badge-immediate">⚡ Instant</span>
                            <?php endif; ?>
                            <span class="sop-meta"><?= $propCount ?> properties</span>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-danger btn-sm" onclick="event.stopPropagation(); removeSop(this);">✕ Remove</button>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="sop-card-body">
                        <input type="hidden" name="sops[<?= $index ?>][id]" value="<?= htmlspecialchars($sop['id']) ?>">
                        
                        <!-- ── Basics ── -->
                        <div class="field-group">
                            <div class="field-group-title">Basics</div>
                            <div class="field-row">
                                <label>SOP Name</label>
                                <input type="text" name="sops[<?= $index ?>][name]" 
                                       value="<?= htmlspecialchars($sop['name'] ?? '') ?>" 
                                       placeholder="e.g. Welcome Basket Prep" required>
                            </div>

                            <div class="field-row">
                                <label>SOP Message (Sent to Slack)</label>
                                <textarea name="sops[<?= $index ?>][sop_message]"
                                          placeholder="Enter the SOP instructions..." required
                                ><?= htmlspecialchars($sop['sop_message'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- ── Timing ── -->
                        <div class="field-group">
                            <div class="field-group-title">Timing</div>
                            
                            <div class="toggle-row">
                                <div class="toggle-info">
                                    <span class="toggle-label">⚡ Send Immediately on Discovery</span>
                                    <span class="toggle-sub">Sends the reminder as soon as the cron discovers the booking (no waiting).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="sops[<?= $index ?>][send_immediately]" value="1"
                                           class="immediate-toggle" <?= $isImmediate ? 'checked' : '' ?>
                                           onchange="toggleTimingFields(this)">
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div class="timing-fields <?= $isImmediate ? 'disabled' : '' ?>">
                                <div class="inline-fields">
                                    <div class="field-row">
                                        <label>Reminder Hours Before Check-in</label>
                                        <input type="number"
                                               name="sops[<?= $index ?>][reminder_hours_before]"
                                               value="<?= htmlspecialchars($sop['reminder_hours_before'] ?? $defaultReminderHours) ?>"
                                               min="1" max="8760">
                                        <div class="field-hint">How many hours before check-in to aim for. Max: 8760 (1 year).</div>
                                    </div>

                                    <div class="field-row">
                                        <label>Scan Days Ahead</label>
                                        <input type="number"
                                               name="sops[<?= $index ?>][scan_days_ahead]"
                                               value="<?= htmlspecialchars($sop['scan_days_ahead'] ?? 2) ?>"
                                               min="1" max="365">
                                        <div class="field-hint">How far ahead to look for upcoming reservations.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Platform Filter ── -->
                        <div class="field-group">
                            <div class="field-group-title">Platform Filter</div>
                            <div class="field-row">
                                <label>Filter Mode</label>
                                <select name="sops[<?= $index ?>][platform_filter_mode]">
                                    <option value="include" <?= ($sop['platform_filter_mode'] ?? 'include') === 'include' ? 'selected' : '' ?>>Run ONLY for selected platforms (Include)</option>
                                    <option value="exclude" <?= ($sop['platform_filter_mode'] ?? '') === 'exclude' ? 'selected' : '' ?>>Run for ALL EXCEPT selected (Exclude)</option>
                                </select>
                            </div>
                            <div class="field-row">
                                <label>Platforms</label>
                                <div class="checkbox-list">
                                    <?php 
                                    $availablePlatforms = $config['platforms'] ?? [];
                                    $isAllChecked = empty($sop['platforms']) || in_array('all', $sop['platforms']);
                                    ?>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="sops[<?= $index ?>][platforms][]" value="all" <?= $isAllChecked ? 'checked' : '' ?>>
                                        <strong>All Platforms</strong>
                                    </label>
                                    
                                    <?php foreach ($availablePlatforms as $platKey): 
                                        $isChecked = in_array($platKey, $sop['platforms'] ?? []);
                                    ?>
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="sops[<?= $index ?>][platforms][]" value="<?= htmlspecialchars($platKey) ?>" <?= $isChecked ? 'checked' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($platKey)) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ── Property Assignment ── -->
                        <div class="field-group">
                            <div class="field-group-title">Property Assignment</div>
                            <div class="field-row">
                                <label>Assign to Properties</label>
                                <div class="search-bar">
                                    <input type="text" class="property-search" placeholder="Search properties...">
                                    <button type="button" class="btn btn-secondary btn-sm select-all-btn">Select All</button>
                                    <button type="button" class="btn btn-secondary btn-sm clear-properties-btn">Clear</button>
                                </div>
                                <div class="checkbox-list">
                                    <?php foreach ($config['properties'] as $uuid => $prop): ?>
                                        <?php $isChecked = in_array($uuid, $sop['properties'] ?? []); ?>
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="sops[<?= $index ?>][properties][]" value="<?= htmlspecialchars($uuid) ?>" <?= $isChecked ? 'checked' : '' ?>>
                                            <?= htmlspecialchars($prop['name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ── Conditions ── -->
                        <?php
                            $ltOp   = $sop['lead_time_operator']        ?? 'any';
                            $ltVal  = $sop['lead_time_value']           ?? 1;
                            $nOp    = $sop['nights_operator']           ?? 'any';
                            $nVal   = $sop['nights_value']              ?? 1;
                            $diOp   = $sop['days_to_checkin_operator']  ?? 'any';
                            $diVal  = $sop['days_to_checkin_value']     ?? 1;
                            $doOp   = $sop['days_to_checkout_operator'] ?? 'any';
                            $doVal  = $sop['days_to_checkout_value']    ?? 1;
                            $ltHidden = ($ltOp === 'any') ? 'style="display:none;"' : '';
                            $nHidden  = ($nOp  === 'any') ? 'style="display:none;"' : '';
                            $diHidden = ($diOp === 'any') ? 'style="display:none;"' : '';
                            $doHidden = ($doOp === 'any') ? 'style="display:none;"' : '';
                        ?>
                        <div class="field-group">
                            <div class="field-group-title">Conditions <span style="font-size:0.65rem;font-weight:400;color:var(--text-dim);margin-left:6px;">(SOP only fires when ALL conditions match)</span></div>

                            <!-- Lead-time condition -->
                            <div class="condition-row">
                                <span class="cond-label">📅 Booking Lead-time</span>
                                <select name="sops[<?= $index ?>][lead_time_operator]" class="cond-operator" data-target="lt-val-<?= $index ?>">
                                    <option value="any"  <?= $ltOp==='any'  ? 'selected':'' ?>>Any lead-time</option>
                                    <option value="lt"   <?= $ltOp==='lt'   ? 'selected':'' ?>>Less than N days (booking→check-in)</option>
                                    <option value="lte"  <?= $ltOp==='lte'  ? 'selected':'' ?>>≤ N days (booking→check-in)</option>
                                    <option value="eq"   <?= $ltOp==='eq'   ? 'selected':'' ?>>Exactly N days (booking→check-in)</option>
                                    <option value="gte"  <?= $ltOp==='gte'  ? 'selected':'' ?>>≥ N days (booking→check-in)</option>
                                    <option value="gt"   <?= $ltOp==='gt'   ? 'selected':'' ?>>More than N days (booking→check-in)</option>
                                </select>
                                <div class="cond-value-group" id="lt-val-<?= $index ?>" <?= $ltHidden ?>>
                                    <input type="number" name="sops[<?= $index ?>][lead_time_value]" value="<?= (int)$ltVal ?>" min="0" max="999">
                                    <span class="unit-label">days</span>
                                </div>
                            </div>

                            <!-- Nights condition -->
                            <div class="condition-row">
                                <span class="cond-label">🌙 Stay Length</span>
                                <select name="sops[<?= $index ?>][nights_operator]" class="cond-operator" data-target="n-val-<?= $index ?>">
                                    <option value="any"  <?= $nOp==='any'  ? 'selected':'' ?>>Any number of nights</option>
                                    <option value="lt"   <?= $nOp==='lt'   ? 'selected':'' ?>>Less than N nights</option>
                                    <option value="lte"  <?= $nOp==='lte'  ? 'selected':'' ?>>≤ N nights</option>
                                    <option value="eq"   <?= $nOp==='eq'   ? 'selected':'' ?>>Exactly N nights</option>
                                    <option value="gte"  <?= $nOp==='gte'  ? 'selected':'' ?>>≥ N nights</option>
                                    <option value="gt"   <?= $nOp==='gt'   ? 'selected':'' ?>>More than N nights</option>
                                </select>
                                <div class="cond-value-group" id="n-val-<?= $index ?>" <?= $nHidden ?>>
                                    <input type="number" name="sops[<?= $index ?>][nights_value]" value="<?= (int)$nVal ?>" min="1" max="999">
                                    <span class="unit-label">nights</span>
                                </div>
                            </div>

                            <!-- Days until check-in condition -->
                            <div class="condition-row">
                                <span class="cond-label">🚪 Days to Check-in</span>
                                <select name="sops[<?= $index ?>][days_to_checkin_operator]" class="cond-operator" data-target="di-val-<?= $index ?>">
                                    <option value="any"  <?= $diOp==='any'  ? 'selected':'' ?>>Any time until check-in</option>
                                    <option value="lt"   <?= $diOp==='lt'   ? 'selected':'' ?>>Less than N days until check-in</option>
                                    <option value="lte"  <?= $diOp==='lte'  ? 'selected':'' ?>>≤ N days until check-in</option>
                                    <option value="eq"   <?= $diOp==='eq'   ? 'selected':'' ?>>Exactly N days until check-in</option>
                                    <option value="gte"  <?= $diOp==='gte'  ? 'selected':'' ?>>≥ N days until check-in</option>
                                    <option value="gt"   <?= $diOp==='gt'   ? 'selected':'' ?>>More than N days until check-in</option>
                                </select>
                                <div class="cond-value-group" id="di-val-<?= $index ?>" <?= $diHidden ?>>
                                    <input type="number" name="sops[<?= $index ?>][days_to_checkin_value]" value="<?= (int)$diVal ?>" min="0" max="999">
                                    <span class="unit-label">days</span>
                                </div>
                            </div>

                            <!-- Days until check-out condition -->
                            <div class="condition-row">
                                <span class="cond-label">🏁 Days to Check-out</span>
                                <select name="sops[<?= $index ?>][days_to_checkout_operator]" class="cond-operator" data-target="do-val-<?= $index ?>">
                                    <option value="any"  <?= $doOp==='any'  ? 'selected':'' ?>>Any time until check-out</option>
                                    <option value="lt"   <?= $doOp==='lt'   ? 'selected':'' ?>>Less than N days until check-out</option>
                                    <option value="lte"  <?= $doOp==='lte'  ? 'selected':'' ?>>≤ N days until check-out</option>
                                    <option value="eq"   <?= $doOp==='eq'   ? 'selected':'' ?>>Exactly N days until check-out</option>
                                    <option value="gte"  <?= $doOp==='gte'  ? 'selected':'' ?>>≥ N days until check-out</option>
                                    <option value="gt"   <?= $doOp==='gt'   ? 'selected':'' ?>>More than N days until check-out</option>
                                </select>
                                <div class="cond-value-group" id="do-val-<?= $index ?>" <?= $doHidden ?>>
                                    <input type="number" name="sops[<?= $index ?>][days_to_checkout_value]" value="<?= (int)$doVal ?>" min="0" max="999">
                                    <span class="unit-label">days</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="save-bar">
            <button type="submit" id="save-all-btn" class="btn btn-primary">💾 Save All SOPs</button>
        </div>
    </form>

    <!-- ═══ Reminders Dashboard ═══ -->
    <div class="section-header" style="margin-top:36px;">
        <h2>Reminders <span class="count-badge"><?= count($reminders) ?></span></h2>
    </div>

    <?php if (empty($reminders)): ?>
        <div class="empty-state">
            <p>No reminders yet. Run the cron job or set up the database first.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Property</th>
                    <th>Guest</th>
                    <th>Check-in</th>
                    <th>Scheduled</th>
                    <th>Status</th>
                    <th>Sent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reminders as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['property_name']) ?></td>
                        <td><?= htmlspecialchars($r['guest_name']) ?></td>
                        <td><?= date('M j, g:i A', strtotime($r['check_in'])) ?></td>
                        <td><?= date('M j, g:i A', strtotime($r['scheduled_at'])) ?></td>
                        <td>
                            <span class="status-<?= htmlspecialchars($r['status']) ?>">
                                <?= strtoupper(htmlspecialchars($r['status'])) ?>
                            </span>
                            <?php if ($r['status'] === 'failed' && $r['error_message']): ?>
                                <br><small style="color:var(--text-dim);"><?= htmlspecialchars($r['error_message']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $r['sent_at'] ? date('M j, g:i A', strtotime($r['sent_at'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <script>
        let sopCounter = <?= count($config['sops']) ?>;
        
        // Pass PHP property & platform lists to JS for dynamic building
        const propertyList = <?= json_encode($config['properties']) ?>;
        const platformList = <?= json_encode($config['platforms']) ?>;
        const defaultHours = <?= $defaultReminderHours ?>;

        // ─── Toggle SOP card expand/collapse ──
        function toggleSopCard(header) {
            header.closest('.sop-card').classList.toggle('expanded');
        }

        // ─── Remove SOP ──
        function removeSop(btn) {
            if (confirm('Remove this SOP?')) {
                btn.closest('.sop-card').remove();
            }
        }

        // ─── Toggle timing fields based on "Send Immediately" ──
        function toggleTimingFields(checkbox) {
            const card = checkbox.closest('.sop-card');
            const timingFields = card.querySelector('.timing-fields');
            const badge = card.querySelector('.badge-immediate');
            
            if (checkbox.checked) {
                timingFields.classList.add('disabled');
                // Add badge if not exists
                if (!badge) {
                    const title = card.querySelector('.sop-title');
                    const span = document.createElement('span');
                    span.className = 'badge-immediate';
                    span.textContent = '⚡ Instant';
                    title.after(span);
                }
            } else {
                timingFields.classList.remove('disabled');
                if (badge) badge.remove();
            }
        }

        function esc(str) {
            return str.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        function generateCheckboxHtml(idx, selectedUuids = []) {
            let html = '';
            for (const [uuid, prop] of Object.entries(propertyList)) {
                const isChecked = selectedUuids.includes(uuid) ? 'checked' : '';
                html += `
                <label class="checkbox-item">
                    <input type="checkbox" name="sops[${idx}][properties][]" value="${uuid}" ${isChecked}>
                    ${esc(prop.name)}
                </label>`;
            }
            return html;
        }

        function generatePlatformCheckboxes(idx, selectedPlats = []) {
            let html = `
            <label class="checkbox-item">
                <input type="checkbox" name="sops[${idx}][platforms][]" value="all" ${selectedPlats.length === 0 || selectedPlats.includes('all') ? 'checked' : ''}>
                <strong>All Platforms</strong>
            </label>`;
            
            for (const key of platformList) {
                const isChecked = selectedPlats.includes(key) ? 'checked' : '';
                html += `
                <label class="checkbox-item">
                    <input type="checkbox" name="sops[${idx}][platforms][]" value="${key}" ${isChecked}>
                    ${key.charAt(0).toUpperCase() + key.slice(1)}
                </label>`;
            }
            return html;
        }

        function addSop() {
            const container = document.getElementById('sop-container');
            const idx = sopCounter++;
            const sopId = 'sop_' + Math.random().toString(36).substr(2, 9);

            const checkboxesHTML = generateCheckboxHtml(idx);
            const platformsHTML = generatePlatformCheckboxes(idx);

            const html = `
                <div class="sop-card expanded" id="sop-block-${idx}">
                    <div class="sop-card-header" onclick="toggleSopCard(this)">
                        <div class="left">
                            <span class="chevron">▶</span>
                            <span class="sop-title">New SOP</span>
                            <span class="sop-meta">0 properties</span>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-danger btn-sm" onclick="event.stopPropagation(); removeSop(this);">✕ Remove</button>
                        </div>
                    </div>

                    <div class="sop-card-body">
                        <input type="hidden" name="sops[${idx}][id]" value="${sopId}">
                        
                        <div class="field-group">
                            <div class="field-group-title">Basics</div>
                            <div class="field-row">
                                <label>SOP Name</label>
                                <input type="text" name="sops[${idx}][name]" placeholder="e.g. Welcome Basket Prep" required>
                            </div>
                            <div class="field-row">
                                <label>SOP Message (Sent to Slack)</label>
                                <textarea name="sops[${idx}][sop_message]" placeholder="Enter the SOP instructions..." required></textarea>
                            </div>
                        </div>

                        <div class="field-group">
                            <div class="field-group-title">Timing</div>
                            <div class="toggle-row">
                                <div class="toggle-info">
                                    <span class="toggle-label">⚡ Send Immediately on Discovery</span>
                                    <span class="toggle-sub">Sends the reminder as soon as the cron discovers the booking (no waiting).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="sops[${idx}][send_immediately]" value="1"
                                           class="immediate-toggle" onchange="toggleTimingFields(this)">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="timing-fields">
                                <div class="inline-fields">
                                    <div class="field-row">
                                        <label>Reminder Hours Before Check-in</label>
                                        <input type="number" name="sops[${idx}][reminder_hours_before]" value="${defaultHours}" min="1" max="8760">
                                        <div class="field-hint">How many hours before check-in to aim for. Max: 8760 (1 year).</div>
                                    </div>
                                    <div class="field-row">
                                        <label>Scan Days Ahead</label>
                                        <input type="number" name="sops[${idx}][scan_days_ahead]" value="2" min="1" max="365">
                                        <div class="field-hint">How far ahead to look for upcoming reservations.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field-group">
                            <div class="field-group-title">Platform Filter</div>
                            <div class="field-row">
                                <label>Filter Mode</label>
                                <select name="sops[${idx}][platform_filter_mode]">
                                    <option value="include">Run ONLY for selected platforms (Include)</option>
                                    <option value="exclude">Run for ALL EXCEPT selected (Exclude)</option>
                                </select>
                            </div>
                            <div class="field-row">
                                <label>Platforms</label>
                                <div class="checkbox-list">
                                    ${platformsHTML}
                                </div>
                            </div>
                        </div>

                        <div class="field-group">
                            <div class="field-group-title">Property Assignment</div>
                            <div class="field-row">
                                <label>Assign to Properties</label>
                                <div class="search-bar">
                                    <input type="text" class="property-search" placeholder="Search properties...">
                                    <button type="button" class="btn btn-secondary btn-sm select-all-btn">Select All</button>
                                    <button type="button" class="btn btn-secondary btn-sm clear-properties-btn">Clear</button>
                                </div>
                                <div class="checkbox-list">
                                    ${checkboxesHTML}
                                </div>
                            </div>
                        </div>

                        <div class="field-group">
                            <div class="field-group-title">Conditions <span style="font-size:0.65rem;font-weight:400;color:var(--text-dim);margin-left:6px;">(SOP only fires when ALL conditions match)</span></div>

                            <div class="condition-row">
                                <span class="cond-label">📅 Booking Lead-time</span>
                                <select name="sops[${idx}][lead_time_operator]" class="cond-operator" data-target="lt-val-${idx}">
                                    <option value="any">Any lead-time</option>
                                    <option value="lt">Less than N days (booking→check-in)</option>
                                    <option value="lte">≤ N days (booking→check-in)</option>
                                    <option value="eq">Exactly N days (booking→check-in)</option>
                                    <option value="gte">≥ N days (booking→check-in)</option>
                                    <option value="gt">More than N days (booking→check-in)</option>
                                </select>
                                <div class="cond-value-group" id="lt-val-${idx}" style="display:none;">
                                    <input type="number" name="sops[${idx}][lead_time_value]" value="1" min="0" max="999">
                                    <span class="unit-label">days</span>
                                </div>
                            </div>

                            <div class="condition-row">
                                <span class="cond-label">🌙 Stay Length</span>
                                <select name="sops[${idx}][nights_operator]" class="cond-operator" data-target="n-val-${idx}">
                                    <option value="any">Any number of nights</option>
                                    <option value="lt">Less than N nights</option>
                                    <option value="lte">≤ N nights</option>
                                    <option value="eq">Exactly N nights</option>
                                    <option value="gte">≥ N nights</option>
                                    <option value="gt">More than N nights</option>
                                </select>
                                <div class="cond-value-group" id="n-val-${idx}" style="display:none;">
                                    <input type="number" name="sops[${idx}][nights_value]" value="1" min="1" max="999">
                                    <span class="unit-label">nights</span>
                                </div>
                            </div>

                            <div class="condition-row">
                                <span class="cond-label">🚪 Days to Check-in</span>
                                <select name="sops[${idx}][days_to_checkin_operator]" class="cond-operator" data-target="di-val-${idx}">
                                    <option value="any">Any time until check-in</option>
                                    <option value="lt">Less than N days until check-in</option>
                                    <option value="lte">≤ N days until check-in</option>
                                    <option value="eq">Exactly N days until check-in</option>
                                    <option value="gte">≥ N days until check-in</option>
                                    <option value="gt">More than N days until check-in</option>
                                </select>
                                <div class="cond-value-group" id="di-val-${idx}" style="display:none;">
                                    <input type="number" name="sops[${idx}][days_to_checkin_value]" value="1" min="0" max="999">
                                    <span class="unit-label">days</span>
                                </div>
                            </div>

                            <div class="condition-row">
                                <span class="cond-label">🏁 Days to Check-out</span>
                                <select name="sops[${idx}][days_to_checkout_operator]" class="cond-operator" data-target="do-val-${idx}">
                                    <option value="any">Any time until check-out</option>
                                    <option value="lt">Less than N days until check-out</option>
                                    <option value="lte">≤ N days until check-out</option>
                                    <option value="eq">Exactly N days until check-out</option>
                                    <option value="gte">≥ N days until check-out</option>
                                    <option value="gt">More than N days until check-out</option>
                                </select>
                                <div class="cond-value-group" id="do-val-${idx}" style="display:none;">
                                    <input type="number" name="sops[${idx}][days_to_checkout_value]" value="1" min="0" max="999">
                                    <span class="unit-label">days</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', html);
        }

        // ─── Show/hide condition value input when operator changes ──
        $(document).on('change', '.cond-operator', function() {
            const op = $(this).val();
            const targetId = $(this).data('target');
            const $valueGroup = $('#' + targetId);
            if (op === 'any') {
                $valueGroup.hide();
            } else {
                $valueGroup.show();
            }
        });

        // Handle mutual exclusivity for "All Platforms" checkbox
        $(document).on('change', '.checkbox-item input[type="checkbox"]', function() {
            const $this = $(this);
            const val = $this.val();
            const $container = $this.closest('.checkbox-list');
            
            // Only apply this logic to the platforms checkbox list (which has an 'all' option)
            const $allCheckbox = $container.find('input[value="all"]');
            if ($allCheckbox.length === 0) return; // Must be the properties list, skip

            if (val === 'all') {
                if ($this.is(':checked')) {
                    // If "All Platforms" is checked, uncheck everything else
                    $container.find('input[type="checkbox"]').not($this).prop('checked', false);
                }
            } else {
                if ($this.is(':checked')) {
                    // If a specific platform is checked, uncheck "All Platforms"
                    $allCheckbox.prop('checked', false);
                } else {
                    // If we just unchecked the last specific platform, maybe auto-check "All"?
                    const anyChecked = $container.find('input[type="checkbox"]:not([value="all"]):checked').length > 0;
                    if (!anyChecked) {
                        $allCheckbox.prop('checked', true);
                    }
                }
            }
        });

        // Update card header title on name input change
        $(document).on('input', 'input[name*="[name]"]', function() {
            const card = $(this).closest('.sop-card');
            const title = card.find('.sop-title');
            title.text($(this).val() || 'New SOP');
        });

        $(document).ready(function() {
            // Background dynamic load of API properties
            $.get('?ajax=sync_properties', function(res) {
                if (res.success && res.properties) {
                    Object.assign(propertyList, res.properties);
                    
                    if (res.platforms) {
                        platformList.length = 0; // clear
                        platformList.push(...res.platforms);
                    }
                    
                    console.log("Properties & platforms synced with API dynamically.");
                    
                    // Re-render toolboxes for ALL existing SOP blocks on the page
                    $('#sop-container .sop-card').each(function() {
                        const block = $(this);
                        const blockId = block.attr('id'); // e.g. sop-block-0
                        const idx = blockId.split('-').pop();
                        
                        // Discover currently checked UUIDs to retain state
                        let selectedProps = [];
                        block.find('input[name="sops['+idx+'][properties][]"]:checked').each(function() {
                            selectedProps.push($(this).val());
                        });
                        
                        let selectedPlats = [];
                        block.find('input[name="sops['+idx+'][platforms][]"]:checked').each(function() {
                            selectedPlats.push($(this).val());
                        });

                        // Re-inject HTML — platforms list is first, properties list is second
                        const $checkboxLists = block.find('.checkbox-list');
                        if ($checkboxLists.length >= 2) {
                            const newPlatHtml = generatePlatformCheckboxes(idx, selectedPlats);
                            $checkboxLists.eq(0).html(newPlatHtml);
                            
                            const newPropHtml = generateCheckboxHtml(idx, selectedProps);
                            $checkboxLists.eq(1).html(newPropHtml);
                        }
                    });
                }
            }).always(function() {
                // Hide the loading banner regardless of success or failure
                $('#api-loading-banner').fadeOut();
            });

            // Property Search Support
            $(document).on('keyup', '.property-search', function() {
                var term = $(this).val().toLowerCase();
                $(this).closest('.field-row').find('.checkbox-item').each(function() {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(term) > -1);
                });
            });

            // Select All Checkboxes
            $(document).on('click', '.select-all-btn', function() {
                $(this).closest('.field-row').find('input[type="checkbox"]:visible').prop('checked', true);
            });

            // Clear All Checkboxes
            $(document).on('click', '.clear-properties-btn', function() {
                $(this).closest('.field-row').find('input[type="checkbox"]').prop('checked', false);
            });

            // "Saving..." Animation + comprehensive validation
            $('#sop-form').on('submit', function(e) {
                let hasError = false;
                $('.sop-card').each(function() {
                    const block = $(this);
                    const isImmediate = block.find('.immediate-toggle').is(':checked');
                    const name = block.find('input[name*="[name]"]').val() || 'Unnamed SOP';

                    // When Send Immediately is ON → scan = 365, skip timing-based checks
                    if (!isImmediate) {
                        const hours = parseInt(block.find('input[name*="[reminder_hours_before]"]').val()) || 0;
                        const days  = parseInt(block.find('input[name*="[scan_days_ahead]"]').val()) || 0;

                        // 1. Scan vs Reminder Hours
                        if (days * 24 < hours) {
                            alert(`⚠️ SOP "${name}":\nScan Days Ahead (${days}d = ${days*24}h) must be ≥ Reminder Hours Before (${hours}h).\n\nPlease increase Scan Days Ahead.`);
                            hasError = true;
                            return false;
                        }

                        // 2. Scan vs Days-to-Check-in condition
                        const diOp  = block.find('select[name*="[days_to_checkin_operator]"]').val();
                        const diVal = parseInt(block.find('input[name*="[days_to_checkin_value]"]').val()) || 0;
                        if (diOp !== 'any' && (diOp === 'eq' || diOp === 'gte' || diOp === 'gt')) {
                            // Condition requires check-in to be >= N days away, scan must cover N days
                            const minScan = diOp === 'gt' ? diVal + 1 : diVal;
                            if (days < minScan) {
                                alert(`⚠️ SOP "${name}" — Days to Check-in condition issue:\nYou set "${diOp} ${diVal} days" but Scan Days Ahead is only ${days}.\n\nThe scan window won't reach that far — this condition will never fire.\nIncrease Scan Days Ahead to at least ${minScan}.`);
                                hasError = true;
                                return false;
                            }
                        }

                        // 3. Scan vs Days-to-Check-out condition
                        const doOp  = block.find('select[name*="[days_to_checkout_operator]"]').val();
                        const doVal = parseInt(block.find('input[name*="[days_to_checkout_value]"]').val()) || 0;
                        if (doOp !== 'any' && (doOp === 'eq' || doOp === 'gte' || doOp === 'gt')) {
                            const minScan = doOp === 'gt' ? doVal + 1 : doVal;
                            if (days < minScan) {
                                alert(`⚠️ SOP "${name}" — Days to Check-out condition issue:\nYou set "${doOp} ${doVal} days" but Scan Days Ahead is only ${days}.\n\nThe scan window won't reach that far — this condition will never fire.\nIncrease Scan Days Ahead to at least ${minScan}.`);
                                hasError = true;
                                return false;
                            }
                        }
                    }
                    // Note: Send Immediately SOPs skip the above because scan is forced to 365 days.
                    // Lead-time and Nights conditions have no scan-window dependency — always valid.
                });

                if (hasError) {
                    e.preventDefault();
                    return false;
                }

                const btn = $(this).find('#save-all-btn');
                if (btn.length) {
                    btn.prop('disabled', true).text('⏳ Saving...');
                }
            });

            // Live inline scan-window warning for Days to Check-in / Check-out
            function checkScanWindowHint(block) {
                if (!block || !block.length) return;
                const isImmediate = block.find('.immediate-toggle').is(':checked');
                const days = parseInt(block.find('input[name*="[scan_days_ahead]"]').val()) || 0;

                [['days_to_checkin', '🚪'], ['days_to_checkout', '🏁']].forEach(([key, icon]) => {
                    const op  = block.find(`select[name*="[${key}_operator]"]`).val();
                    const val = parseInt(block.find(`input[name*="[${key}_value]"]`).val()) || 0;
                    let hintEl = block.find(`.scan-hint-${key}`);

                    // Create hint element if it doesn't exist yet
                    if (!hintEl.length) {
                        block.find(`select[name*="[${key}_operator]"]`).closest('.condition-row')
                            .append(`<div class="scan-hint scan-hint-${key}"></div>`);
                        hintEl = block.find(`.scan-hint-${key}`);
                    }

                    if (isImmediate) {
                        hintEl.html(`<span style="color:var(--accent-green);font-size:0.7rem;">⚡ Send Immediately → scan = 365d, no window issue.</span>`).show();
                        return;
                    }

                    if (op === 'any') { hintEl.hide(); return; }

                    const needsFarScan = (op === 'gte' || op === 'eq' || op === 'gt');
                    if (needsFarScan) {
                        const minScan = op === 'gt' ? val + 1 : val;
                        if (days < minScan) {
                            hintEl.html(`<span style="color:var(--accent-amber);font-size:0.7rem;">⚠️ Scan Days Ahead is ${days}d but condition needs ≥${minScan}d — will never fire.</span>`).show();
                        } else {
                            hintEl.html(`<span style="color:var(--accent-green);font-size:0.7rem;">✓ Scan Days Ahead (${days}d) covers this condition.</span>`).show();
                        }
                    } else {
                        // lt / lte — condition targets near-term check-ins, scan window is fine if >= 1
                        hintEl.html(`<span style="color:var(--text-dim);font-size:0.7rem;">ℹ️ This fires for check-ins within the scan window (${days}d) that meet the condition.</span>`).show();
                    }
                });
            }

            // Trigger hint check on relevant field changes
            $(document).on('change', 'select[name*="[days_to_checkin_operator]"], select[name*="[days_to_checkout_operator]"]', function() {
                checkScanWindowHint($(this).closest('.sop-card'));
            });
            $(document).on('input change', 'input[name*="[scan_days_ahead]"]', function() {
                checkScanWindowHint($(this).closest('.sop-card'));
            });
            $(document).on('change', '.immediate-toggle', function() {
                checkScanWindowHint($(this).closest('.sop-card'));
            });
            // Run on page load for all existing cards
            $('.sop-card').each(function() { checkScanWindowHint($(this)); });
        });
    </script>
</body>
</html>
