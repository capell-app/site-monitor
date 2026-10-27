<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Data\SiteMonitorDashboardData;
use Capell\SiteMonitor\Enums\SiteMonitorEvidenceStatus;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static SiteMonitorDashboardData run()
 */
final class BuildSiteMonitorDashboardAction
{
    use AsFake;
    use AsObject;

    public function handle(): SiteMonitorDashboardData
    {
        // Mixed legacy wall times cannot be ordered in SQL. Keep only the most
        // recent 100 instants in memory while streaming the retained run history.
        $recentRuns = (new SiteMonitorRun)->newCollection();
        foreach (SiteMonitorRun::query()->whereNotNull('response_ms')->cursor() as $run) {
            $recentRuns->push($run);
            $recentRuns = $recentRuns
                ->sort(static fn (SiteMonitorRun $left, SiteMonitorRun $right): int => ($right->checked_at <=> $left->checked_at) ?: ($right->getKey() <=> $left->getKey()))
                ->take(100);
        }

        $responseTimes = $recentRuns
            ->pluck('response_ms')
            ->map(static function (mixed $value): ?int {
                if (is_int($value)) {
                    return $value;
                }

                if (is_string($value) && is_numeric($value)) {
                    return (int) $value;
                }

                return null;
            })
            ->filter(static fn (?int $value): bool => $value !== null)
            ->sort()
            ->values();

        $medianResponseMs = $responseTimes->isEmpty()
            ? null
            : $responseTimes->get((int) floor(($responseTimes->count() - 1) / 2));

        $oldestOpenIncidentAt = SiteMonitorIncident::query()
            ->where('status', SiteMonitorIncidentStatus::Open->value)
            ->get()
            ->map(static fn (SiteMonitorIncident $incident) => $incident->opened_at)
            ->sort()
            ->first();
        $latestCheckedAt = SiteMonitorTarget::query()->get()
            ->map(static fn (SiteMonitorTarget $target) => $target->last_checked_at)
            ->filter()
            ->sortDesc()
            ->first();

        $overdueTargets = 0;
        $neverCheckedTargets = 0;
        foreach (SiteMonitorTarget::query()->where('enabled', true)->cursor() as $target) {
            $evidence = BuildSiteMonitorEvidenceAction::run($target);
            $overdueTargets += $evidence->status === SiteMonitorEvidenceStatus::Overdue ? 1 : 0;
            $neverCheckedTargets += $evidence->status === SiteMonitorEvidenceStatus::NeverChecked ? 1 : 0;
        }

        return new SiteMonitorDashboardData(
            totalTargets: SiteMonitorTarget::query()->count(),
            enabledTargets: SiteMonitorTarget::query()->where('enabled', true)->count(),
            passingTargets: SiteMonitorTarget::query()->where('current_state', SiteMonitorState::Passing->value)->count(),
            warningTargets: SiteMonitorTarget::query()->where('current_state', SiteMonitorState::Warning->value)->count(),
            failingTargets: SiteMonitorTarget::query()->where('current_state', SiteMonitorState::Failing->value)->count(),
            openIncidents: SiteMonitorIncident::query()->where('status', SiteMonitorIncidentStatus::Open->value)->count(),
            oldestOpenIncidentAt: $oldestOpenIncidentAt,
            latestCheckedAt: $latestCheckedAt,
            medianResponseMs: is_int($medianResponseMs) ? $medianResponseMs : null,
            overdueTargets: $overdueTargets,
            neverCheckedTargets: $neverCheckedTargets,
        );
    }
}
