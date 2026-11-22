<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Providers;

use Capell\Core\Contracts\Security\SecurityInventoryContributor;
use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\TableName;
use Capell\SiteMonitor\Console\Commands\RunSiteMonitorCommand;
use Capell\SiteMonitor\Console\Commands\SeedSiteMonitorScreenshotFixtureCommand;
use Capell\SiteMonitor\Console\Commands\SiteMonitorDoctorCommand;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Events\SiteMonitorIncidentOpened;
use Capell\SiteMonitor\Events\SiteMonitorIncidentResolved;
use Capell\SiteMonitor\Events\SiteMonitorTargetFlapping;
use Capell\SiteMonitor\Listeners\SendSiteMonitorIncidentAlertListener;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Security\CertificateExpiryWeaknessCheck;
use Capell\SiteMonitor\Security\SiteMonitorSecurityInventoryContributor;
use Capell\SiteMonitor\Support\DnsSiteMonitorHostResolver;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Support\RdapDomainExpiryClient;
use Capell\SiteMonitor\Support\SiteMonitorAlertHttpFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Override;
use Spatie\LaravelPackageTools\Package;

final class SiteMonitorServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-site-monitor';

    public static string $packageName = 'capell-app/site-monitor';

    #[Override]
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
                SeedSiteMonitorScreenshotFixtureCommand::class,
            ])
            ->hasMigrations([
                '2026_06_13_000001_create_site_monitor_targets_table',
                '2026_06_13_000002_create_site_monitor_runs_table',
                '2026_06_13_000003_create_site_monitor_incidents_table',
                '2026_10_04_000001_remove_implicit_timestamp_updates_from_site_monitor',
                '2026_11_20_000001_add_checks_windows_and_flapping_to_site_monitor_targets',
                '2026_11_20_000002_add_alert_snooze_to_site_monitor_targets',
            ]);
    }

    #[Override]
    public function registeringPackage(): void
    {
        parent::registeringPackage();

        $this->app->register(AdminServiceProvider::class);

        $this->app->bind(SiteMonitorHttpClient::class, LaravelSiteMonitorHttpClient::class);
        $this->app->bind(SiteMonitorHostResolver::class, DnsSiteMonitorHostResolver::class);
        $this->app->bind(SiteMonitorDomainExpiryClient::class, RdapDomainExpiryClient::class);
        $this->app->singleton(SiteMonitorAlertHttpFactory::class, static fn (): SiteMonitorAlertHttpFactory => new SiteMonitorAlertHttpFactory);
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([SiteMonitorSecurityInventoryContributor::class], SecurityInventoryContributor::TAG);
        $this->app->tag([CertificateExpiryWeaknessCheck::class], SecurityWeaknessCheck::TAG);

        $this
            ->registerModels()
            ->registerProtectedTables();

        Event::listen([SiteMonitorIncidentOpened::class, SiteMonitorIncidentResolved::class, SiteMonitorTargetFlapping::class], SendSiteMonitorIncidentAlertListener::class);

        if (! (bool) config('capell-site-monitor.schedule_enabled', true)) {
            return;
        }

        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('capell:site-monitor:run')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }

    private function registerModels(): self
    {
        $models = [
            SiteMonitorTarget::class,
            SiteMonitorRun::class,
            SiteMonitorIncident::class,
        ];

        $this->surface()->models($models);

        return $this;
    }

    private function registerProtectedTables(): self
    {
        CapellCore::registerProtectedTable(TableName::resolve('site_monitor_targets'));
        CapellCore::registerProtectedTable(TableName::resolve('site_monitor_runs'));
        CapellCore::registerProtectedTable(TableName::resolve('site_monitor_incidents'));

        return $this;
    }
}
