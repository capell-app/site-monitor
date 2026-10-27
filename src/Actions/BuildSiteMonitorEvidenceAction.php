<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Data\SiteMonitorEvidenceData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorEvidenceStatus;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildSiteMonitorEvidenceAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteMonitorTarget $target): SiteMonitorEvidenceData
    {
        $overdueAt = $target->last_checked_at?->utc()->addMinutes(max(1, $target->interval_minutes) * 2);
        $status = match (true) {
            ! $target->enabled => SiteMonitorEvidenceStatus::Paused,
            $target->last_checked_at === null => SiteMonitorEvidenceStatus::NeverChecked,
            $target->last_checked_at->isAfter(CarbonImmutable::now('UTC')) => SiteMonitorEvidenceStatus::Overdue,
            $overdueAt !== null && CarbonImmutable::now('UTC')->greaterThan($overdueAt) => SiteMonitorEvidenceStatus::Overdue,
            default => SiteMonitorEvidenceStatus::Fresh,
        };

        return new SiteMonitorEvidenceData(
            status: $status,
            lastCheckedAt: $target->last_checked_at,
            overdueAt: $overdueAt,
            invalidStatusRange: $target->check_type === SiteMonitorCheckType::HttpStatus
                && $target->expected_status_minimum > $target->expected_status_maximum,
        );
    }
}
