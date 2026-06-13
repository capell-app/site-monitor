<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Tests\Fixtures;

use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Carbon\CarbonImmutable;

final class FakeSiteMonitorDomainExpiryClient implements SiteMonitorDomainExpiryClient
{
    public function __construct(
        private ?CarbonImmutable $expiresAt,
    ) {}

    public function expiresAt(string $domain): ?CarbonImmutable
    {
        return $this->expiresAt;
    }
}
