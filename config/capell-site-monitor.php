<?php

declare(strict_types=1);

return [
    'default_interval_minutes' => 5,
    'default_timeout_ms' => 5000,
    'default_failure_threshold' => 2,
    'batch_size' => 50,
    'warning_response_ms' => 1500,
    'allow_private_targets' => false,
    'max_redirects' => 5,
    'ssl_expiry_warning_days' => 30,
    'domain_expiry_warning_days' => 45,
    // A target is flapping when its up/down state changed at least `flap_change_threshold`
    // times within its last `flap_window_runs` runs. It alerts once, then stays quiet until it settles.
    // Set `flap_window_runs` to 0 to switch flap detection off.
    'flap_window_runs' => 10,
    'flap_change_threshold' => 4,
    'run_retention_days' => 30,
    // Resolved incidents are deleted after this many days; open incidents are kept. 0 keeps them all.
    'incident_retention_days' => 365,
    'max_stale_minutes' => 30,
    // Each configured channel receives incident opening and automatic recovery alerts.
    // Leave a destination empty to disable that channel. HTTP destinations require public HTTPS.
    'alerts' => [
        'mail_to' => env('CAPELL_SITE_MONITOR_ALERT_MAIL_TO', ''),
        'slack_url' => env('CAPELL_SITE_MONITOR_ALERT_SLACK_URL', ''),
        'webhook_url' => env('CAPELL_SITE_MONITOR_ALERT_WEBHOOK_URL', ''),
        'webhook_token' => env('CAPELL_SITE_MONITOR_ALERT_WEBHOOK_TOKEN', ''),
        'notify_on_recovery' => env('CAPELL_SITE_MONITOR_ALERT_NOTIFY_ON_RECOVERY', true),
        'http_timeout_seconds' => env('CAPELL_SITE_MONITOR_ALERT_HTTP_TIMEOUT_SECONDS', 5),
        'queue_connection' => env('CAPELL_SITE_MONITOR_ALERT_QUEUE_CONNECTION'),
    ],
    'schedule_enabled' => true,
    'site_discovery_auto_targets_enabled' => false,
    'rdap_timeout_ms' => 5000,
    // Outbound retry policy for RDAP domain expiry lookups, which are idempotent reads.
    // Deliberately not used by the uptime prober, where a retry would mask an outage.
    'rdap' => [
        'retry_times' => 3,
        'retry_delay_ms' => 500,
        'retry_after_max_ms' => 60000,
    ],
    'rdap_endpoints' => [
        'co.uk' => 'https://rdap.nominet.uk/uk/domain/{domain}',
        'com' => 'https://rdap.verisign.com/com/v1/domain/{domain}',
        'net' => 'https://rdap.verisign.com/net/v1/domain/{domain}',
        'org' => 'https://rdap.publicinterestregistry.org/rdap/org/domain/{domain}',
        'uk' => 'https://rdap.nominet.uk/uk/domain/{domain}',
        'io' => 'https://rdap.nic.io/domain/{domain}',
        'co' => 'https://rdap.nic.co/domain/{domain}',
        'app' => 'https://pubapi.registry.google/rdap/domain/{domain}',
        'dev' => 'https://pubapi.registry.google/rdap/domain/{domain}',
        'page' => 'https://pubapi.registry.google/rdap/domain/{domain}',
    ],
];
