<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Tests\Fixtures;

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;

final class SiteMonitorTargetFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(SiteMonitorCheckType $checkType, array $overrides = []): SiteMonitorTarget
    {
        return SiteMonitorTarget::query()->create([
            'name' => 'Example',
            'url' => 'https://93.184.216.34',
            'check_type' => $checkType,
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
}
