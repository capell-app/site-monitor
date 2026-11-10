<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Providers;

use Capell\Core\Support\Migration\MigrationRootRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class MigrationMetadataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {

        $this->callAfterResolving(MigrationRootRegistry::class, static function (MigrationRootRegistry $registry): void {
            $registry->register('capell-app/site-monitor', __DIR__ . '/../../database/migrations', [
                '2026_06_13_000001_create_site_monitor_targets_table' => 'site_monitor_targets',
                '2026_06_13_000002_create_site_monitor_runs_table' => 'site_monitor_runs',
                '2026_06_13_000003_create_site_monitor_incidents_table' => 'site_monitor_incidents',
            ]);
        });
    }
}
