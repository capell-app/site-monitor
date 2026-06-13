<?php

declare(strict_types=1);

return [
    'default_interval_minutes' => 5,
    'default_timeout_ms' => 5000,
    'default_failure_threshold' => 2,
    'batch_size' => 50,
    'warning_response_ms' => 1500,
    'ssl_expiry_warning_days' => 30,
    'domain_expiry_warning_days' => 45,
    'run_retention_days' => 30,
    'max_stale_minutes' => 30,
    'schedule_enabled' => true,
    'site_discovery_auto_targets_enabled' => false,
    'rdap_timeout_ms' => 5000,
    'rdap_endpoints' => [
        'com' => 'https://rdap.verisign.com/com/v1/domain/{domain}',
        'net' => 'https://rdap.verisign.com/net/v1/domain/{domain}',
        'org' => 'https://rdap.publicinterestregistry.org/rdap/org/domain/{domain}',
    ],
];
