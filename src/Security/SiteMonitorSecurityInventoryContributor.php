<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Security;

use Capell\Core\Contracts\Security\SecurityInventoryContributor;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Override;

final class SiteMonitorSecurityInventoryContributor implements SecurityInventoryContributor
{
    #[Override]
    public function prefix(): string
    {
        return 'site_monitor';
    }

    #[Override]
    public function flags(): array
    {
        $now = CarbonImmutable::now();
        $configuredWarningDays = config('capell-site-monitor.ssl_expiry_warning_days', 30);
        $warningDays = max(0, is_numeric($configuredWarningDays) ? (int) $configuredWarningDays : 30);
        $flags = ['warning_days' => $warningDays];
        $count = 0;
        foreach (SiteMonitorTarget::query()->where('enabled', true)->where('check_type', SiteMonitorCheckType::SslCertificate->value)->with('latestRun')->orderBy('id')->get() as $target) {
            $run = $target->latestRun;
            $checked = $run?->checked_at;
            $expiry = $run?->expires_at;
            $observation = $checked === null || $expiry === null ? 'missing' : ($checked->isAfter($now) || $checked->lessThan($now->subMinutes(max(1, $target->interval_minutes) * 2)) ? 'stale' : 'fresh');
            $targetId = $target->getKey();
            $flags["target_{$count}_id"] = is_numeric($targetId) ? (int) $targetId : 0;
            $flags["target_{$count}_checked_at"] = $checked?->toIso8601String();
            $flags["target_{$count}_expires_at"] = $expiry?->toIso8601String();
            $flags["target_{$count}_observation"] = $observation;
            $flags["target_{$count}_expired"] = $expiry?->lessThanOrEqualTo($now);
            $flags["target_{$count}_approaching"] = $expiry?->lessThanOrEqualTo($now->addDays($warningDays));
            $count++;
        }
        $flags['target_count'] = $count;

        return $flags;
    }
}
