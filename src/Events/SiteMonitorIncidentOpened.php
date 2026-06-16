<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Events;

use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Foundation\Events\Dispatchable;

final class SiteMonitorIncidentOpened
{
    use Dispatchable;

    public function __construct(
        public readonly SiteMonitorIncident $incident,
        public readonly SiteMonitorTarget $target,
        public readonly SiteMonitorRun $run,
    ) {}
}
