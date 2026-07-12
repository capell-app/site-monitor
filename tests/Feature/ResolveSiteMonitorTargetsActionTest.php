<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\ResolveSiteMonitorTargetsAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;

it('returns enabled targets whose next check is due', function (): void {
    $dueTarget = createResolvableSiteMonitorTarget(['next_check_at' => CarbonImmutable::now()->subMinute()]);

    createResolvableSiteMonitorTarget(['next_check_at' => CarbonImmutable::now()->addMinute()]);
    createResolvableSiteMonitorTarget(['enabled' => false, 'next_check_at' => CarbonImmutable::now()->subMinute()]);

    $targets = (new ResolveSiteMonitorTargetsAction)->handle();

    expect($targets)->toHaveCount(1)
        ->and($targets->firstOrFail()->is($dueTarget))->toBeTrue();
});

it('can limit due targets by site and target id', function (): void {
    $matchingTarget = createResolvableSiteMonitorTarget(['site_id' => 10, 'next_check_at' => null]);
    createResolvableSiteMonitorTarget(['site_id' => 20, 'next_check_at' => null]);

    $targets = (new ResolveSiteMonitorTargetsAction)->handle(siteId: 10, targetId: $matchingTarget->id);

    expect($targets)->toHaveCount(1)
        ->and($targets->firstOrFail()->is($matchingTarget))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createResolvableSiteMonitorTarget(array $overrides = []): SiteMonitorTarget
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
        ...$overrides,
    ]);
}
