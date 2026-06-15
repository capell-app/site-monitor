# Site Monitor - Improvement & Growth Plan

> Package: capell-app/site-monitor · Kind: package · Tier: premium · Product group: Capell Operations · Bundle: operations · Status: Active

## 1. Snapshot

Site Monitor is a premium operations package for external uptime, SSL certificate, domain expiry, and incident monitoring. It owns three tables (`site_monitor_targets`, `site_monitor_runs`, `site_monitor_incidents`), a scheduled command (`capell:site-monitor:run`), a doctor command (`capell:site-monitor:doctor`), queued target checks, two Filament resources (targets and incidents), and an admin dashboard page. Domain behavior is Action-backed: target discovery/resolution, external check execution, run recording, incident reconciliation, and dashboard aggregation. The package binds swappable HTTP and RDAP clients and can optionally create HTTP targets from Site Discovery's public URL registry. Functional tests already cover check execution, due-target resolution, run command behavior, incidents, health checks, and admin surface registration. Current README/overview still label the package as Pipeline even though the code is substantially shipped, and marketplace media remains SVG preview art rather than real Capell runner screenshots.

## 2. Improvements (existing functionality)

1. **Add outbound URL safety before treating this as production-ready.** `LaravelSiteMonitorHttpClient` fetches arbitrary target URLs, follows redirects, and `RunSiteMonitorCheckAction` opens raw SSL sockets to parsed hosts. Monitoring public URLs is the package's job, but admin-entered targets still need an SSRF/private-network guard and redirect revalidation so checks cannot hit localhost, metadata IPs, RFC1918 ranges, or internal hostnames unless explicitly allowed in non-production. Evidence: `LaravelSiteMonitorHttpClient`, `RunSiteMonitorCheckAction::sslCertificateExpiresAt()`, `SiteMonitorTargetResource` URL field. - **M** - **Done 2026-06-14:** `GuardSiteMonitorOutboundUrlAction` now blocks private/reserved/local targets before HTTP, SSL, domain, and RDAP checks; the HTTP client follows redirects manually and revalidates each hop.

2. **Update docs/status from generated Pipeline language to current shipped behavior.** README and overview say "Pipeline", "not documented as shipped", and "review-required" despite existing migrations, resources, commands, tests, and manifest contributions. Refresh docs to Available after the focused verification pass, and document the real install impact, schedule, queue, retention, external HTTP safety, and operator workflows. Evidence: `README.md`, `docs/overview.md`. - **S** - **Done 2026-06-14:** README, overview, and docs index now describe Available behavior, scheduler/queue requirements, retention, and outbound safety.

3. **Replace SVG preview marketplace media with runner-backed screenshots.** `capell.json` promotes `dashboard.svg` and `incident.svg`; `docs/screenshots.json` uses marketplace-asset entries rather than route-backed admin captures. Capture and promote the Site Monitor dashboard, target list/form, and incident detail from the Capell runner before certification. - **S**

4. **Implement run retention for the documented config.** `config/capell-site-monitor.php` defines `run_retention_days`, but no pruning Action, command, or scheduled cleanup is visible. Add `PruneSiteMonitorRunsAction` and command/schedule integration or remove the config until it is used. Evidence: config, `SiteMonitorRun` model, provider schedule. - **M** - **Done 2026-06-14:** `PruneSiteMonitorRunsAction` prunes old runs from the scheduled command while preserving latest target runs and incident-linked evidence.

5. **Make schedule and queue behavior operationally explicit.** `SiteMonitorServiceProvider` schedules `capell:site-monitor:run` every minute when installed and enabled; `RunDueSiteMonitorChecksAction` dispatches one job per target by default. Docs should tell operators this requires a scheduler and queue worker, and health checks should surface stale targets when the scheduler/queue is not running. Evidence: provider schedule, `RunDueSiteMonitorChecksAction`, `RunSiteMonitorTargetJob`, `SiteMonitorHealthCheck`. - **S** - **Done 2026-06-14:** docs now call out the scheduler, queue worker, `--sync` verification path, and stale-target health signal.

6. **Add query-budget coverage for the dashboard aggregation.** `BuildSiteMonitorDashboardAction` issues several aggregate queries and loads the last 100 response times. This is reasonable, but the manifest declares an admin query budget of 20 without a focused guard. Add a test to pin query count and keep future dashboard cards from turning the operations page into an unbounded query surface. - **S**

7. **Shipped 2026-06-15: package operations metadata is complete.** `capell.json` now declares the run command, console-command/health-check/queue-job contributions, the queued target job, and no deferred contribution types. The provider also registers Site Monitor models through the facade-visible core registry, and README/overview now match the verified Available package behavior. `capell.json`, `src/Manifest/*Contribution.php`, `src/Providers/SiteMonitorServiceProvider.php`, `tests/Unit/ManifestRequirementsTest.php`, `README.md`, `docs/overview.md`. - **S**

## 3. Missing Features (gaps)

Capabilities declared: site monitoring, uptime, SSL expiry, domain expiry, incident tracking, scheduled checks, and operations admin.

- **No notification channel yet.** Incidents are recorded, but there is no Email Studio, Slack/webhook, or admin notification bridge for new incidents/resolutions. This is table-stakes for monitoring.
- **No acknowledgement/ownership workflow.** Incidents can be open/resolved, but there is no operator acknowledgement, assignee, notes timeline, or escalation state.
- **No status page or digest output.** The package is admin-only. A customer-facing or internal status summary, even if optional, would turn monitoring data into operational communication.
- **Domain monitoring is TLD-limited.** RDAP endpoints are configured for `.com`, `.net`, and `.org`. Other TLDs silently return unavailable unless operators add config.
- **No per-target retry/backoff beyond failure thresholds.** The scheduler checks due targets; there is no jitter, retry-after handling, per-host concurrency limit, or cooldown after repeated failures.

