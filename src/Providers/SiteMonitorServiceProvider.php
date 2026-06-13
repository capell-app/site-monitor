<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Providers;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\SiteMonitor\Console\Commands\RunSiteMonitorCommand;
use Capell\SiteMonitor\Console\Commands\SiteMonitorDoctorCommand;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Support\RdapDomainExpiryClient;
use Illuminate\Console\Scheduling\Schedule;
use Override;
use Spatie\LaravelPackageTools\Package;

final class SiteMonitorServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-site-monitor';

    public static string $packageName = 'capell-app/site-monitor';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile(self::$name)
            ->hasTranslations()
            ->hasViews(self::$name)
            ->hasCommands([
                RunSiteMonitorCommand::class,
                SiteMonitorDoctorCommand::class,
            ])
            ->hasMigrations([
                '2026_06_13_000001_create_site_monitor_targets_table',
                '2026_06_13_000002_create_site_monitor_runs_table',
                '2026_06_13_000003_create_site_monitor_incidents_table',
            ]);
    }

    public function registeringPackage(): void
    {
        $this->app->register(AdminServiceProvider::class);

        $this->app->bind(SiteMonitorHttpClient::class, LaravelSiteMonitorHttpClient::class);
        $this->app->bind(SiteMonitorDomainExpiryClient::class, RdapDomainExpiryClient::class);
    }

    public function packageBooted(): void
    {
        if (! $this->isPackageInstalled()) {
            return;
        }

        $this
            ->registerModels()
            ->registerProtectedTables();

        if (! (bool) config('capell-site-monitor.schedule_enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('capell:site-monitor:run')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }

    #[Override]
    protected function isPackageInstalled(): bool
    {
        return CapellCore::isPackageInstalled(self::$packageName);
    }

    private function registerModels(): self
    {
        CapellCore::registerModels([
            SiteMonitorTarget::class,
            SiteMonitorRun::class,
            SiteMonitorIncident::class,
        ]);

        return $this;
    }

    private function registerProtectedTables(): self
    {
        CapellCore::registerProtectedTable('site_monitor_targets');
        CapellCore::registerProtectedTable('site_monitor_runs');
        CapellCore::registerProtectedTable('site_monitor_incidents');

        return $this;
    }
}
