# Incident alert delivery

Site Monitor sends incident alerts through email, Slack incoming webhooks and generic HTTPS webhooks. Configure any combination in the host application's `config/capell-site-monitor.php`, or set these environment variables:

| Environment variable                             | Config key under `alerts` | Default                                               |
| ------------------------------------------------ | ------------------------- | ----------------------------------------------------- |
| `CAPELL_SITE_MONITOR_ALERT_MAIL_TO`              | `mail_to`                 | Empty; comma-separated email addresses                |
| `CAPELL_SITE_MONITOR_ALERT_SLACK_URL`            | `slack_url`               | Empty; Slack incoming webhook URL                     |
| `CAPELL_SITE_MONITOR_ALERT_WEBHOOK_URL`          | `webhook_url`             | Empty; generic receiver URL                           |
| `CAPELL_SITE_MONITOR_ALERT_WEBHOOK_TOKEN`        | `webhook_token`           | Empty; optional bearer token for the generic receiver |
| `CAPELL_SITE_MONITOR_ALERT_NOTIFY_ON_RECOVERY`   | `notify_on_recovery`      | `true`                                                |
| `CAPELL_SITE_MONITOR_ALERT_HTTP_TIMEOUT_SECONDS` | `http_timeout_seconds`    | `5`; bounded to 1–30 seconds per HTTP request         |
| `CAPELL_SITE_MONITOR_ALERT_QUEUE_CONNECTION`     | `queue_connection`        | The application's default queue connection            |

An empty destination disables that channel. Each destination receives incidents for every target in the installation. Keep URLs and tokens in secret configuration; the Slack URL itself contains a secret. Refresh the host's configuration cache and restart long-running queue workers after changing configuration. Existing published config files must include the new keys, or the corresponding channels remain disabled.

For Slack, create an [incoming webhook](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/) for the intended workspace and channel, then set `CAPELL_SITE_MONITOR_ALERT_SLACK_URL`. No extra Slack notification package or bot token is required. Messages contain the translated incident subject, target address and next action, with Slack markup escaped and link/media previews disabled.

For a generic receiver, set `CAPELL_SITE_MONITOR_ALERT_WEBHOOK_URL`. If the receiver requires a bearer token, set `CAPELL_SITE_MONITOR_ALERT_WEBHOOK_TOKEN`. The webhook sends it as `Authorization: Bearer <token>`. Both settings are controlled by the deployment operator through the host configuration or environment. Site Monitor administrators can change monitored targets through the admin panel. The delivery URL and token are outside that panel's permissions. Review the receiver URL and token together before deploying either change. Slack uses its own incoming webhook URL. Both HTTP channels send a `POST` with `Content-Type: application/json`.

## Generic webhook payload

Opening and recovery use the same fields. `event` is `site_monitor.incident.opened` or `site_monitor.incident.resolved`; the incident ID stays the same on recovery. Example opening payload:

```json
{
    "event": "site_monitor.incident.opened",
    "incident_id": 42,
    "target_id": 7,
    "target_name": "Public website",
    "target_url": "https://www.example.com",
    "state": "failing",
    "status_code": 503,
    "response_ms": 100,
    "error_type": "http_status",
    "failure_count": 2,
    "checked_at": "2026-11-19T10:00:00+00:00",
    "opened_at": "2026-11-19T10:00:00+00:00",
    "resolved_at": null
}
```

`status_code`, `response_ms` and `error_type` can be null. Timestamps use ISO 8601. `resolved_at` is null on opening and populated on recovery. The recovery payload contains the actual passing run. All channels display the target label and origin, including any explicit port. The address excludes credentials, paths, query strings and fragments. The generic payload also includes the target ID and excludes raw error messages, redirect chains and model metadata. Receivers should accept additional fields in future versions and use `event` plus `incident_id` to identify a transition.

## Delivery behaviour and limits

After the incident transaction commits, Site Monitor queues one job per configured channel. An incident opens after the target's failure threshold. Repeated failures of an open incident send no further alert. Automatic recovery queues alerts unless `notify_on_recovery` is false. Built-in alerts cover automatic opening and recovery. Subscribe to `SiteMonitorIncidentManuallyTransitioned` for operator decisions.

Each job uses the channel's current destination in deployment configuration. The job payload contains the incident snapshot and channel name. HTTP channels read their credentials during delivery and keep them out of Laravel notification events, outgoing HTTP telemetry and failure reports. Reports include the channel and the HTTP status or exception type. HTTP receivers must return a 2xx status. Site Monitor treats redirects as failed deliveries.

Both HTTP channels use Core's guarded client with HTTPS, public addresses, DNS pinning, no redirects or environment proxies, bounded connection and request timeouts, and a 64 KiB wire response limit. For these status-only requests, the client leaves the response body encoded. Use the receiver's final public HTTPS endpoint. The `allow_private_targets` option applies to monitor probes.

Run a supervised worker with PHP's `pcntl` extension on the alert queue connection. If the application's default uses Laravel's database/deferred failover, set `CAPELL_SITE_MONITOR_ALERT_QUEUE_CONNECTION=database` to send alerts through its database connection. A failover connection is eligible when every leg is worker-backed, but a Laravel worker only pops jobs from the first backend of a failover connection. Run a supervised worker on each backing connection and queue (for example both Redis and database), so alerts queued during a failover are consumed. Otherwise set the alert queue connection to a single worker-backed connection. If the selected connection is unsuitable, Site Monitor logs a warning and skips delivery. Set the queue's retry or visibility window above the 45-second alert job timeout, which covers delivery including DNS and HTTP.

Each job gets one attempt. Investigate a failed delivery in the application logs and at the receiver. Further failing checks on the same open incident do not resend the opening alert. For durable retries, subscribe to the incident events with an independently queued integration. Use independent external monitoring for failures that stop this application's scheduler, queue, database or host. Verify receipt in the actual mailbox, Slack channel and webhook service before relying on alerts operationally.
