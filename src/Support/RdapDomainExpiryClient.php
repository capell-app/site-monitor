<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Support\Http\OutboundHttpRetry;
use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Actions\ResolveRdapEndpointAction;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class RdapDomainExpiryClient implements SiteMonitorDomainExpiryClient
{
    public function expiresAt(string $domain): ?CarbonImmutable
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $url = ResolveRdapEndpointAction::run($domain);

        if (! is_string($url)) {
            return null;
        }

        try {
            $endpoint = $this->endpoint($url);

            // An RDAP domain lookup is an idempotent read of registry data, and its
            // result does not feed the up/down verdict, so retrying is safe here.
            $response = OutboundHttpRetry::fromConfig('capell-site-monitor.rdap')
                ->apply(
                    Http::timeout(max(1, (int) ceil($this->integerConfig('capell-site-monitor.rdap_timeout_ms', 5000) / 1000)))
                        ->acceptJson()
                        ->withOptions($this->requestOptions($endpoint)),
                )
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $events = $response->json('events');

        if (! is_array($events)) {
            return null;
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $eventAction = $event['eventAction'] ?? null;
            $eventDate = $event['eventDate'] ?? null;

            if ($eventAction !== 'expiration' || ! is_string($eventDate)) {
                continue;
            }

            return CarbonImmutable::parse($eventDate);
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, address: string}
     */
    private function endpoint(string $url): array
    {
        $parts = parse_url($url);

        throw_if(! is_array($parts), InvalidArgumentException::class, 'RDAP endpoint URL could not be parsed.');

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = GuardSiteMonitorOutboundUrlAction::run($url);

        return [
            'host' => $host,
            'port' => $this->port($parts, $scheme),
            'address' => $this->resolvedAddress($host),
        ];
    }

    /**
     * @param  array{host: string, port: int, address: string}  $endpoint
     * @return array<string, mixed>
     */
    private function requestOptions(array $endpoint): array
    {
        throw_unless(defined('CURLOPT_RESOLVE'), InvalidArgumentException::class, 'Site monitor RDAP checks require cURL host pinning support.');

        return [
            'curl' => [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $endpoint['host'], $endpoint['port'], $endpoint['address'])],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function port(array $parts, ?string $scheme): int
    {
        $port = $parts['port'] ?? null;

        if (is_int($port) && $port > 0 && $port <= 65535) {
            return $port;
        }

        return $scheme === 'http' ? 80 : 443;
    }

    private function resolvedAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->guardResolvedAddress($host);

            return $host;
        }

        $addresses = gethostbynamel($host);

        if (is_array($addresses) && $addresses !== []) {
            $address = $addresses[0];

            $this->guardResolvedAddress($address);

            return $address;
        }

        throw new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'RDAP endpoint host could not be resolved before the outbound check.');
    }

    private function guardResolvedAddress(string $address): void
    {
        if (! app()->isProduction() && (bool) config('capell-site-monitor.allow_private_targets', false)) {
            return;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new UnsafeSiteMonitorTargetException('unsafe_target_address', 'RDAP endpoint host resolves to a private or reserved network address.');
        }
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
