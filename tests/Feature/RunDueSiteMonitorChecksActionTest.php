<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\QueueSiteMonitorTargetCheckAction;
use Capell\SiteMonitor\Actions\RunDueSiteMonitorChecksAction;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

it('queues due monitor targets by default', function (): void {
    Queue::fake();

    $target = createDueSiteMonitorTarget();

    $count = RunDueSiteMonitorChecksAction::run();

    expect($count)->toBe(1);
    Queue::assertPushed(RunSiteMonitorTargetJob::class, fn (RunSiteMonitorTargetJob $job): bool => $job->targetId === $target->id);
});

it('queues a requested target check instead of running outbound work in the request', function (): void {
    Queue::fake();
    $target = createDueSiteMonitorTarget();

    QueueSiteMonitorTargetCheckAction::run($target);

    Queue::assertPushed(
        RunSiteMonitorTargetJob::class,
        fn (RunSiteMonitorTargetJob $job): bool => $job->targetId === $target->getKey(),
    );
});

it('can run due monitor targets inline', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(
        state: SiteMonitorState::Passing,
        statusCode: 200,
        responseMs: 25,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    )));

    createDueSiteMonitorTarget();

    $count = RunDueSiteMonitorChecksAction::run(queue: false);

    expect($count)->toBe(1)
        ->and(SiteMonitorRun::query()->count())->toBe(1);
});

it('records an explicit failure when a queued check exhausts its retries', function (): void {
    $target = createDueSiteMonitorTarget();
    $targetId = $target->getKey();
    throw_unless(is_int($targetId), RuntimeException::class, 'Site monitor target must have an integer key.');

    $job = new RunSiteMonitorTargetJob($targetId);
    $job->failed(new RuntimeException('unexpected transport failure'));

    $run = SiteMonitorRun::query()->sole();

    expect($run->state)->toBe(SiteMonitorState::Failing)
        ->and($run->error_type)->toBe('monitor_job_failed')
        ->and($run->error_message)->toBe('unexpected transport failure');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createDueSiteMonitorTarget(array $overrides = []): SiteMonitorTarget
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
        'next_check_at' => CarbonImmutable::now()->subMinute(),
        ...$overrides,
    ]);
}

it('refuses a direct queue request after a target was disabled elsewhere', function (): void {
    Queue::fake();
    $target = createDueSiteMonitorTarget();
    SiteMonitorTarget::query()->whereKey($target->id)->update(['enabled' => false]);

    expect(QueueSiteMonitorTargetCheckAction::run($target))->toBeFalse();
    Queue::assertNothingPushed();
});

it('records an enabled queued check and ignores one disabled before worker execution', function (): void {
    Queue::fake();
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(SiteMonitorState::Passing, 200, 25, null, null, null)));
    $target = createDueSiteMonitorTarget();
    expect(QueueSiteMonitorTargetCheckAction::run($target))->toBeTrue();
    Queue::assertPushed(RunSiteMonitorTargetJob::class, 1);
    $job = new RunSiteMonitorTargetJob($target->id);
    $job->handle();
    expect($target->runs()->count())->toBe(1)
        ->and($target->refresh()->current_state)->toBe(SiteMonitorState::Passing)
        ->and($target->last_checked_at)->not->toBeNull();

    $target->update(['enabled' => false]);
    $job->handle();
    $job->failed(new RuntimeException('Late failure callback'));
    expect($target->runs()->count())->toBe(1)
        ->and($target->refresh()->current_state)->toBe(SiteMonitorState::Passing);
});
