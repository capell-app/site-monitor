<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Capell\Core\Support\Http\OutboundHttpRetry;
use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Actions\ResolveRdapEndpointAction;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Carbon\CarbonImmutable;
use Override;
use Throwable;

final class RdapDomainExpiryClient implements SiteMonitorDomainExpiryClient
{
    /** RDAP domain records are small JSON documents. */
    private const int MAX_RESPONSE_BYTES = 1024 * 1024;

    #[Override]
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
            GuardSiteMonitorOutboundUrlAction::run($url);
            $timeoutSeconds = max(1, (int) ceil($this->integerConfig('capell-site-monitor.rdap_timeout_ms', 5000) / 1000));
            $port = parse_url($url, PHP_URL_PORT);

            // An RDAP domain lookup is an idempotent read of registry data, and its
            // result does not feed the up/down verdict, so retrying is safe here.
            $response = OutboundHttpRetry::fromConfig('capell-site-monitor.rdap')
                ->apply(
                    // Core resolves the registry host, refuses non-public addresses,
                    // pins the connection and follows no redirects.
                    OutboundHttp::guarded(new OutboundHttpPolicy(
                        schemes: ['https', 'http'],
                        ports: [is_int($port) ? $port : (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443)],
                        maxBytes: self::MAX_RESPONSE_BYTES,
                        connectTimeoutSeconds: $timeoutSeconds,
                        timeoutSeconds: $timeoutSeconds,
                    ))->acceptJson(),
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
