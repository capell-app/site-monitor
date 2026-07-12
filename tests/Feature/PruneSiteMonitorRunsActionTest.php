<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\PruneSiteMonitorRunsAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;

it('prunes old monitor runs while preserving latest and incident-linked evidence', function (): void {
    $target = pruneSiteMonitorTarget();

    $prunableRun = pruneSiteMonitorRun($target, CarbonImmutable::now()->subDays(60));
    $incidentRun = pruneSiteMonitorRun($target, CarbonImmutable::now()->subDays(55));
    $latestRun = pruneSiteMonitorRun($target, CarbonImmutable::now()->subMinute());

    SiteMonitorIncident::query()->create([
        'target_id' => $target->getKey(),
        'latest_run_id' => $incidentRun->getKey(),
        'status' => SiteMonitorIncidentStatus::Open,
        'severity' => 'critical',
        'summary' => 'Example is failing',
        'failure_count' => 2,
        'opened_at' => CarbonImmutable::now()->subDays(55),
        'last_failure_at' => CarbonImmutable::now()->subDays(55),
    ]);

    $deleted = PruneSiteMonitorRunsAction::run(retentionDays: 30);

    expect($deleted)->toBe(1)
        ->and(SiteMonitorRun::query()->whereKey($prunableRun->getKey())->exists())->toBeFalse()
        ->and(SiteMonitorRun::query()->whereKey($incidentRun->getKey())->exists())->toBeTrue()
        ->and(SiteMonitorRun::query()->whereKey($latestRun->getKey())->exists())->toBeTrue();
});

function pruneSiteMonitorTarget(): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Example',
        'url' => 'https://93.184.216.34',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => SiteMonitorState::Unknown,
    ]);
}

function pruneSiteMonitorRun(SiteMonitorTarget $target, CarbonImmutable $checkedAt): SiteMonitorRun
{
    return SiteMonitorRun::query()->create([
        'target_id' => $target->getKey(),
        'state' => SiteMonitorState::Failing,
        'status_code' => 500,
        'response_ms' => 100,
        'error_type' => 'unexpected_status_code',
        'error_message' => 'Expected HTTP 200-399, received 500.',
        'checked_at' => $checkedAt,
    ]);
}
