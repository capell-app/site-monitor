# Site Monitor

<!-- prettier-ignore-start -->

## What This Plugin Adds

Site Monitor is an **Available**, **Schema-owning** Capell package in the **Capell Operations** product group. It is tracked as `capell-app/site-monitor` and ships admin, console, queue, health, and shared runtime surfaces.

External uptime, SSL certificate, domain expiry, and incident monitoring for Capell sites.

The package ships scheduled external checks, incident tracking, run retention, outbound target safety, and configurable RDAP domain-expiry lookups. Marketplace screenshot certification is still pending runner-backed captures.

Status details:

- Status: Available
- Tier: premium
- Bundle: operations
- Composer package: `capell-app/site-monitor`
- Namespace: `Capell\SiteMonitor`
- Theme key: not applicable

## Why It Matters

**For developers:** The package gives developers package-owned service providers, Actions, Data objects, models, Filament classes, jobs, health checks, and swappable HTTP/RDAP clients instead of pushing this behaviour into core or application code.

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
- Actions: `BuildSiteMonitorDashboardAction`, `GuardSiteMonitorOutboundUrlAction`, `PruneSiteMonitorRunsAction`, `ReconcileSiteMonitorIncidentAction`, `RecordSiteMonitorRunAction`, `ResolveRdapEndpointAction`, `ResolveSiteMonitorTargetsAction`, `RunDueSiteMonitorChecksAction`, `RunSiteMonitorCheckAction`.
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
- Deletion/retention behaviour: scheduled checks prune old runs with `run_retention_days` while preserving each target's latest run and incident-linked evidence.

## Install Impact

- Availability: Available; requires the scheduler and queue worker for normal operation.
- Admin navigation: adds package-owned Filament classes when registered.
- Permissions: `View:SiteMonitorTarget`, `Create:SiteMonitorTarget`, `Update:SiteMonitorTarget`, `Delete:SiteMonitorTarget`, `View:SiteMonitorIncident`, `Update:SiteMonitorIncident`.
- Public routes: none detected in package route files.
- Database changes: package migrations are declared.
- Settings: no package settings declared.
- Queues or schedules: schedules `capell:site-monitor:run` every minute when installed and dispatches `RunSiteMonitorTargetJob` for due targets by default.
- Cache tags: `site-monitor`.
- Commands: `capell:site-monitor:doctor`, `capell:site-monitor:run`.

## Common Pitfalls

- Do not run checks against private, loopback, link-local, or reserved targets unless explicitly enabling private targets in a non-production environment.
- Keep class references out of `capell.json` until those classes exist and focused tests pass.
- Keep screenshot and workflow notes labelled as review-required until real package screens are captured or verified.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Domain expiry is unavailable | The domain suffix has no configured RDAP endpoint or the registry response has no expiration event | Check `capell-site-monitor.rdap_endpoints` and the run error type | Add a suffix-specific endpoint template such as `co.uk => https://rdap.nominet.uk/uk/domain/{domain}` |
| Checks stay stale | Scheduler or queue worker is not running | Run `capell:site-monitor:doctor` and inspect the stale-target health check | Start the scheduler and queue worker, or run `capell:site-monitor:run --sync` for verification |

## Quick Start

1. Install the package migrations and confirm the package is enabled in the host Capell app.
2. Run the scheduler and a queue worker, then use `capell:site-monitor:run --sync` for a direct verification pass.
3. Configure `capell-site-monitor.rdap_endpoints` for any additional domain suffixes your operators monitor.

## Next Steps

- [Package docs index](README.md)
- [Screenshot contract](screenshots.json)
- [Marketplace assets](assets/marketplace/)
- [Capell content language plan](../../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../../docs/erd/capell-and-package-erds.md)
- Related packages: [Diagnostics](../../diagnostics/README.md), [Email Studio](../../email-studio/README.md), [Exception Reports](../../exception-reports/README.md), [Seo Suite](../../seo-suite/README.md), [Site Discovery](../../site-discovery/README.md), [Url Manager](../../url-manager/README.md).
- Docs gap: replace SVG marketplace previews with runner-backed admin screenshots before certification.

<!-- prettier-ignore-end -->
