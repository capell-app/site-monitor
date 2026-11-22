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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    useSiteMonitorAlertQueue();
});

function snoozeResult(int $status): SiteMonitorCheckResultData
{
    return new SiteMonitorCheckResultData(
        state: $status < 400 ? SiteMonitorState::Passing : SiteMonitorState::Failing,
        statusCode: $status,
        responseMs: 80,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    );
}

/** @param array<string, mixed> $overrides */
function snoozeTarget(array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Snooze',
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

function runSnoozeCommand(SiteMonitorTarget $target): void
{
    $target->refresh()->forceFill(['next_check_at' => CarbonImmutable::now()->subMinute()])->save();
    expect(Artisan::call('capell:site-monitor:run', ['--sync' => true]))->toBe(0);
    workSiteMonitorAlertQueue();
}

/** Bug caught: staff who snooze a noisy target still being paged, or never being paged again afterwards. */
it('records incidents but sends no alert while snoozed, and alerts again once the snooze ends', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com');
    $client = new FakeSiteMonitorHttpClient(snoozeResult(503));
    app()->instance(SiteMonitorHttpClient::class, $client);
    $target = snoozeTarget(['alerts_snoozed_until' => CarbonImmutable::now()->addHour()]);

    runSnoozeCommand($target);

    expect(SiteMonitorIncident::query()->count())->toBe(1)
        ->and(SiteMonitorIncident::query()->firstOrFail()->status)->toBe(SiteMonitorIncidentStatus::Open)
        ->and(SiteMonitorRun::query()->count())->toBe(1);
    Notification::assertNothingSent();

    $client->replaceResult(snoozeResult(200));
    runSnoozeCommand($target);

    expect(SiteMonitorIncident::query()->firstOrFail()->status)->toBe(SiteMonitorIncidentStatus::Resolved);
    Notification::assertNothingSent();

    $target->refresh()->forceFill(['alerts_snoozed_until' => CarbonImmutable::now()->subMinute()])->save();
    $client->replaceResult(snoozeResult(503));
    runSnoozeCommand($target);
    $client->replaceResult(snoozeResult(200));
    runSnoozeCommand($target);

    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 2);
});

/** Bug caught: a sub-second target timeout being rounded up to a whole second on the real request. */
it('sends the stored sub-second timeout on the request made by the run command', function (): void {
    $options = [];
    Http::fake(/** @param array<string, mixed> $requestOptions */ function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('', 200);
    });
    config()->set('capell-site-monitor.allow_private_targets', true);
    snoozeTarget(['timeout_ms' => 250]);

    expect(Artisan::call('capell:site-monitor:run', ['--sync' => true]))->toBe(0);

    expect(SiteMonitorRun::query()->firstOrFail()->state)->toBe(SiteMonitorState::Passing)
        ->and($options['timeout'])->toBeLessThanOrEqual(0.25)
        ->and($options['timeout'])->toBeGreaterThan(0.0);
});
