<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SiteMonitorIncidentTransitionData extends Data
{
    public function __construct(
        public readonly SiteMonitorIncidentStatus $fromStatus,
        public readonly SiteMonitorIncidentStatus $toStatus,
        public readonly string $actorId,
        public readonly string $reason,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
