<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

class SiteMonitorClock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
