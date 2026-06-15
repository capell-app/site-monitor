# Site Monitor

<!-- prettier-ignore-start -->

## What This Plugin Adds

Site Monitor is an **Available**, **Schema-owning** Capell package in the **Capell Operations** product group. It ships as `capell-app/site-monitor` and extends these surfaces: admin, console, shared.

External uptime, SSL certificate, domain expiry, and incident monitoring for Capell sites.

After install, operators get package-owned monitor targets, run history, incident tracking, scheduled checks, and a Site Monitor dashboard inside Capell admin.

Status details:

- Status: Available
- Tier: premium
- Bundle: operations
- Composer package: `capell-app/site-monitor`
- Namespace: `Capell\SiteMonitor`
- Theme key: not applicable

## Why It Matters

**For developers:** The package gives developers package-owned service providers, Actions, Data objects, models, Filament classes, and Blade views instead of pushing this behaviour into core or application code.

**For teams:** External uptime, SSL, domain, and incident monitoring for Capell sites.

## Screens And Workflow

Screenshot contract: `screenshots.json`.

- Site Monitor extension card (marketplace, required).
- Site Monitor dashboard (admin, required).
- Site Monitor incident detail (admin, required).

## Technical Shape

- Service providers: `Capell\SiteMonitor\Providers\SiteMonitorServiceProvider`, `Capell\SiteMonitor\Providers\AdminServiceProvider`.
- Config files: `packages/site-monitor/config/capell-site-monitor.php`.
- Migrations: `packages/site-monitor/database/migrations/2026_06_13_000001_create_site_monitor_targets_table.php`, `packages/site-monitor/database/migrations/2026_06_13_000002_create_site_monitor_runs_table.php`, `packages/site-monitor/database/migrations/2026_06_13_000003_create_site_monitor_incidents_table.php`.
- Models: `SiteMonitorIncident`, `SiteMonitorRun`, `SiteMonitorTarget`.
- Filament classes: `SiteMonitorDashboardPage`, `EditSiteMonitorIncident`, `ListSiteMonitorIncidents`, `SiteMonitorIncidentResource`, `CreateSiteMonitorTarget`, `EditSiteMonitorTarget`, `ListSiteMonitorTargets`, `SiteMonitorTargetResource`.
- Actions: `BuildSiteMonitorDashboardAction`, `GuardSiteMonitorOutboundUrlAction`, `PruneSiteMonitorRunsAction`, `ReconcileSiteMonitorIncidentAction`, `RecordSiteMonitorRunAction`, `ResolveSiteMonitorTargetsAction`, `RunDueSiteMonitorChecksAction`, `RunSiteMonitorCheckAction`.
- Data objects: `SiteMonitorCheckResultData`, `SiteMonitorDashboardData`, `SiteMonitorIncidentData`, `SiteMonitorTargetData`.
- Jobs: `RunSiteMonitorTargetJob`.
- Command signatures: `capell:site-monitor:run`, `capell:site-monitor:doctor`.
- Console command classes: `RunSiteMonitorCommand`, `SiteMonitorDoctorCommand`.
- Manifest contributions: `admin-page: Capell\SiteMonitor\Manifest\SiteMonitorDashboardPageContribution`, `admin-resource: Capell\SiteMonitor\Manifest\SiteMonitorIncidentResourceContribution`, `admin-resource: Capell\SiteMonitor\Manifest\SiteMonitorTargetResourceContribution`, `model: Capell\SiteMonitor\Manifest\SiteMonitorIncidentModelContribution`, `model: Capell\SiteMonitor\Manifest\SiteMonitorRunModelContribution`, `model: Capell\SiteMonitor\Manifest\SiteMonitorTargetModelContribution`, `scheduled-job: Capell\SiteMonitor\Manifest\SiteMonitorScheduledChecksContribution`, `queue-job: Capell\SiteMonitor\Manifest\SiteMonitorQueueJobContribution`, `console-command: Capell\SiteMonitor\Manifest\SiteMonitorConsoleCommandsContribution`, `health-check: Capell\SiteMonitor\Manifest\SiteMonitorHealthContribution`.
- Health checks: `Capell\SiteMonitor\Health\SiteMonitorHealthCheck`.
- Blade views: `packages/site-monitor/resources/views/filament/pages/site-monitor-dashboard.blade.php`.
- Cache tags: `site-monitor`.

## Data Model

- Required tables: `site_monitor_targets`, `site_monitor_runs`, `site_monitor_incidents`.
- Models: `SiteMonitorIncident`, `SiteMonitorRun`, `SiteMonitorTarget`.
- Migration files: `2026_06_13_000001_create_site_monitor_targets_table.php`, `2026_06_13_000002_create_site_monitor_runs_table.php`, `2026_06_13_000003_create_site_monitor_incidents_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: `capell:site-monitor:run` prunes old monitor runs using `run_retention_days` while preserving latest target runs and incident-linked evidence.

## Install Impact

- Availability: Available.
- Admin navigation: adds package-owned Filament classes when registered.
- Permissions: `View:SiteMonitorTarget`, `Create:SiteMonitorTarget`, `Update:SiteMonitorTarget`, `Delete:SiteMonitorTarget`, `View:SiteMonitorIncident`, `Update:SiteMonitorIncident`.
- Public routes: none detected in package route files.
- Database changes: package migrations are declared.
- Settings: no package settings declared.
- Queues or schedules: schedules `capell:site-monitor:run` every minute when enabled; due target checks dispatch `RunSiteMonitorTargetJob` unless the command is run with `--sync`.
- Cache tags: `site-monitor`.
- Commands: `capell:site-monitor:run`, `capell:site-monitor:doctor`.

## Common Pitfalls

- Keep scheduler and queue workers running in production, or run `capell:site-monitor:run --sync` for focused verification.
- Keep monitor targets restricted to public URLs; outbound checks reject private, reserved, loopback, and local targets.
- Keep screenshot and workflow notes current when real package screens are recaptured.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Monitor targets are not checked | Scheduler or queue worker is not running | Check `capell:site-monitor:run`, queue workers, and stale-target health checks | Start the scheduler/queue worker or run `capell:site-monitor:run --sync` for verification |
| Target URL is rejected | Outbound safety guard blocked a private, reserved, loopback, or local address | Check the target URL and redirects | Use a public URL or adjust non-production guard config deliberately |

## Quick Start

1. Install the package: `composer require capell-app/site-monitor`.
2. Run host migrations through the installed Capell app.
3. Open the Site Monitor admin dashboard, add public monitor targets, and verify `capell:site-monitor:run --sync` records checks.

## Next Steps

- [Package docs index](README.md)
- [Screenshot contract](screenshots.json)
- [Marketplace assets](assets/marketplace/)
- [Capell content language plan](../../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../../docs/erd/capell-and-package-erds.md)
- Related packages: [Diagnostics](../../diagnostics/README.md), [Email Studio](../../email-studio/README.md), [Exception Reports](../../exception-reports/README.md), [Seo Suite](../../seo-suite/README.md), [Site Discovery](../../site-discovery/README.md), [Url Manager](../../url-manager/README.md).
- Focused tests: `vendor/bin/pest packages/site-monitor/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
