# Changelog — WP Site Monitor

## [1.5.0] — 2026-04-08

### ✨ New Features

- **Flexible Report Scheduling**: Reports can now be sent on specific days of the week, fixed intervals (e.g., every 14 days), or a combination thereof instead of daily only
  - New setting: "Report Schedule Type" (Specific days vs Fixed interval)
  - New setting: "Report days of the week" (select Mon-Sun)
  - New setting: "Report interval days" (e.g., 7, 14, 30)
  - New setting: "Report timezone" (Site default or custom timezone)
  - Replaces old `daily_report_hour` with flexible `report_time` + timezone support

- **Timezone Configuration**: Set custom timezone for report scheduling independent of site timezone
  - Dropdown with common timezones (America, Europe, Asia, etc.)
  - Defaults to site timezone

- **Vulnerability Scanning (Wordfence Integration)**: 
  - New REST API endpoint: `GET /wp-json/wp-site-monitor/v1/inventory` — exposes installed plugins, themes, and WordPress core version
  - New Settings section: "Vulnerability Scanning (Wordfence)"
  - Auto-registration with Cloud Run vulnerability scanner
  - Auto-unregistration on deactivation
  - Consolidated Slack alerts for critical vulnerabilities (CVSS ≥ 8)

### 🔧 Technical Changes

**Cron Changes:**
- Replaced daily cron `wpsm_daily_health_report` with hourly cron `wpsm_report_check`
- Added `WPSM_Notifier::maybe_send_report()` — intelligently checks if report should send based on schedule settings
- Verification: hour window (±30 min), day of week, interval elapsed, anti-duplicate (20h cooldown)

**API Changes:**
- Added `GET /inventory` endpoint to `class-api.php`
  - Returns: `{ site, core, plugins[], theme }`
  - Authenticated with `x-wpsm-api-key`
  - Used by Cloud Run vulnerability scanner

**Settings Changes:**
- New fields:
  - `report_schedule_type` (weekly_days | interval)
  - `report_days_of_week` (array of 1-7)
  - `report_interval_days` (int)
  - `report_time` (HH:MM, replaces daily_report_hour)
  - `report_timezone` (site | timezone string)
  - `vuln_scanning_enabled` (bool)
  - `vuln_cloud_endpoint` (URL)
  - `vuln_cloud_token` (Bearer token)

**Notifier Changes:**
- Added `maybe_send_report()` method — handles all verification logic for flexible scheduling
- Auto-registration/unregistration methods in `class-settings.php`

**Plugin Changes:**
- `activate()`: Changed cron from daily to hourly `wpsm_report_check`
- `deactivate()`: Added unregistration from Cloud Run when plugin is disabled
- `bind_cron_actions()`: Updated to bind `wpsm_report_check` instead of daily report

**Files Modified:**
- `wp-site-monitor.php` — cron schedule changes
- `includes/class-settings.php` — new schedule UI, register/unregister methods
- `includes/class-api.php` — new `/inventory` endpoint
- `includes/class-notifier.php` — `maybe_send_report()` method
- `mu-plugin/wpsm-watchdog.php` — version bump

### 📚 Documentation

- Added `SCHEDULE_UPGRADE.md` — Complete guide for testing flexible report scheduling
- Added `INVENTORY_ENDPOINT.md` — How to use the new `/inventory` endpoint
- Added `CLOUD_RUN_SPEC.md` — Complete spec for Cloud Run vulnerability scanner (separate repo)

### 🔄 Migration Notes

- **Report scheduling migration**: Old setting `daily_report_hour` is replaced with new flexible scheduling
  - On re-activation, defaults to Mon-Fri, 08:00 site timezone
  - Admin must reconfigure if they had custom daily time

- **Backwards compatibility**: Old daily cron `wpsm_daily_health_report` is cleaned up on activation

### 🐛 Bug Fixes

- None in this release

### ⚠️ Breaking Changes

- Removed `next_daily_report_timestamp()` static method (internal only, not part of public API)
- Daily cron `wpsm_daily_health_report` replaced with hourly cron `wpsm_report_check`
  - If third-party code hooked into `wpsm_daily_health_report`, it needs updating

### 📦 Dependencies

- No new dependencies added
- Still supports: WordPress 5.0+, PHP 7.4+
- Cloud Run deployment (optional): Python 3.11, Flask, requests, google-cloud-storage

---

## [1.4.2] — Earlier

See git history for details.
