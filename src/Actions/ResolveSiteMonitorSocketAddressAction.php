<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Capell\SiteMonitor\Contracts\SiteMonitorHostResolverWithin;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Capell\SiteMonitor\Support\SiteMonitorDeadline;
use Capell\SiteMonitor\Support\SiteMonitorNetworkAddressPolicy;
use Illuminate\Http\Client\ConnectionException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveSiteMonitorSocketAddressAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SiteMonitorHostResolver $resolver) {}

    /**
     * @throws ConnectionException when the deadline passes while the host resolves
     */
    public function handle(string $host, ?SiteMonitorDeadline $deadline = null): string
    {
        $addresses = $deadline instanceof SiteMonitorDeadline && $this->resolver instanceof SiteMonitorHostResolverWithin
            ? $this->resolver->addressesWithin($host, $deadline)
            : $this->resolver->addresses($host);

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
