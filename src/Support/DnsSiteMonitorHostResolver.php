<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Override;

/**
 * The default resolver delegates to Core, so every outbound check in the install
 * shares one DNS implementation and tests can swap the answers in one place.
 */
final readonly class DnsSiteMonitorHostResolver implements SiteMonitorHostResolver
{
    public function __construct(private ResolvesHostAddresses $resolver) {}

    /**
     * @return list<string>
     */
    #[Override]
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return array_values(array_filter(
            $this->resolver->resolve($host),
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false,
        ));
    }
}
