<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Events;

use Capell\SiteMonitor\Data\SiteMonitorIncidentTransitionData;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Illuminate\Foundation\Events\Dispatchable;

final class SiteMonitorIncidentManuallyTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly SiteMonitorIncident $incident,
        public readonly SiteMonitorIncidentTransitionData $transition,
    ) {}
}
