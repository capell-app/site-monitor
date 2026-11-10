# Site Monitor reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/site-monitor`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

Site Monitor is an **Available**, **Schema-owning** Capell package in the **Capell Operations** product group. It ships as `capell-app/site-monitor` and extends these surfaces: admin, console, shared.

- Status: Available
- Tier: free
- Bundle: operations
- Composer package: `capell-app/site-monitor`
- Namespace: `Capell\SiteMonitor`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\SiteMonitor\Providers\MigrationMetadataServiceProvider`
- `Capell\SiteMonitor\Providers\SiteMonitorServiceProvider`
- `Capell\SiteMonitor\Providers\AdminServiceProvider`

### Config files

- `packages/site-monitor/config/capell-site-monitor.php`

### Migrations

- `packages/site-monitor/database/migrations/2026_06_13_000001_create_site_monitor_targets_table.php`
- `packages/site-monitor/database/migrations/2026_06_13_000002_create_site_monitor_runs_table.php`
- `packages/site-monitor/database/migrations/2026_06_13_000003_create_site_monitor_incidents_table.php`
- `packages/site-monitor/database/migrations/2026_10_04_000001_remove_implicit_timestamp_updates_from_site_monitor.php`

### Models

- `RetainsTimestampInstants`
- `SiteMonitorIncident`
- `SiteMonitorRun`
- `SiteMonitorTarget`

### Filament classes

- `SiteMonitorDashboardPage`
- `EditSiteMonitorIncident`
- `ListSiteMonitorIncidents`
- `SiteMonitorIncidentResource`
- `CreateSiteMonitorTarget`
- `EditSiteMonitorTarget`
- `ListSiteMonitorTargets`
- `SiteMonitorTargetResource`

### Policies

- `AbstractSiteMonitorResourcePolicy`
- `SiteMonitorIncidentPolicy`
- `SiteMonitorTargetPolicy`

### Extension contracts

- `SiteMonitorDomainExpiryClient`
- `SiteMonitorHostResolver`
- `SiteMonitorHttpClient`

### Events

- `SiteMonitorIncidentManuallyTransitioned`
- `SiteMonitorIncidentOpened`
- `SiteMonitorIncidentResolved`

### Actions

- `AuthoriseSiteMonitorTargetSiteAction`
- `BuildSiteMonitorDashboardAction`
- `BuildSiteMonitorEvidenceAction`
- `PrepareSiteMonitorScreenshotAction`
- `GuardSiteMonitorOutboundUrlAction`
- `OrderSiteMonitorTimestampsAction`
- `PruneSiteMonitorRunsAction`
- `QueueSiteMonitorTargetCheckAction`
- `ReconcileSiteMonitorIncidentAction`
- `RecordSiteMonitorRunAction`
- `ResolveRdapEndpointAction`
- `ResolveSiteMonitorSocketAddressAction`
- `ResolveSiteMonitorTargetsAction`
- `RunDueSiteMonitorChecksAction`
- `RunSiteMonitorCheckAction`
- `TransitionSiteMonitorIncidentAction`
- `ValidateSiteMonitorStatusRangeAction`

### Data objects

- `SiteMonitorCheckResultData`
- `SiteMonitorDashboardData`
- `SiteMonitorEvidenceData`
- `SiteMonitorIncidentData`
- `SiteMonitorIncidentTransitionData`
- `SiteMonitorTargetData`

### Jobs

- `RunSiteMonitorTargetJob`

### Command signatures

- `capell:site-monitor-screenshot-fixture`
- `capell:site-monitor:doctor`
- `capell:site-monitor:run`

### Manifest action API

- `buildSiteMonitorDashboard: Capell\SiteMonitor\Actions\BuildSiteMonitorDashboardAction`
- `reconcileSiteMonitorIncident: Capell\SiteMonitor\Actions\ReconcileSiteMonitorIncidentAction`
- `recordSiteMonitorRun: Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction`
- `resolveSiteMonitorTargets: Capell\SiteMonitor\Actions\ResolveSiteMonitorTargetsAction`
- `runDueSiteMonitorChecks: Capell\SiteMonitor\Actions\RunDueSiteMonitorChecksAction`
- `runSiteMonitorCheck: Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction`

### Scheduled commands

- `capell:site-monitor:run (everyMinute; package registered)`

### Console command classes

- `RunSiteMonitorCommand`
- `SeedSiteMonitorScreenshotFixtureCommand`
- `SiteMonitorDoctorCommand`

### Manifest contributions

- `admin-page: Capell\SiteMonitor\Manifest\SiteMonitorDashboardPageContribution`
- `admin-resource: Capell\SiteMonitor\Manifest\SiteMonitorIncidentResourceContribution`
- `admin-resource: Capell\SiteMonitor\Manifest\SiteMonitorTargetResourceContribution`
- `console-command: Capell\SiteMonitor\Manifest\SiteMonitorConsoleCommandsContribution`
- `health-check: Capell\SiteMonitor\Manifest\SiteMonitorHealthContribution`
- `migration: Capell\SiteMonitor\Manifest\SiteMonitorMigrationsContribution`
- `model: Capell\SiteMonitor\Manifest\SiteMonitorIncidentModelContribution`
- `model: Capell\SiteMonitor\Manifest\SiteMonitorRunModelContribution`
- `model: Capell\SiteMonitor\Manifest\SiteMonitorTargetModelContribution`
- `scheduled-job: Capell\SiteMonitor\Manifest\SiteMonitorScheduledChecksContribution`

### Health checks

- `Capell\SiteMonitor\Health\SiteMonitorHealthCheck`

### Blade views

- `packages/site-monitor/resources/views/filament/pages/site-monitor-dashboard.blade.php`

### Cache tags

- `site-monitor`


## Data Model

- Required tables: `site_monitor_targets`, `site_monitor_runs`, `site_monitor_incidents`.
- Models: `RetainsTimestampInstants`, `SiteMonitorIncident`, `SiteMonitorRun`, `SiteMonitorTarget`.
- Core record references in migrations: `sites via site_id`, `languages via language_id`.
- Migration files: `2026_06_13_000001_create_site_monitor_targets_table.php`, `2026_06_13_000002_create_site_monitor_runs_table.php`, `2026_06_13_000003_create_site_monitor_incidents_table.php`, `2026_10_04_000001_remove_implicit_timestamp_updates_from_site_monitor.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: migrations declare cascade-on-delete relationships and null-on-delete relationships; no timed pruning or retention schedule is declared in `capell.json`.

<!-- prettier-ignore-end -->
