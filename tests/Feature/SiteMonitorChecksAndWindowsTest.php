<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/SiteMonitorAlertQueue.php';

use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Data\SiteMonitorIncidentAlertData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
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

function checksPage(int $status = 200, int $ms = 100, string $body = 'Welcome. Add to basket'): SiteMonitorCheckResultData
{
    return new SiteMonitorCheckResultData(
        state: $status < 400 ? SiteMonitorState::Passing : SiteMonitorState::Failing,
        statusCode: $status,
        responseMs: $ms,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
        body: $body,
    );
}

/** @param array<string, mixed> $overrides */
function checksTarget(array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Checks',
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
        ...$overrides,
    ]);
}

function runChecksCommand(SiteMonitorTarget $target): SiteMonitorRun
{
    $target->refresh()->forceFill(['next_check_at' => CarbonImmutable::now()->subMinute()])->save();
    expect(Artisan::call('capell:site-monitor:run', ['--sync' => true]))->toBe(0);
    workSiteMonitorAlertQueue();

    return SiteMonitorRun::query()->latest('id')->firstOrFail();
}

/** Bug caught: a 200 page that has lost its key content (an empty shell) reported as healthy. */
it('fails the check when the expected keyword is missing from the page', function (): void {
    $client = new FakeSiteMonitorHttpClient(checksPage(body: 'Maintenance shell'));
    app()->instance(SiteMonitorHttpClient::class, $client);
    $target = checksTarget(['expected_keyword' => 'Add to basket']);

    $missing = runChecksCommand($target);
    expect($missing->state)->toBe(SiteMonitorState::Failing)
        ->and($missing->error_type)->toBe('keyword_missing')
        ->and(SiteMonitorIncident::query()->count())->toBe(1);

    $client->replaceResult(checksPage());
    expect(runChecksCommand($target)->state)->toBe(SiteMonitorState::Passing);
});

/** Bug caught: a very slow page only ever warning, never failing, once the operator set a limit. */
it('fails the check when the response is slower than the target latency threshold', function (): void {
    $client = new FakeSiteMonitorHttpClient(checksPage(ms: 900));
    app()->instance(SiteMonitorHttpClient::class, $client);
    $target = checksTarget(['max_response_ms' => 500]);

    $slow = runChecksCommand($target);
    expect($slow->state)->toBe(SiteMonitorState::Failing)
        ->and($slow->error_type)->toBe('response_too_slow');

    $client->replaceResult(checksPage(ms: 300));
    expect(runChecksCommand($target)->state)->toBe(SiteMonitorState::Passing);
});

/** Bug caught: planned downtime paging the team and leaving a false incident. */
it('opens no incident and sends no alert during a maintenance window, and does once it ends', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com');
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(checksPage(status: 503)));
    $target = checksTarget([
        'maintenance_starts_at' => CarbonImmutable::now()->subHour(),
        'maintenance_ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    runChecksCommand($target);
    runChecksCommand($target);

    expect(SiteMonitorIncident::query()->count())->toBe(0)
        ->and(SiteMonitorRun::query()->count())->toBe(2);
    Notification::assertNothingSent();

    $target->refresh()->forceFill(['maintenance_ends_at' => CarbonImmutable::now()->subMinute()])->save();
    runChecksCommand($target);

    expect(SiteMonitorIncident::query()->count())->toBe(1);
    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 1);
});

/** Bug caught: a check flipping every run paging on every flip. */
it('marks a flipping target as flapping and alerts once instead of on every flip', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com');
    config()->set('capell-site-monitor.flap_window_runs', 6);
    config()->set('capell-site-monitor.flap_change_threshold', 4);
    $client = new FakeSiteMonitorHttpClient(checksPage(status: 503));
    app()->instance(SiteMonitorHttpClient::class, $client);
    $target = checksTarget();

    foreach ([503, 200, 503, 200] as $status) {
        $client->replaceResult(checksPage(status: $status));
        runChecksCommand($target);
    }

    expect($target->refresh()->is_flapping)->toBeFalse();
    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 4);

    foreach ([503, 200, 503, 200] as $status) {
        $client->replaceResult(checksPage(status: $status));
        runChecksCommand($target);
    }

    expect($target->refresh()->is_flapping)->toBeTrue();
    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 5);
    Notification::assertSentOnDemand(
        SiteMonitorIncidentNotification::class,
        static fn (SiteMonitorIncidentNotification $notification): bool => $notification->toWebhook()->event === SiteMonitorIncidentAlertData::EVENT_FLAPPING,
    );
});
