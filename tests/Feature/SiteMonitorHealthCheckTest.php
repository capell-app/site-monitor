<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Health\SiteMonitorHealthCheck;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;

it('reports storage and command diagnostics', function (): void {
    $results = SiteMonitorHealthCheck::runDiagnostics();

    expect($results)->each->toBeInstanceOf(DoctorCheckResultData::class)
        ->and($results->pluck('passed')->contains(false))->toBeFalse();
});

it('fails incident diagnostics when incidents are open', function (): void {
    $target = createHealthSiteMonitorTarget();

    SiteMonitorIncident::query()->create([
        'target_id' => $target->getKey(),
        'status' => SiteMonitorIncidentStatus::Open,
        'severity' => 'critical',
        'summary' => 'Example is failing',
        'failure_count' => 1,
        'opened_at' => CarbonImmutable::now(),
    ]);

    $result = SiteMonitorHealthCheck::runDiagnostics('site-monitor.open-incidents')->firstOrFail();

    expect($result)->toBeInstanceOf(DoctorCheckResultData::class)
        ->and($result->passed)->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createHealthSiteMonitorTarget(array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Example',
        'url' => 'https://example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => SiteMonitorState::Unknown,
        'last_checked_at' => CarbonImmutable::now(),
        ...$overrides,
    ]);
}
