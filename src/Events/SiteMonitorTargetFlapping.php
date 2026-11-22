<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Events;

use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Foundation\Events\Dispatchable;

final class SiteMonitorTargetFlapping
{
    use Dispatchable;

    public function __construct(
        public readonly SiteMonitorTarget $target,
        public readonly SiteMonitorRun $run,
        public readonly int $changes,
    ) {}
}
