<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\SiteMonitor\Support\DnsSiteMonitorHostResolver;

it('resolves hosts through the Core resolver and drops anything that is not an address', function (): void {
    app()->instance(ResolvesHostAddresses::class, new class implements ResolvesHostAddresses
    {
        public function resolve(string $host): array
        {
            return $host === 'feeds.capell.test' ? ['93.184.216.34', 'not-an-address', '2606:2800:220:1::'] : [];
        }
    });

    expect(resolve(DnsSiteMonitorHostResolver::class)->addresses('feeds.capell.test'))->toBe(['93.184.216.34', '2606:2800:220:1::'])
        ->and(resolve(DnsSiteMonitorHostResolver::class)->addresses('elsewhere.capell.test'))->toBe([]);
});
