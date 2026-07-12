<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Support\RdapDomainExpiryClient;

it('binds monitor client contracts', function (): void {
    expect(app(SiteMonitorHttpClient::class))->toBeInstanceOf(LaravelSiteMonitorHttpClient::class)
        ->and(app(SiteMonitorDomainExpiryClient::class))->toBeInstanceOf(RdapDomainExpiryClient::class)
        ->and(CapellCore::getModels())->toContain(
            SiteMonitorTarget::class,
            SiteMonitorRun::class,
            SiteMonitorIncident::class,
        );
});
