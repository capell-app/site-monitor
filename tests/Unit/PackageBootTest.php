<?php

declare(strict_types=1);

use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Support\RdapDomainExpiryClient;

it('binds monitor client contracts', function (): void {
    expect(app(SiteMonitorHttpClient::class))->toBeInstanceOf(LaravelSiteMonitorHttpClient::class);
    expect(app(SiteMonitorDomainExpiryClient::class))->toBeInstanceOf(RdapDomainExpiryClient::class);
});
