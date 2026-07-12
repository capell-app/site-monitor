<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionAdminResource;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\SiteMonitor\Console\Commands\RunSiteMonitorCommand;
use Capell\SiteMonitor\Console\Commands\SiteMonitorDoctorCommand;
use Capell\SiteMonitor\Events\SiteMonitorIncidentOpened;
use Capell\SiteMonitor\Events\SiteMonitorIncidentResolved;
use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Capell\SiteMonitor\Health\SiteMonitorHealthCheck;
use Capell\SiteMonitor\Manifest\SiteMonitorConsoleCommandsContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorDashboardPageContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorHealthContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorIncidentModelContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorIncidentResourceContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorRunModelContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorScheduledChecksContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorTargetModelContribution;
use Capell\SiteMonitor\Manifest\SiteMonitorTargetResourceContribution;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;

it('declares the implemented package surfaces in capell manifest', function (): void {
    $manifest = capell_json_file_array(__DIR__ . '/../../capell.json');
    $contributes = data_get($manifest, 'contributes');

    throw_unless(is_array($contributes), RuntimeException::class, 'Expected Site Monitor manifest contributions.');

    expect(data_get($manifest, 'providers.runtime', []))->toContain('Capell\\SiteMonitor\\Providers\\SiteMonitorServiceProvider')
        ->and(data_get($manifest, 'providers.admin', []))->toContain('Capell\\SiteMonitor\\Providers\\AdminServiceProvider')
        ->and(data_get($manifest, 'database.migrations'))->toBeTrue()
        ->and(data_get($manifest, 'commands.run'))->toBe('capell:site-monitor:run')
        ->and(data_get($manifest, 'commands.doctor'))->toBe('capell:site-monitor:doctor')
        ->and(data_get($manifest, 'capabilities', []))->toContain('incident-notification-events')
        ->and(data_get($manifest, 'healthChecks', []))->not->toBeEmpty()
        ->and(data_get($manifest, 'security.publicSurface.routeNames', []))->toBe([])
        ->and(data_get($manifest, 'contributionTraceability.deferredContributions'))->toBe([]);

    expect($contributes)->toContain([
        'type' => 'admin-page',
        'class' => SiteMonitorDashboardPageContribution::class,
        'pageClass' => SiteMonitorDashboardPage::class,
        'labelKey' => 'capell-site-monitor::package.navigation.dashboard',
    ])
        ->and($contributes)->toContain([
            'type' => 'admin-resource',
            'class' => SiteMonitorTargetResourceContribution::class,
            'resourceClass' => SiteMonitorTargetResource::class,
        ])
        ->and($contributes)->toContain([
            'type' => 'admin-resource',
            'class' => SiteMonitorIncidentResourceContribution::class,
            'resourceClass' => SiteMonitorIncidentResource::class,
        ])
        ->and($contributes)->toContain([
            'type' => 'model',
            'class' => SiteMonitorTargetModelContribution::class,
            'modelClasses' => [SiteMonitorTarget::class],
        ])
        ->and($contributes)->toContain([
            'type' => 'model',
            'class' => SiteMonitorRunModelContribution::class,
            'modelClasses' => [SiteMonitorRun::class],
        ])
        ->and($contributes)->toContain([
            'type' => 'model',
            'class' => SiteMonitorIncidentModelContribution::class,
            'modelClasses' => [SiteMonitorIncident::class],
        ])
        ->and($contributes)->toContain([
            'type' => 'scheduled-job',
            'class' => SiteMonitorScheduledChecksContribution::class,
            'command' => 'capell:site-monitor:run',
            'frequency' => 'everyMinute',
        ])
        ->and($contributes)->toContain([
            'type' => 'console-command',
            'class' => SiteMonitorConsoleCommandsContribution::class,
            'commands' => [
                'capell:site-monitor:run',
                'capell:site-monitor:doctor',
            ],
            'commandClasses' => [
                RunSiteMonitorCommand::class,
                SiteMonitorDoctorCommand::class,
            ],
        ])
        ->and($contributes)->toContain([
            'type' => 'health-check',
            'class' => SiteMonitorHealthContribution::class,
            'checkClass' => SiteMonitorHealthCheck::class,
        ]);

    foreach ($contributes as $contribution) {
        throw_unless(is_array($contribution), RuntimeException::class, 'Expected Site Monitor manifest contribution to be an array.');

        $class = $contribution['class'] ?? null;

        expect(is_string($class) ? class_implements($class) : [])->toContain(ExtensionContribution::class);
    }

    expect(class_implements(SiteMonitorTargetResourceContribution::class))->toContain(RegistersExtensionAdminResource::class)
        ->and(class_implements(SiteMonitorIncidentResourceContribution::class))->toContain(RegistersExtensionAdminResource::class)
        ->and(class_implements(SiteMonitorScheduledChecksContribution::class))->toContain(RunsScheduledExtensionJob::class)
        ->and(class_implements(SiteMonitorHealthContribution::class))->toContain(ChecksExtensionHealth::class)
        ->and(class_exists(SiteMonitorIncidentOpened::class))->toBeTrue()
        ->and(class_exists(SiteMonitorIncidentResolved::class))->toBeTrue();
});
