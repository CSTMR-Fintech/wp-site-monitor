# WP Site Monitor

A WordPress plugin that monitors your site's health in real time and sends alerts to Slack. Built for developers and agencies managing WordPress sites.

---

## Features

- 🔴 **Critical alerts** — fatal PHP errors, SSL failures, site unreachable (HTTP 5xx)
- 🟡 **Warning alerts** — WordPress/plugin/theme updates available, slow pages, SSL expiring soon, database size threshold
- 🔵 **Info alerts** — admin logins, 404s, WooCommerce orders pending
- 📊 **Daily health report** — sent to Slack every morning with a full site summary (always includes update info regardless of alert level settings)
- 🔌 **REST API** — expose site status and alerts to external apps in real time
- 🛡️ **Fatal error resilience** — uses a PHP-level shutdown handler + raw cURL so alerts fire even when WordPress itself crashes

---

## Slack Message Format

```
🔴 [CRITICAL] | My Site
Fatal PHP error: Call to undefined function foo() in wp-content/themes/my-theme/functions.php on line 42
12:45 PM
```

```
📊 Daily Health Report | My Site
━━━━━━━━━━━━━━━━━━━━
WordPress 6.9.4: ✅ Up to date
Plugins: ⚠️ 2 update(s): WooCommerce, Yoast SEO
Themes: ✅ All up to date
SSL: ✅ Valid (87 days remaining)
Database: ✅ 134.5 MB
Last 24h alerts: ✅ 0 critical, 1 warnings, 3 info
```

---

## Installation

1. Clone or download this repo into your `wp-content/plugins/` directory
2. Run `composer install` inside the plugin folder
3. Activate the plugin from the WordPress admin
4. Go to **Settings → WP Site Monitor** and configure your Slack Webhook URL

---

## Configuration

| Setting | Description |
|---|---|
| Slack Webhook URL | Incoming Webhook from your Slack app |
| Generic Webhook URL | Optional — sends structured JSON to external apps |
| Real-time Alert Levels | Choose which levels trigger immediate Slack messages |
| Check Interval | How often the scheduled checks run (default: 1 hour) |
| Max Stored Alerts | How many events to keep in the local log (25–500) |
| API Key | Used to authenticate REST API requests |

---

## REST API

All endpoints require authentication via the `x-wpsm-api-key` header or `?api_key=` query parameter.

### `GET /wp-json/wp-site-monitor/v1/status`
Full site status snapshot: WordPress version, plugin/theme update counts, database size, recent alerts summary.

### `GET /wp-json/wp-site-monitor/v1/alerts`
Recent alerts. Optional filters:

| Param | Values | Default |
|---|---|---|
| `level` | `critical`, `warning`, `info` | all |
| `limit` | 1–100 | 25 |

**Example:**
```
GET /wp-json/wp-site-monitor/v1/alerts?level=critical&limit=10
Headers: x-wpsm-api-key: YOUR_KEY
```

### `POST /wp-json/wp-site-monitor/v1/webhook`
Receive external monitoring events and store them as alerts.

```json
{
  "level": "critical",
  "message": "Deployment failed on production."
}
```

---

## Releasing Updates

This plugin uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) to deliver updates via GitHub Releases.

1. Bump the version in `wp-site-monitor.php` (header + `WPSM_VERSION` constant)
2. Commit and push
3. Create a version tag — GitHub Actions builds and attaches the zip automatically:

```bash
git tag v1.1.0
git push origin v1.1.0
```

WordPress sites running the plugin will see the update notification within the next check cycle.

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Composer (for local development)
- A Slack app with an Incoming Webhook configured

---

## License

MIT
