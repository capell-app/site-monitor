<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReconcileSiteMonitorIncidentAction
{
    use AsAction;

    public function handle(SiteMonitorTarget $target, SiteMonitorRun $run): ?SiteMonitorIncident
    {
        if ($run->state === SiteMonitorState::Passing) {
            $this->resolveOpenIncident($target, $run);

            return null;
        }

        if ($run->state !== SiteMonitorState::Failing) {
            $incident = $target->openIncident()->first();

            return $incident instanceof SiteMonitorIncident ? $incident : null;
        }

        if ((int) $target->consecutive_failures < (int) $target->failure_threshold) {
            return null;
        }

        $incident = $target->openIncident()->first();

        if ($incident instanceof SiteMonitorIncident) {
            $incident->forceFill([
                'latest_run_id' => $run->getKey(),
                'failure_count' => max((int) $incident->failure_count + 1, (int) $target->consecutive_failures),
                'last_failure_at' => $run->checked_at,
                'metadata' => [
                    'last_error_type' => $run->error_type,
                    'last_status_code' => $run->status_code,
                ],
            ])->save();

            return $incident;
        }

        return SiteMonitorIncident::query()->create([
            'target_id' => $target->getKey(),
            'latest_run_id' => $run->getKey(),
            'status' => SiteMonitorIncidentStatus::Open,
            'severity' => 'critical',
            'summary' => sprintf('%s is failing', $target->name),
            'failure_count' => max(1, (int) $target->consecutive_failures),
            'opened_at' => CarbonImmutable::now(),
            'last_failure_at' => $run->checked_at,
            'metadata' => [
                'last_error_type' => $run->error_type,
                'last_status_code' => $run->status_code,
            ],
        ]);
    }

    private function resolveOpenIncident(SiteMonitorTarget $target, SiteMonitorRun $run): void
    {
        $incident = $target->openIncident()->first();

        if (! $incident instanceof SiteMonitorIncident) {
            return;
        }

        $incident->forceFill([
            'latest_run_id' => $run->getKey(),
            'status' => SiteMonitorIncidentStatus::Resolved,
            'resolved_at' => CarbonImmutable::now(),
        ])->save();
    }
}
