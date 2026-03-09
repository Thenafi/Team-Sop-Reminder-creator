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
        if (!isset($config['properties'])) $config['properties'] = [];
        foreach ($apiProperties as $prop) {
            $uuid = $prop['id'];
            $config['properties'][$uuid] = [
                'name' => $prop['name'] ?? $prop['public_name'] ?? 'Unnamed',
                'timezone' => $prop['timezone'] ?? '-0500'
            ];
        }
        saveConfig($config);
        echo json_encode(['success' => true, 'properties' => $config['properties']]);
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
            
            $config['sops'][] = [
                'id' => $sopId,
                'name' => trim($sopData['name'] ?? 'Unnamed SOP'),
                'sop_message' => trim($sopData['sop_message'] ?? ''),
                'reminder_hours_before' => (int) ($sopData['reminder_hours_before'] ?: env('REMINDER_HOURS_BEFORE', 12)),
                'scan_days_ahead' => (int) ($sopData['scan_days_ahead'] ?: 2),
                'properties' => $sopData['properties'] ?? [], // Array of enabled property UUIDs for this SOP
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.5;
            padding: 20px;
            max-width: 960px;
            margin: 0 auto;
        }
        h1 { font-size: 1.5rem; margin-bottom: 5px; }
        .subtitle { color: #666; font-size: 0.85rem; margin-bottom: 20px; }
        h2 { font-size: 1.15rem; margin: 25px 0 10px; border-bottom: 2px solid #ddd; padding-bottom: 5px; }

        /* Messages */
        .msg { padding: 10px 14px; border-radius: 4px; margin-bottom: 15px; font-size: 0.9rem; }
        .msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Loading Spinner */
        #api-loading-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 0.85rem;
            margin-bottom: 15px;
            font-weight: 500;
        }
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid #856404;
            border-bottom-color: transparent;
            border-radius: 50%;
            display: inline-block;
            box-sizing: border-box;
            animation: rotation 1s linear infinite;
        }
        @keyframes rotation {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Property cards */
        .property-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 12px 15px;
            margin-bottom: 8px;
        }
        .property-header {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        .property-header input[type="checkbox"] { transform: scale(1.2); }
        .property-name { font-weight: 600; font-size: 0.95rem; }
        .property-uuid { font-size: 0.7rem; color: #999; font-family: monospace; }
        .property-details {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        .property-card.expanded .property-details { display: block; }

        /* Form elements */
        label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 3px; color: #555; }
        textarea {
            width: 100%;
            min-height: 60px;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            font-family: inherit;
            font-size: 0.85rem;
            resize: vertical;
            margin-bottom: 8px;
        }
        input[type="number"] {
            width: 80px;
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            font-size: 0.85rem;
        }
        .btn {
            display: inline-block;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-sm { padding: 5px 12px; font-size: 0.8rem; }

        /* Reminders table */
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin-top: 10px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; }
        .status-scheduled { color: #d97706; font-weight: 600; }
        .status-sent { color: #059669; font-weight: 600; }
        .status-failed { color: #dc2626; font-weight: 600; }

        /* Toggle expand */
        .expand-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            color: #2563eb;
            margin-left: auto;
        }

        .actions { margin-top: 15px; display: flex; gap: 10px; }
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 0.8rem;
            color: #1e40af;
            margin-bottom: 15px;
        }
        .field-row { margin-bottom: 12px; }
        .property-checkbox-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 8px;
            background: #fafafa;
            border: 1px solid #eee;
            padding: 10px;
            border-radius: 4px;
            max-height: 200px;
            overflow-y: auto;
            margin-top: 5px;
        }
        .property-checkbox-item {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .remove-sop-btn {
            color: #dc2626;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: bold;
            float: right;
            margin-top: -5px;
        }
    </style>
</head>
<body>
    <h1>🏠 SOP Reminder Manager</h1>
    <p class="subtitle">Hospitable → Slack Smart Bridge (Multi-SOP v2)</p>

    <?php if ($message): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>">
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
    <form method="POST" style="margin-bottom: 15px;">
        <input type="hidden" name="action" value="run_db_setup">
        <button type="submit" class="btn btn-secondary btn-sm"
                onclick="return confirm('Create/verify database table?')">
            ⚙️ Setup Database
        </button>
    </form>
    <?php endif; ?>

    <!-- ═══ SOP Builder ═══ -->
    <form method="POST">
        <input type="hidden" name="action" value="save_config">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h2>Your SOPs (<?= count($config['sops']) ?>)</h2>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addSop()">+ Add New SOP</button>
        </div>

        <div id="sop-container">
            <?php foreach ($config['sops'] as $index => $sop): ?>
                <div class="property-card" id="sop-block-<?= $index ?>">
                    <button type="button" class="remove-sop-btn" onclick="this.parentElement.remove()">X Remove SOP</button>
                    
                    <input type="hidden" name="sops[<?= $index ?>][id]" value="<?= htmlspecialchars($sop['id']) ?>">
                    
                    <div class="field-row">
                        <label>SOP Name</label>
                        <input type="text" name="sops[<?= $index ?>][name]" 
                               value="<?= htmlspecialchars($sop['name'] ?? '') ?>" 
                               placeholder="e.g. Welcome Basket Prep" style="width: 100%; padding: 6px;" required>
                    </div>

                    <div class="field-row">
                        <label>SOP Message (Sent to Slack)</label>
                        <textarea name="sops[<?= $index ?>][sop_message]"
                                  placeholder="Enter the SOP instructions..." required
                        ><?= htmlspecialchars($sop['sop_message'] ?? '') ?></textarea>
                    </div>

                    <div class="field-row">
                        <label>Reminder Hours Before Check-in</label>
                        <input type="number"
                               name="sops[<?= $index ?>][reminder_hours_before]"
                               value="<?= htmlspecialchars($sop['reminder_hours_before'] ?? $defaultReminderHours) ?>"
                               min="1" max="168">
                    </div>

                    <div class="field-row">
                        <label>Scan Days Ahead (Window)</label>
                        <input type="number"
                               name="sops[<?= $index ?>][scan_days_ahead]"
                               value="<?= htmlspecialchars($sop['scan_days_ahead'] ?? 2) ?>"
                               min="1" max="30">
                    </div>

                    <div class="field-row">
                        <label>Assign to Properties</label>
                        <div style="margin-bottom: 5px; display: flex; gap: 8px;">
                            <input type="text" class="property-search" placeholder="Search properties..." style="flex: 1; padding: 4px; border: 1px solid #ccc; border-radius: 3px; font-size: 0.8rem;">
                            <button type="button" class="btn btn-secondary btn-sm select-all-btn">Select All</button>
                            <button type="button" class="btn btn-secondary btn-sm clear-properties-btn">Clear</button>
                        </div>
                        <div class="property-checkbox-list">
                            <?php foreach ($config['properties'] as $uuid => $prop): ?>
                                <?php $isChecked = in_array($uuid, $sop['properties'] ?? []); ?>
                                <label class="property-checkbox-item">
                                    <input type="checkbox" name="sops[<?= $index ?>][properties][]" value="<?= htmlspecialchars($uuid) ?>" <?= $isChecked ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($prop['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="actions" style="margin-top:20px;">
            <button type="submit" id="save-all-btn" class="btn btn-primary" style="font-size: 1.1rem; padding: 10px 24px;">💾 Save All SOPs</button>
        </div>
    </form>

    <!-- ═══ Reminders Dashboard ═══ -->
    <h2>Reminders (Last 50)</h2>

    <?php if (empty($reminders)): ?>
        <p style="color:#999; font-size:0.9rem;">No reminders yet. Run the cron job or set up the database first.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
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
                                <br><small style="color:#999;"><?= htmlspecialchars($r['error_message']) ?></small>
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
        
        // Pass PHP property list to JS for dynamic building
        const propertyList = <?= json_encode($config['properties']) ?>;
        const defaultHours = <?= $defaultReminderHours ?>;

        function addSop() {
            const container = document.getElementById('sop-container');
            const idx = sopCounter++;
            const sopId = 'sop_' + Math.random().toString(36).substr(2, 9);
            
            let checkboxesHTML = '';
            for (const [uuid, prop] of Object.entries(propertyList)) {
                checkboxesHTML += `
                <label class="property-checkbox-item">
                    <input type="checkbox" name="sops[${idx}][properties][]" value="${uuid}">
                    ${prop.name.replace(/</g, "&lt;").replace(/>/g, "&gt;")}
                </label>`;
            }

            const html = `
                <div class="property-card" id="sop-block-${idx}">
                    <button type="button" class="remove-sop-btn" onclick="this.parentElement.remove()">X Remove SOP</button>
                    <input type="hidden" name="sops[${idx}][id]" value="${sopId}">
                    
                    <div class="field-row">
                        <label>SOP Name</label>
                        <input type="text" name="sops[${idx}][name]" placeholder="e.g. Welcome Basket Prep" style="width: 100%; padding: 6px;" required>
                    </div>

                    <div class="field-row">
                        <label>SOP Message (Sent to Slack)</label>
                        <textarea name="sops[${idx}][sop_message]" placeholder="Enter the SOP instructions..." required></textarea>
                    </div>

                    <div class="field-row">
                        <label>Reminder Hours Before Check-in</label>
                        <input type="number" name="sops[${idx}][reminder_hours_before]" value="${defaultHours}" min="1" max="168">
                    </div>

                    <div class="field-row">
                        <label>Scan Days Ahead (Window)</label>
                        <input type="number" name="sops[${idx}][scan_days_ahead]" value="2" min="1" max="30">
                    </div>

                    <div class="field-row">
                        <label>Assign to Properties</label>
                        <div style="margin-bottom: 5px; display: flex; gap: 8px;">
                            <input type="text" class="property-search" placeholder="Search properties..." style="flex: 1; padding: 4px; border: 1px solid #ccc; border-radius: 3px; font-size: 0.8rem;">
                            <button type="button" class="btn btn-secondary btn-sm select-all-btn">Select All</button>
                            <button type="button" class="btn btn-secondary btn-sm clear-properties-btn">Clear</button>
                        </div>
                        <div class="property-checkbox-list">
                            ${checkboxesHTML}
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', html);
        }

        $(document).ready(function() {
            // Background dynamic load of API properties
            $.get('?ajax=sync_properties', function(res) {
                if (res.success && res.properties) {
                    Object.assign(propertyList, res.properties);
                    console.log("Properties synced with API dynamically.");
                }
            }).always(function() {
                // Hide the loading banner regardless of success or failure
                $('#api-loading-banner').fadeOut();
            });

            // Property Search Support
            $(document).on('keyup', '.property-search', function() {
                var term = $(this).val().toLowerCase();
                $(this).closest('.field-row').find('.property-checkbox-item').each(function() {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(term) > -1);
                });
            });

            // Select All Checkboxes
            $(document).on('click', '.select-all-btn', function() {
                // Only select visible ones so search-filtering works nicely with Select All
                $(this).closest('.field-row').find('input[type="checkbox"]:visible').prop('checked', true);
            });

            // Clear All Checkboxes
            $(document).on('click', '.clear-properties-btn', function() {
                $(this).closest('.field-row').find('input[type="checkbox"]').prop('checked', false);
            });

            // "Saving..." Animation
            $('form').on('submit', function() {
                const btn = $(this).find('#save-all-btn');
                if(btn.length) {
                    btn.prop('disabled', true).text('⏳ Saving...');
                }
            });
        });
    </script>
</body>
</html>
