<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions\Fixtures;

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use RuntimeException;

final class PrepareSiteMonitorScreenshotAction
{
    private const string CaptureSeed = 'site-monitor-screenshot-homepage';

    public function handle(): SiteMonitorTarget
    {
        $configuredAppPath = getenv('CAPELL_SCREENSHOT_APP_PATH');
        $appPath = is_string($configuredAppPath) ? realpath($configuredAppPath) : false;
        $basePath = realpath(base_path());
        throw_unless(app()->environment('local', 'testing')
            && getenv('CAPELL_SCREENSHOT_FIXTURE') === 'record-state'
            && is_string($appPath)
            && is_string($basePath)
            && $appPath === $basePath, RuntimeException::class, 'Site Monitor screenshot fixtures require the selected disposable screenshot environment.');

        $captureEmail = getenv('CAPELL_SCREENSHOT_ADMIN_EMAIL') ?: (getenv('CAPELL_ADMIN_EMAIL') ?: 'admin@example.com');
        $authenticated = auth()->user();
        throw_if(
            $authenticated !== null && data_get($authenticated, 'email') !== $captureEmail,
            RuntimeException::class,
            'Screenshot fixtures require the selected capture actor.',
        );

        return (new SiteMonitorTarget)->getConnection()->transaction(static function (): SiteMonitorTarget {
            $samples = [
                420 => [200, SiteMonitorState::Passing, 130],
                380 => [200, SiteMonitorState::Passing, 95],
                310 => [503, SiteMonitorState::Failing, 20],
                305 => [503, SiteMonitorState::Failing, 12],
            ];
            $matches = SiteMonitorTarget::query()
                ->where('source_key', 'screenshot-homepage')
                ->where('source_package', 'capell-app/site-monitor')
                ->get();
            throw_if($matches->count() > 1, RuntimeException::class, 'The Site Monitor screenshot target key has multiple owners.');
            $existing = $matches->first();
            $ownedRunIds = [];
            $seenResponseMs = [];
            if ($existing instanceof SiteMonitorTarget) {
                throw_unless(
                    $existing->metadata === ['capture_seed' => self::CaptureSeed]
                        && $existing->name === 'Screenshot homepage'
                        && $existing->url === 'https://screenshot.example.test'
                        && $existing->site_id === null && $existing->language_id === null,
                    RuntimeException::class,
                    'The Site Monitor screenshot target key belongs to another record.',
                );
                foreach (SiteMonitorRun::query()->where('target_id', $existing->getKey())->get() as $run) {
                    $sample = $samples[$run->response_ms] ?? null;
                    throw_unless(
                        $run->metadata === ['capture_seed' => self::CaptureSeed]
                            && $sample !== null && $run->status_code === $sample[0] && $run->state === $sample[1]
                            && $run->error_type === null && $run->error_message === null && $run->redirect_chain === null
                            && ! in_array($run->response_ms, $seenResponseMs, true),
                        RuntimeException::class,
                        'The Site Monitor screenshot target has a foreign run.',
                    );
                    $ownedRunIds[] = $run->getKey();
                    $seenResponseMs[] = $run->response_ms;
                }

                $incidentCount = 0;
                foreach (SiteMonitorIncident::query()->where('target_id', $existing->getKey())->get() as $incident) {
                    $incidentCount++;
                    throw_unless(
                        $incident->metadata === ['capture_seed' => self::CaptureSeed]
                            && $incident->summary === 'Screenshot homepage returned a demo 503 response.'
                            && $incident->status === SiteMonitorIncidentStatus::Open
                            && $incident->severity === 'critical'
                            && $incident->failure_count === 2 && $incident->resolved_at === null
                            && $incidentCount === 1
                            && ($incident->latest_run_id === null || in_array($incident->latest_run_id, $ownedRunIds, true)),
                        RuntimeException::class,
                        'The Site Monitor screenshot target has a foreign incident.',
                    );
                }
            }

            throw_if(
                $existing instanceof SiteMonitorTarget && SiteMonitorIncident::query()->whereIn('latest_run_id', $ownedRunIds)->where('target_id', '!=', $existing->getKey())->exists(),
                RuntimeException::class,
                'A foreign incident references a Site Monitor screenshot run.',
            );

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
                'last_checked_at' => now()->subMinutes(5),
                'metadata' => ['capture_seed' => self::CaptureSeed],
            ]);

            // Capture timestamps move between invocations; only validated fixture
            // descendants may be replaced when the minute changes.
            SiteMonitorRun::query()->whereIn('id', $ownedRunIds)->delete();
            $checkedAt = now()->startOfMinute();
            foreach ($samples as $responseMs => [$status, $state, $minutesAgo]) {
                SiteMonitorRun::query()->create([
                    'target_id' => $target->getKey(),
                    'checked_at' => $checkedAt->copy()->subMinutes($minutesAgo),
                    'state' => $state,
                    'status_code' => $status,
                    'response_ms' => $responseMs,
                    'metadata' => ['capture_seed' => self::CaptureSeed],
                ]);
            }

            SiteMonitorIncident::query()->updateOrCreate([
                'target_id' => $target->getKey(),
                'summary' => 'Screenshot homepage returned a demo 503 response.',
            ], [
                'status' => SiteMonitorIncidentStatus::Open,
                'severity' => 'critical',
                'failure_count' => 2,
                'opened_at' => now()->subHour(),
                'last_failure_at' => now(),
                'latest_run_id' => null,
                'metadata' => ['capture_seed' => self::CaptureSeed],
            ]);

            return $target;
        });
    }
}
