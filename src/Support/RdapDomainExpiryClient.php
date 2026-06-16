<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Actions\ResolveRdapEndpointAction;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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
            GuardSiteMonitorOutboundUrlAction::run($url);

            $response = Http::timeout(max(1, (int) ceil($this->integerConfig('capell-site-monitor.rdap_timeout_ms', 5000) / 1000)))
                ->acceptJson()
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
