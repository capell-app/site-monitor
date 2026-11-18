<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Support\Http\PublicAddressPolicy;

/**
 * The single public-address rule for every site monitor outbound request, delegated to
 * Core so site monitor refuses exactly what every other outbound client refuses.
 */
final class SiteMonitorNetworkAddressPolicy
{
    public static function isPublic(string $address): bool
    {
        return resolve(PublicAddressPolicy::class)->isPublicAddress($address);
    }
}
