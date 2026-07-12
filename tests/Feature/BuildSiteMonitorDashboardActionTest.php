<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\BuildSiteMonitorDashboardAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('keeps dashboard aggregation inside the declared admin query budget', function (): void {
    $passingTarget = createDashboardSiteMonitorTarget('Passing homepage', SiteMonitorState::Passing, [
        'last_checked_at' => CarbonImmutable::parse('2026-06-16 09:00:00'),
    ]);
    $warningTarget = createDashboardSiteMonitorTarget('Slow checkout', SiteMonitorState::Warning);
    $failingTarget = createDashboardSiteMonitorTarget('Down docs', SiteMonitorState::Failing, [
        'enabled' => false,
    ]);

    createDashboardSiteMonitorRun($passingTarget, 100);
    createDashboardSiteMonitorRun($warningTarget, 900);
    $latestFailingRun = createDashboardSiteMonitorRun($failingTarget, 200);

    SiteMonitorIncident::query()->create([
        'target_id' => $failingTarget->id,
        'latest_run_id' => $latestFailingRun->id,
        'status' => SiteMonitorIncidentStatus::Open,
        'severity' => 'critical',
        'summary' => 'Down docs is failing.',
        'failure_count' => 2,
        'opened_at' => CarbonImmutable::parse('2026-06-16 08:00:00'),
        'last_failure_at' => CarbonImmutable::parse('2026-06-16 08:05:00'),
    ]);

    $queryCount = 0;
    DB::listen(static function () use (&$queryCount): void {
        $queryCount++;
    });

    $dashboard = BuildSiteMonitorDashboardAction::run();

    expect($queryCount)->toBeLessThanOrEqual(20)
        ->and($dashboard->totalTargets)->toBe(3)
        ->and($dashboard->enabledTargets)->toBe(2)
        ->and($dashboard->passingTargets)->toBe(1)
        ->and($dashboard->warningTargets)->toBe(1)
        ->and($dashboard->failingTargets)->toBe(1)
        ->and($dashboard->openIncidents)->toBe(1)
        ->and($dashboard->medianResponseMs)->toBe(200)
        ->and($dashboard->oldestOpenIncidentAt?->toDateTimeString())->toBe('2026-06-16 08:00:00')
        ->and($dashboard->latestCheckedAt?->toDateTimeString())->toBe('2026-06-16 09:00:00');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createDashboardSiteMonitorTarget(string $name, SiteMonitorState $state, array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => $name,
        'url' => 'https://example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => $state,
        ...$overrides,
    ]);
}

function createDashboardSiteMonitorRun(SiteMonitorTarget $target, int $responseMs): SiteMonitorRun
{
    return SiteMonitorRun::query()->create([
        'target_id' => $target->id,
        'state' => $target->current_state,
        'status_code' => $target->current_state === SiteMonitorState::Passing ? 200 : 500,
        'response_ms' => $responseMs,
        'checked_at' => CarbonImmutable::parse('2026-06-16 09:00:00')->subSeconds($responseMs),
    ]);
}
