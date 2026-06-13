<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteDiscovery\Actions\BuildPublicUrlRegistryAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveSiteMonitorTargetsAction
{
    use AsAction;

    /**
     * @return Collection<int, SiteMonitorTarget>
     */
    public function handle(?int $siteId = null, ?int $targetId = null): Collection
    {
        $this->syncSiteDiscoveryTargets();

        return SiteMonitorTarget::query()
            ->where('enabled', true)
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($targetId !== null, fn (Builder $query): Builder => $query->whereKey($targetId))
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', CarbonImmutable::now());
            })
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit($this->integerConfig('capell-site-monitor.batch_size', 50))
            ->get();
    }

    private function syncSiteDiscoveryTargets(): void
    {
        if (! (bool) config('capell-site-monitor.site_discovery_auto_targets_enabled', false)) {
            return;
        }

        if (! class_exists(BuildPublicUrlRegistryAction::class)) {
            return;
        }

        $entries = BuildPublicUrlRegistryAction::run();

        foreach ($entries as $entry) {
            if (! is_object($entry)) {
                continue;
            }

            $canonicalUrl = data_get($entry, 'canonicalUrl');
            $isSitemapEligible = data_get($entry, 'isSitemapEligible');

            if (! is_string($canonicalUrl) || $canonicalUrl === '' || $isSitemapEligible !== true) {
                continue;
            }

            $sourceKey = sha1(implode('|', [
                $canonicalUrl,
                $this->stringValue(data_get($entry, 'siteKey', '')),
                $this->stringValue(data_get($entry, 'languageKey', '')),
            ]));
            $title = data_get($entry, 'title');
            $routeName = data_get($entry, 'routeName');

            SiteMonitorTarget::query()->updateOrCreate(
                [
                    'source_package' => 'capell-app/site-discovery',
                    'source_key' => $sourceKey,
                ],
                [
                    'site_id' => $this->nullableInteger(data_get($entry, 'siteId')),
                    'language_id' => $this->nullableInteger(data_get($entry, 'languageId')),
                    'name' => is_string($title) && $title !== '' ? $title : $canonicalUrl,
                    'url' => $canonicalUrl,
                    'check_type' => SiteMonitorCheckType::HttpStatus,
                    'route_name' => is_string($routeName) ? $routeName : null,
                    'interval_minutes' => $this->integerConfig('capell-site-monitor.default_interval_minutes', 5),
                    'timeout_ms' => $this->integerConfig('capell-site-monitor.default_timeout_ms', 5000),
                    'failure_threshold' => $this->integerConfig('capell-site-monitor.default_failure_threshold', 2),
                    'expected_status_minimum' => 200,
                    'expected_status_maximum' => 399,
                    'enabled' => true,
                    'current_state' => SiteMonitorState::Unknown,
                ],
            );
        }
    }

    private function integerConfig(string $key, int $default): int
    {
        return $this->nullableInteger(config($key)) ?? $default;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }
}
