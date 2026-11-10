<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Capell\SiteMonitor\Support\SiteMonitorNetworkAddressPolicy;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveSiteMonitorSocketAddressAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SiteMonitorHostResolver $resolver) {}

    public function handle(string $host): string
    {
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'Target host could not be resolved before the outbound check.');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'Target resolution did not return an IP address.');
            }

            if (! GuardSiteMonitorOutboundUrlAction::privateTargetsAreAllowed() && ! SiteMonitorNetworkAddressPolicy::isPublic($address)) {
                throw new UnsafeSiteMonitorTargetException('unsafe_target_address', 'Target host resolves to a private or reserved network address.');
            }
        }

        return $addresses[0];
    }
}
