<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\SiteMonitor\Console\Commands\RunSiteMonitorCommand;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SiteMonitorHealthCheck implements ChecksExtensionHealth
{
    private const string STORAGE_KEY = 'site-monitor.storage';

    private const string COMMAND_KEY = 'site-monitor.command';

    private const string TARGET_FRESHNESS_KEY = 'site-monitor.target-freshness';

    private const string INCIDENTS_KEY = 'site-monitor.open-incidents';

    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(?string $key = null): Collection
    {
        $check = new self;
        $assertions = collect([
            self::STORAGE_KEY => $check->storageCheck(),
            self::COMMAND_KEY => $check->commandCheck(),
            self::TARGET_FRESHNESS_KEY => $check->targetFreshnessCheck(),
            self::INCIDENTS_KEY => $check->openIncidentsCheck(),
        ]);

        if ($key === null) {
            return $assertions->values();
        }

        $assertion = $assertions->get($key);

        return $assertion instanceof DoctorCheckResultData
            ? collect([$assertion])
            : collect();
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    private function storageCheck(): DoctorCheckResultData
    {
        $missingTables = collect([
            'site_monitor_targets',
            'site_monitor_runs',
            'site_monitor_incidents',
        ])
            ->reject(static fn (string $table): bool => Schema::hasTable($table))
            ->values()
            ->all();

        return new DoctorCheckResultData(
            label: (string) __('capell-site-monitor::package.health.storage.label'),
            passed: $missingTables === [],
            message: $missingTables === []
                ? (string) __('capell-site-monitor::package.health.storage.passed')
                : (string) __('capell-site-monitor::package.health.storage.failed', [
                    'tables' => implode(', ', $missingTables),
                ]),
            remediation: $missingTables === []
                ? null
                : (string) __('capell-site-monitor::package.health.storage.remediation'),
        );
    }

    private function commandCheck(): DoctorCheckResultData
    {
        $available = class_exists(RunSiteMonitorCommand::class);

        return new DoctorCheckResultData(
            label: (string) __('capell-site-monitor::package.health.command.label'),
            passed: $available,
            message: $available
                ? (string) __('capell-site-monitor::package.health.command.passed')
                : (string) __('capell-site-monitor::package.health.command.failed'),
            remediation: $available ? null : (string) __('capell-site-monitor::package.health.command.remediation'),
        );
    }

    private function targetFreshnessCheck(): DoctorCheckResultData
    {
        try {
            $enabledTargets = SiteMonitorTarget::query()->where('enabled', true)->count();
            $staleTargets = $enabledTargets === 0 ? 0 : $this->staleTargetCount();
        } catch (Throwable) {
            $enabledTargets = 0;
            $staleTargets = 1;
        }

        return new DoctorCheckResultData(
            label: (string) __('capell-site-monitor::package.health.target_freshness.label'),
            passed: $staleTargets === 0,
            message: $enabledTargets === 0
                ? (string) __('capell-site-monitor::package.health.target_freshness.empty')
                : ($staleTargets === 0
                    ? (string) __('capell-site-monitor::package.health.target_freshness.passed', ['count' => $enabledTargets])
                    : (string) __('capell-site-monitor::package.health.target_freshness.failed', ['count' => $staleTargets])),
            remediation: $staleTargets === 0
                ? null
                : (string) __('capell-site-monitor::package.health.target_freshness.remediation'),
        );
    }

    private function openIncidentsCheck(): DoctorCheckResultData
    {
        try {
            $count = SiteMonitorIncident::query()
                ->where('status', SiteMonitorIncidentStatus::Open->value)
                ->count();
        } catch (Throwable) {
            $count = 1;
        }

        return new DoctorCheckResultData(
            label: (string) __('capell-site-monitor::package.health.incidents.label'),
            passed: $count === 0,
            message: $count === 0
                ? (string) __('capell-site-monitor::package.health.incidents.passed')
                : (string) trans_choice('capell-site-monitor::package.health.incidents.failed', $count, ['count' => $count]),
            remediation: $count === 0
                ? null
                : (string) __('capell-site-monitor::package.health.incidents.remediation'),
        );
    }

    private function staleTargetCount(): int
    {
        $cutoff = CarbonImmutable::now()
            ->subMinutes($this->integerConfig('capell-site-monitor.max_stale_minutes', 30));

        return SiteMonitorTarget::query()
            ->where('enabled', true)
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $cutoff);
            })
            ->count();
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
