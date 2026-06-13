<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Contracts;

use Carbon\CarbonImmutable;

interface SiteMonitorDomainExpiryClient
{
    public function expiresAt(string $domain): ?CarbonImmutable;
}
