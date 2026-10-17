<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Contracts;

interface SiteMonitorHostResolver
{
    /** @return list<string> */
    public function addresses(string $host): array;
}
