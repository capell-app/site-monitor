<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Contracts;

use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Models\SiteMonitorTarget;

interface SiteMonitorHttpClient
{
    public function check(SiteMonitorTarget $target): SiteMonitorCheckResultData;
}
