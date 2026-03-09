# Team SOP Reminder Creator

A PHP-based "Smart Bridge" between [Hospitable](https://hospitable.com) and Slack. Automatically sends SOP reminders to your team at random shift-aligned times before guest check-in.

## How It Works

1. **Configure** — Open the web UI, tick your properties, and write SOP messages for each.
2. **Discover** — A cron job (every 2 min) fetches accepted reservations from the Hospitable API.
3. **Schedule** — For each new booking, the system picks a random time within a 2-shift (8-hour) window centered around a configurable number of hours before check-in.
4. **Send** — When the scheduled time arrives, a formatted Slack message is posted with the guest info, thread link, and SOP instructions.

### Shift Schedule (BDT / UTC+6)

| Shift | Start | End   |
|-------|-------|-------|
| 1     | 06:00 | 10:00 |
| 2     | 10:00 | 14:00 |
| 3     | 14:00 | 18:00 |
| 4     | 18:00 | 22:00 |
| 5     | 22:00 | 02:00 |
| 6     | 02:00 | 06:00 |

## Setup

### 1. Environment

```bash
cp .env.example .env
```

Fill in your values in `.env`:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — MySQL credentials
- `HOSPITABLE_API_TOKEN` — Bearer token from Hospitable
- `SLACK_BOT_TOKEN` — Slack Bot OAuth token (`xoxb-...`)
- `SLACK_CHANNEL_ID` — Target Slack channel
- `AUTH_USER`, `AUTH_PASS` — HTTP Basic Auth for the web UI

### 2. Database

Run the SQL in your MySQL client:

```sql
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
    scheduled_at    DATETIME NOT NULL,
    sent_at         DATETIME DEFAULT NULL,
    status          ENUM('scheduled','sent','failed') DEFAULT 'scheduled',
    error_message   TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reservation (reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Or use the **"Setup Database"** button in the web UI, or run:
```bash
php db_setup.php
```

### 3. Cron Job (cPanel)

```
*/2 * * * * php /home/YOUR_USER/public_html/path/to/cron.php >> /home/YOUR_USER/logs/sop_cron.log 2>&1
```

### 4. Testing with Mock API

Set `API_MODE=mock` in `.env` to use test data from `mock_api.php`. Switch to `API_MODE=live` for production.

## File Structure

```
├── .env.example          # Environment template
├── .htaccess             # Protects sensitive files
├── config.php            # Env loader, DB connection, helpers
├── config.json           # Property config (managed by UI)
├── db_setup.php          # DB table setup script
├── hospitable_api.php    # Hospitable API client
├── mock_api.php          # Mock API for testing
├── slack.php             # Slack message sender
├── shift_scheduler.php   # 2-shift random time algorithm
├── cron.php              # Heartbeat (runs every 2 min)
├── index.php             # Web UI
└── api_docs/             # Hospitable API documentation
```

## Slack Message Format

```
Guest Name ⌂ Property Name
Thu, Mar 26, 2026 → Tue, Mar 31, 2026
PLATFORM_ID (Accepted)
https://my.hospitable.com/inbox/thread/<conversation-uuid>
submess-<conversation-uuid>
---
<SOP Message>
@here
```

## Edge Cases

- **Last-minute bookings** (≤30 min to check-in) → sends immediately
- **Cancelled reservations** → re-verified before sending, skipped if no longer accepted
- **Modified check-in** → detected and rescheduled automatically
- **Slack rate limits** → 1-second delay between messages
- **Duplicate bookings** → prevented by unique DB constraint
