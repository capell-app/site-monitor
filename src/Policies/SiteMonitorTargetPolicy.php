<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Policies;

final class SiteMonitorTargetPolicy extends AbstractSiteMonitorResourcePolicy
{
    protected static function subject(): string
    {
        return 'SiteMonitorTarget';
    }
}
