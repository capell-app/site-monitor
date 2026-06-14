<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

final class RdapDomainExpiryClient implements SiteMonitorDomainExpiryClient
{
    public function expiresAt(string $domain): ?CarbonImmutable
    {
        $domain = strtolower(trim($domain));
        $topLevelDomain = $this->topLevelDomain($domain);

        if ($domain === '' || $topLevelDomain === null) {
            return null;
        }

        $template = config("capell-site-monitor.rdap_endpoints.{$topLevelDomain}");

        if (! is_string($template) || $template === '') {
            return null;
        }

        $url = str_replace('{domain}', rawurlencode($domain), $template);

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

    private function topLevelDomain(string $domain): ?string
    {
        $parts = explode('.', $domain);
        $lastPart = end($parts);

        return is_string($lastPart) && $lastPart !== '' ? $lastPart : null;
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
