<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveRdapEndpointAction
{
    use AsAction;

    public function handle(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $configuredEndpoints = config('capell-site-monitor.rdap_endpoints', []);

        if (! is_array($configuredEndpoints)) {
            return null;
        }

        $suffixes = array_values(array_filter(array_keys($configuredEndpoints), is_string(...)));
        usort($suffixes, fn (string $firstSuffix, string $secondSuffix): int => substr_count($secondSuffix, '.') <=> substr_count($firstSuffix, '.')
                ?: strlen($secondSuffix) <=> strlen($firstSuffix));

        foreach ($suffixes as $suffix) {
            if (! $this->domainMatchesSuffix($domain, $suffix)) {
                continue;
            }

            $template = $configuredEndpoints[$suffix] ?? null;

            if (! is_string($template) || $template === '') {
                continue;
            }

            return str_replace('{domain}', rawurlencode($domain), $template);
        }

        return null;
    }

    private function domainMatchesSuffix(string $domain, string $suffix): bool
    {
        $suffix = strtolower(trim($suffix, ". \t\n\r\0\x0B"));

        return $suffix !== '' && ($domain === $suffix || str_ends_with($domain, ".{$suffix}"));
    }
}
