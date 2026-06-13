<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SiteMonitorDashboardData extends Data
{
    public function __construct(
        public readonly int $totalTargets,
        public readonly int $enabledTargets,
        public readonly int $passingTargets,
        public readonly int $warningTargets,
        public readonly int $failingTargets,
        public readonly int $openIncidents,
        public readonly ?CarbonImmutable $oldestOpenIncidentAt,
        public readonly ?CarbonImmutable $latestCheckedAt,
        public readonly ?int $medianResponseMs,
    ) {}
}
