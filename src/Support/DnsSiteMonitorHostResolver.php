<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Override;

final class DnsSiteMonitorHostResolver implements SiteMonitorHostResolver
{
    #[Override]
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