## 4. Issues / Risks

1. **Critical risk: external checks can be used as SSRF if target URLs are not restricted.** The package deliberately makes outbound requests, so it must resolve and block private/loopback/link-local hosts before each request and after redirects. Recommended fix: centralize target URL validation/resolution and cover HTTP, SSL, and RDAP paths with private-IP tests. - **P1**

2. **Important gap: docs understate a shipped package and overstate review-required status.** This can block adoption and confuse operators. Recommended fix: update docs after first safety slice and package verification. - **P2**

3. **Important gap: retention config is unused.** Run tables can grow indefinitely for active sites. Recommended fix: prune old runs on command schedule while preserving latest run and incident-linked evidence. - **P2**

4. **Important gap: no alert delivery makes incident tracking passive.** Operators must open the dashboard to know a target is down. Recommended fix: add a notification seam, starting with Email Studio/support event dispatch if installed. - **P2**

5. **Improvement: marketplace media does not prove the real admin UI.** SVG previews are acceptable placeholders, not certification evidence. Recommended fix: capture real runner screenshots. - **P3**

## 5. Marketplace & Positioning

Site Monitor belongs in `Capell Operations` as the operations counterpart to Diagnostics and Exception Reports. For site owners/operators, the outcome is early warning when public pages, certificates, or domains are failing. For developers, the value is package-owned monitor targets, check clients, scheduled jobs, and incident records rather than bespoke cron scripts.

**Current summary:** "External uptime, SSL, domain, and incident monitoring for Capell sites."

**Improved summary:** "Monitor Capell sites from the outside: uptime checks, SSL and domain expiry warnings, and incident history for operators."

**Improved description:** "Site Monitor runs scheduled checks against public URLs, certificates, and domain expiry records, then stores every run and incident in Capell admin. Operators can review target state, open incidents, response times, and failure evidence without adding custom cron code to each site. Swappable HTTP and RDAP clients make the monitor testable and host-configurable, while Site Discovery can seed public URL targets when enabled. Built for teams that want operational visibility beside their CMS, not buried in external spreadsheets."

**Media status:** Static SVG dashboard/incident previews should be replaced with runner-backed admin dashboard, target, and incident screenshots before marking the package complete.

**Cross-sell:** Diagnostics should surface package health. Email Studio should send incident/resolution notifications. Exception Reports provides runtime error context. Site Discovery can seed URL targets. SEO Suite and URL Manager can consume uptime/canonical-route evidence.

**Keywords/tags:** `site-monitor`, `uptime`, `ssl-expiry`, `domain-expiry`, `incidents`, `operations`, `scheduler`, `queue`, `diagnostics`, `site-discovery`, `status`.

## 6. Prioritized Roadmap

| Item                                                                                                                                                           | Bucket | Effort | Impact | Section ref |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ------ | ------ | ----------- |
| Add SSRF/private-network protection for HTTP, redirect, SSL, and domain-check targets                                                                          | Done   | M      | High   | §2.1, §4.1  |
| Refresh README/overview from Pipeline to verified Available behavior                                                                                           | Done   | S      | Medium | §2.2, §4.2  |
| Add run-retention pruning using `run_retention_days` while preserving evidence                                                                                 | Done   | M      | Medium | §2.4, §4.3  |
| Document scheduler/queue operations and surface stale-target health clearly                                                                                    | Done   | S      | Medium | §2.5        |
| Capture and promote real runner-backed admin screenshots                                                                                                       | Next   | S      | High   | §2.3, §5    |
| Add dashboard query-budget coverage                                                                                                                            | Next   | S      | Medium | §2.6        |
| Shipped 2026-06-15: Complete operational manifest metadata and docs for commands, health, queue job, model registry visibility, and Available install behavior | Done   | S      | Medium | §2.7        |
| Add Email Studio or event-based incident notifications                                                                                                         | Next   | M      | High   | §3, §4.4    |
| Add acknowledgement, assignee, and notes workflow for incidents                                                                                                | Later  | M      | Medium | §3          |
| Add status page or digest export                                                                                                                               | Later  | L      | Medium | §3          |
| Expand RDAP endpoint support and document unsupported TLD behavior                                                                                             | Later  | S      | Low    | §3          |

## 7. Verification

Focused implementation verification passed:

```bash
vendor/bin/pest packages/site-monitor/tests/Feature/RunSiteMonitorCheckActionTest.php packages/site-monitor/tests/Feature/RunDueSiteMonitorChecksActionTest.php --configuration=phpunit.xml
```

Full package verification passed:

```bash
vendor/bin/pest packages/site-monitor/tests --configuration=phpunit.xml
```

Result: 21 tests, 60 assertions passed.

## 8. Completion Checklist

- [x] Package plan created from current code, manifest, docs, screenshots, and tests.
- [x] Comprehensive local review pass completed for outbound checks, schedule/queue, health checks, admin resources, docs, and manifest.
- [x] Capell audience pass completed for operators, site owners, and technical adopters.
- [x] Approved implementation slices shipped.
- [x] Focused Site Monitor verification passed.
- [x] Package tests passed.
- [x] Repo preflight passed for changed files.
