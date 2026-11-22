<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/SiteMonitorAlertQueue.php';

use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Notifications\SiteMonitorIncidentNotification;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    useSiteMonitorAlertQueue();
});

function lifecycleResult(SiteMonitorState $state): SiteMonitorCheckResultData
{
    return new SiteMonitorCheckResultData(
        state: $state,
        statusCode: $state === SiteMonitorState::Passing ? 200 : 503,
        responseMs: 80,
        expiresAt: null,
        errorType: $state === SiteMonitorState::Passing ? null : 'unexpected_status_code',
        errorMessage: null,
    );
}

function lifecycleTarget(): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Lifecycle',
        'url' => 'https://93.184.216.34',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 1,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => SiteMonitorState::Unknown,
        'next_check_at' => CarbonImmutable::now()->subMinute(),
    ]);
}

function runLifecycleCommand(SiteMonitorTarget $target): void
{
    $target->refresh()->forceFill(['next_check_at' => CarbonImmutable::now()->subMinute()])->save();
    expect(Artisan::call('capell:site-monitor:run', ['--sync' => true]))->toBe(0);
    workSiteMonitorAlertQueue();
}

/**
 * Bug caught: a failing check run by the scheduler never reaching the configured
 * channel, or alerting on every run instead of once per state change.
 */
it('alerts once when the scheduled command opens an incident and once when it recovers', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com');
    $client = new FakeSiteMonitorHttpClient(lifecycleResult(SiteMonitorState::Failing));
    app()->instance(SiteMonitorHttpClient::class, $client);
    $target = lifecycleTarget();

    runLifecycleCommand($target);
    runLifecycleCommand($target);
    runLifecycleCommand($target);

    expect(SiteMonitorIncident::query()->count())->toBe(1)
        ->and(SiteMonitorIncident::query()->firstOrFail()->status)->toBe(SiteMonitorIncidentStatus::Open);
    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 1);

    $client->replaceResult(lifecycleResult(SiteMonitorState::Passing));
    runLifecycleCommand($target);
    runLifecycleCommand($target);

    expect(SiteMonitorIncident::query()->firstOrFail()->status)->toBe(SiteMonitorIncidentStatus::Resolved);
    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 2);
});

/**
 * Bug caught: the command ignoring the configured run retention.
 */
it('prunes check results older than the configured retention when the command runs', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(lifecycleResult(SiteMonitorState::Passing)));
    config()->set('capell-site-monitor.run_retention_days', 7);
    $target = lifecycleTarget();
    $old = lifecycleRun($target, 10);
    $recent = lifecycleRun($target, 3);

    runLifecycleCommand($target);

    expect(SiteMonitorRun::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(SiteMonitorRun::query()->whereKey($recent->getKey())->exists())->toBeTrue();
});

/**
 * Bug caught: resolved incidents accumulating forever because only runs were pruned.
 */
it('prunes resolved incidents older than the configured retention but never open ones', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(lifecycleResult(SiteMonitorState::Passing)));
    config()->set('capell-site-monitor.incident_retention_days', 90);
    $target = lifecycleTarget();

    $oldResolved = lifecycleIncident($target, SiteMonitorIncidentStatus::Resolved, 120, 119);
    $recentResolved = lifecycleIncident($target, SiteMonitorIncidentStatus::Resolved, 40, 39);
    $oldOpen = lifecycleIncident($target, SiteMonitorIncidentStatus::Open, 200, null);

    runLifecycleCommand($target);

    expect(SiteMonitorIncident::query()->whereKey($oldResolved->getKey())->exists())->toBeFalse()
        ->and(SiteMonitorIncident::query()->whereKey($recentResolved->getKey())->exists())->toBeTrue()
        ->and(SiteMonitorIncident::query()->whereKey($oldOpen->getKey())->exists())->toBeTrue();
});

function lifecycleRun(SiteMonitorTarget $target, int $daysAgo): SiteMonitorRun
{
    return SiteMonitorRun::query()->create([
        'target_id' => $target->getKey(),
        'state' => SiteMonitorState::Passing,
        'status_code' => 200,
        'response_ms' => 50,
        'checked_at' => CarbonImmutable::now()->subDays($daysAgo),
    ]);
}

function lifecycleIncident(SiteMonitorTarget $target, SiteMonitorIncidentStatus $status, int $openedDaysAgo, ?int $resolvedDaysAgo): SiteMonitorIncident
{
    return SiteMonitorIncident::query()->create([
        'target_id' => $target->getKey(),
        'status' => $status,
        'severity' => 'critical',
        'summary' => 'Lifecycle is failing',
        'failure_count' => 2,
        'opened_at' => CarbonImmutable::now()->subDays($openedDaysAgo),
        'last_failure_at' => CarbonImmutable::now()->subDays($openedDaysAgo),
        'resolved_at' => $resolvedDaysAgo === null ? null : CarbonImmutable::now()->subDays($resolvedDaysAgo),
    ]);
}
