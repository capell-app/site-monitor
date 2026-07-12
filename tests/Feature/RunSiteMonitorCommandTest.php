<?php

declare(strict_types=1);

use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

it('runs due monitor checks inline from the console command', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(
        state: SiteMonitorState::Passing,
        statusCode: 200,
        responseMs: 60,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    )));

    createCommandSiteMonitorTarget();

    $exitCode = Artisan::call('capell:site-monitor:run', ['--sync' => true]);

    expect($exitCode)->toBe(0)
        ->and(SiteMonitorRun::query()->count())->toBe(1);
});

it('runs diagnostics from the doctor command without executing monitor checks', function (): void {
    createCommandSiteMonitorTarget([
        'last_checked_at' => CarbonImmutable::now(),
        'next_check_at' => CarbonImmutable::now()->subMinute(),
    ]);

    $exitCode = Artisan::call('capell:site-monitor:doctor');

    expect($exitCode)->toBe(0)
        ->and(SiteMonitorRun::query()->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createCommandSiteMonitorTarget(array $overrides = []): SiteMonitorTarget
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
