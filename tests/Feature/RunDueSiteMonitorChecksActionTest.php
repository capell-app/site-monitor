<?php

declare(strict_types=1);

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

    $count = (new RunDueSiteMonitorChecksAction)->handle();

    expect($count)->toBe(1);
    Queue::assertPushed(RunSiteMonitorTargetJob::class, fn (RunSiteMonitorTargetJob $job): bool => $job->targetId === $target->id);
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

    $count = (new RunDueSiteMonitorChecksAction)->handle(queue: false);

    expect($count)->toBe(1)
        ->and(SiteMonitorRun::query()->count())->toBe(1);
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
