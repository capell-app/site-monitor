<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Data\SiteMonitorDashboardData;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildSiteMonitorDashboardAction
{
    use AsAction;

    public function handle(): SiteMonitorDashboardData
    {
        $responseTimes = SiteMonitorRun::query()
            ->whereNotNull('response_ms')
            ->latest('checked_at')
            ->limit(100)
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
            ->min('opened_at');
        $latestCheckedAt = SiteMonitorTarget::query()->max('last_checked_at');

        return new SiteMonitorDashboardData(
            totalTargets: SiteMonitorTarget::query()->count(),
            enabledTargets: SiteMonitorTarget::query()->where('enabled', true)->count(),
            passingTargets: SiteMonitorTarget::query()->where('current_state', SiteMonitorState::Passing->value)->count(),
            warningTargets: SiteMonitorTarget::query()->where('current_state', SiteMonitorState::Warning->value)->count(),
            failingTargets: SiteMonitorTarget::query()->where('current_state', SiteMonitorState::Failing->value)->count(),
            openIncidents: SiteMonitorIncident::query()->where('status', SiteMonitorIncidentStatus::Open->value)->count(),
            oldestOpenIncidentAt: is_string($oldestOpenIncidentAt) ? CarbonImmutable::parse($oldestOpenIncidentAt) : null,
            latestCheckedAt: is_string($latestCheckedAt) ? CarbonImmutable::parse($latestCheckedAt) : null,
            medianResponseMs: is_int($medianResponseMs) ? $medianResponseMs : null,
        );
    }
}
