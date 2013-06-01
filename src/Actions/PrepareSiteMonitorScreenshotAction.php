<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use RuntimeException;

final class PrepareSiteMonitorScreenshotAction
{
    public function handle(): SiteMonitorTarget
    {
        throw_unless(app()->environment('local', 'testing') && getenv('CAPELL_SCREENSHOT_FIXTURE') === 'record-state', RuntimeException::class, 'Site Monitor screenshot fixtures require the disposable screenshot environment.');

        return (new SiteMonitorTarget)->getConnection()->transaction(static function (): SiteMonitorTarget {
            $target = SiteMonitorTarget::query()->updateOrCreate([
                'source_key' => 'screenshot-homepage',
                'source_package' => 'capell-app/site-monitor',
            ], [
                'name' => 'Screenshot homepage',
                'url' => 'https://screenshot.example.test',
                'check_type' => SiteMonitorCheckType::HttpStatus,
                'interval_minutes' => 5,
                'timeout_ms' => 5000,
                'failure_threshold' => 2,
                'expected_status_minimum' => 200,
                'expected_status_maximum' => 399,
                'enabled' => false,
                'current_state' => SiteMonitorState::Failing,
            ]);
            SiteMonitorIncident::query()->updateOrCreate([
                'target_id' => $target->getKey(),
                'summary' => 'Screenshot homepage returned a demo 503 response.',
            ], [
                'status' => SiteMonitorIncidentStatus::Open,
                'severity' => 'critical',
                'failure_count' => 2,
                'opened_at' => now()->subHour(),
                'last_failure_at' => now(),
            ]);

            return $target;
        });
    }
}
