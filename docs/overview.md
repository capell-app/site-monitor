# Site Monitor

<!-- prettier-ignore-start -->

## What This Plugin Adds

Site Monitor is a **Pipeline**, **Schema-owning** Capell package in the **Capell Operations** product group. It is tracked as `capell-app/site-monitor` and plans these surfaces: admin, console, shared.

External uptime, SSL certificate, domain expiry, and incident monitoring for Capell sites.

This package is not documented as shipped. Treat screens, workflows, providers, and install behaviour as review-required until certification review and host install verification are complete.

Status details:

- Status: Pipeline
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
- Command signatures: `capell:site-monitor:doctor`, `capell:site-monitor:run`.
- Console command classes: `RunSiteMonitorCommand`, `SiteMonitorDoctorCommand`.
- Manifest contributions: `admin-page: Capell\SiteMonitor\Manifest\SiteMonitorDashboardPageContribution`, `admin-resource: Capell\SiteMonitor\Manifest\SiteMonitorIncidentResourceContribution`, `admin-resource: Capell\SiteMonitor\Manifest\SiteMonitorTargetResourceContribution`, `console-command: Capell\SiteMonitor\Manifest\SiteMonitorConsoleCommandsContribution`, `health-check: Capell\SiteMonitor\Manifest\SiteMonitorHealthContribution`, `model: Capell\SiteMonitor\Manifest\SiteMonitorIncidentModelContribution`, `model: Capell\SiteMonitor\Manifest\SiteMonitorRunModelContribution`, `model: Capell\SiteMonitor\Manifest\SiteMonitorTargetModelContribution`, `scheduled-job: Capell\SiteMonitor\Manifest\SiteMonitorScheduledChecksContribution`.
- Health checks: `Capell\SiteMonitor\Health\SiteMonitorHealthCheck`.
- Blade views: `packages/site-monitor/resources/views/filament/pages/site-monitor-dashboard.blade.php`.
- Cache tags: `site-monitor`.

## Data Model

- Required tables: `site_monitor_targets`, `site_monitor_runs`, `site_monitor_incidents`.
- Models: `SiteMonitorIncident`, `SiteMonitorRun`, `SiteMonitorTarget`.
- Migration files: `2026_06_13_000001_create_site_monitor_targets_table.php`, `2026_06_13_000002_create_site_monitor_runs_table.php`, `2026_06_13_000003_create_site_monitor_incidents_table.php`.
- Migration impact: review required; migration files exist and must be verified through the host install flow.
- Deletion/retention behaviour: Docs gap unless the package has an explicit pruning command, retention setting, or tested cascade path.

## Install Impact

- Availability: Pipeline; review required before installing in a host Capell app.
- Admin navigation: adds package-owned Filament classes when registered.
- Permissions: `View:SiteMonitorTarget`, `Create:SiteMonitorTarget`, `Update:SiteMonitorTarget`, `Delete:SiteMonitorTarget`, `View:SiteMonitorIncident`, `Update:SiteMonitorIncident`.
- Public routes: none detected in package route files.
- Database changes: package migrations are declared.
- Settings: no package settings declared.
- Queues or schedules: review package jobs or schedules before install.
- Cache tags: `site-monitor`.
- Commands: `capell:site-monitor:doctor`, `capell:site-monitor:run`.

## Common Pitfalls

- Do not describe review-required providers, routes, migrations, commands, or admin resources as shipped install behaviour.
- Keep class references out of `capell.json` until those classes exist and focused tests pass.
- Keep screenshot and workflow notes labelled as review-required until real package screens are captured or verified.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package tests fail before assertions | Manifest or provider metadata references classes that do not exist yet | Check `capell.json`, package `composer.json`, and provider imports | Remove planned class references or add the classes and focused tests before marking the package Available |
| Docs describe installable behaviour | Pipeline docs drifted into shipped-language claims | Check `status`, `Install Impact`, and `Quick Start` | Keep the package labelled Pipeline until the install flow is verified |

## Quick Start

1. Review `capell.json`, package docs, and any scaffolded source before treating the package as installable.
2. Complete certification review, host install verification, migrations, and admin surface checks.
3. Run the docs checks, focused package tests, and install verification before changing the status from Pipeline to Available.

## Next Steps

- [Package docs index](README.md)
- [Screenshot contract](screenshots.json)
- [Marketplace assets](assets/marketplace/)
- [Capell content language plan](../../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../../docs/erd/capell-and-package-erds.md)
- Related packages: [Diagnostics](../../diagnostics/README.md), [Email Studio](../../email-studio/README.md), [Exception Reports](../../exception-reports/README.md), [Seo Suite](../../seo-suite/README.md), [Site Discovery](../../site-discovery/README.md), [Url Manager](../../url-manager/README.md).
- Docs gap: finish certification review and host install verification before marking this package Available.

<!-- prettier-ignore-end -->
