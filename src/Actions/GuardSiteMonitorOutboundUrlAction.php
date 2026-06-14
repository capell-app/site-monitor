<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Lorisleiva\Actions\Concerns\AsAction;

final class GuardSiteMonitorOutboundUrlAction
{
    use AsAction;

    public function handle(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            throw new UnsafeSiteMonitorTargetException('invalid_target_url', 'Target URL could not be parsed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeSiteMonitorTargetException('unsupported_target_scheme', 'Target URL must use HTTP or HTTPS.');
        }

        $host = $this->normalizedHost($parts['host'] ?? null);

        if ($host === null) {
            throw new UnsafeSiteMonitorTargetException('missing_target_host', 'Target URL does not contain a host.');
        }

        $this->guardHost($host);

        return $host;
    }

    private function guardHost(string $host): void
    {
        if ($this->privateTargetsAreAllowed()) {
            return;
        }

        if ($this->isLocalHostname($host)) {
            throw new UnsafeSiteMonitorTargetException('unsafe_target_host', 'Target host is local or internal.');
        }

        $addresses = $this->resolveAddresses($host);

        if ($addresses === []) {
            throw new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'Target host could not be resolved before the outbound check.');
        }

        $unsafeAddresses = array_filter(
            $addresses,
            static fn (string $address): bool => filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false,
        );

        if ($unsafeAddresses !== []) {
            throw new UnsafeSiteMonitorTargetException('unsafe_target_address', 'Target host resolves to a private or reserved network address.');
        }
    }

    /**
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            $ipv4Addresses = @gethostbynamel($host);

            if (is_array($ipv4Addresses)) {
                $addresses = array_values(array_filter(
                    $ipv4Addresses,
                    static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false,
                ));
            }
        }

        return array_values(array_unique($addresses));
    }

    private function normalizedHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(trim($host, " \t\n\r\0\x0B."));

        return $host === '' ? null : $host;
    }

    private function isLocalHostname(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || ! str_contains($host, '.');
    }

    private function privateTargetsAreAllowed(): bool
    {
        return ! app()->isProduction()
            && (bool) config('capell-site-monitor.allow_private_targets', false);
    }
}
