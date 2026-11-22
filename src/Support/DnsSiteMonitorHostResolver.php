<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Contracts\Security\ResolvesHostAddressesWithin;
use Capell\SiteMonitor\Contracts\SiteMonitorHostResolverWithin;
use Override;

/**
 * The default resolver delegates to Core, so every outbound check in the install
 * shares one DNS implementation and tests can swap the answers in one place.
 *
 * With a deadline, Core's bounded resolver abandons a lookup when the check's
 * remaining time runs out, and the check then records a timeout.
 */
final readonly class DnsSiteMonitorHostResolver implements SiteMonitorHostResolverWithin
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

        return $this->ipAddresses($this->resolver->resolve($host));
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function addressesWithin(string $host, SiteMonitorDeadline $deadline): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = $this->resolver instanceof ResolvesHostAddressesWithin
            ? $this->resolver->resolveWithin($host, $deadline->remainingSeconds())
            : $this->resolver->resolve($host);

        // A lookup abandoned at the deadline answers nothing: report the timeout,
        // not an unresolvable host.
        $deadline->throwIfExpired();

        return $this->ipAddresses($addresses);
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function ipAddresses(array $addresses): array
    {
        return array_values(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false,
        ));
    }
}
