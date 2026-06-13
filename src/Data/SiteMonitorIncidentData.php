<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SiteMonitorIncidentData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $targetId,
        public readonly SiteMonitorIncidentStatus $status,
        public readonly string $severity,
        public readonly string $summary,
        public readonly int $failureCount,
        public readonly CarbonImmutable $openedAt,
        public readonly ?CarbonImmutable $lastFailureAt,
        public readonly ?CarbonImmutable $resolvedAt,
    ) {}
}
