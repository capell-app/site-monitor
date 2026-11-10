<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Capell\SiteMonitor\Support\SiteMonitorNetworkAddressPolicy;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(string $url)
 */
final class GuardSiteMonitorOutboundUrlAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SiteMonitorHostResolver $resolver) {}

    public static function privateTargetsAreAllowed(): bool
    {
        return ! app()->isProduction()
            && (bool) config('capell-site-monitor.allow_private_targets', false);
    }

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
        if (self::privateTargetsAreAllowed()) {
            return;
        }

        if ($this->isLocalHostname($host)) {
            throw new UnsafeSiteMonitorTargetException('unsafe_target_host', 'Target host is local or internal.');
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'Target host could not be resolved before the outbound check.');
        }

        foreach ($addresses as $address) {
            if (! SiteMonitorNetworkAddressPolicy::isPublic($address)) {
                throw new UnsafeSiteMonitorTargetException('unsafe_target_address', 'Target host resolves to a private or reserved network address.');
            }
        }
    }

    private function normalizedHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(trim($host, " \t\n\r\0\x0B.[]"));

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
}
