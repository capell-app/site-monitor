# Site Monitor

<!-- prettier-ignore-start -->

The Site monitor dashboard counts how many of your listed web addresses are passing, showing a warning or failing. Each address you add is called a target, and your own server checks it every five minutes by default. The checks run as background jobs, which your host or developer must have running. Only global administrators see the dashboard, and the Monitor targets list shows each target's latest result.

There are three kinds of check. A page check passes when the site answers with a status you choose, and anything slower than one and a half seconds shows as a warning. A certificate check warns 30 days before expiry. A domain check looks the domain up in its public registration record and warns 45 days before renewal, for .com, .org, .co.uk and a few other common endings.

By default, two failed checks in a row open an incident. A pass closes it. Staff can close or reopen it. Warnings open none. Page checks support keywords and response time limits. Maintenance records failures without opening incidents or sending alerts. Flapping triggers one alert. Your developer can configure email, Slack or webhook alerts and switch recovery alerts off. No destination means no alerts. Results stay 30 days and resolved outages a year by default, both configurable. Find outages under Monitor incidents.

# Site Monitor

Site Monitor brings public HTTP availability, certificate expiry, domain expiry and incident history into Capell admin. Operators can configure targets, queue checks, inspect failures and record incident resolution without putting probes in the public page-rendering path.

Site Monitor is a free first-party package with Stable maturity. Check the release catalogue for current version verification and availability. Maturity, installation access and evidence for a particular release are separate decisions.

## Operational Scope

Checks run from your application host through its scheduler and queue. HTTP checks validate response status and record response time. Certificate and RDAP checks report expiry or an explicit lookup failure. Repeated failures open an incident at the configured threshold; a passing check resolves it.

Private or reserved destinations are rejected by default. Pausing a discovered target preserves the operator's settings and recorded state. Certificate probes use a validated network address while retaining the original hostname for TLS SNI.

Ask your developer to configure email, Slack or webhook incident alerts using the [alert delivery guide](alerts.md). Each configured destination receives opening and recovery alerts for every target. No alerts are sent until a destination is configured. Keep independent external monitoring for failures that also stop this application host.

## Adoption Checks

Verify entitlement and installation, migrations, effective permissions, scheduler, queue worker and `capell:site-monitor:doctor` in a non-production host. Exercise a passing target, repeated failure, incident opening, recovery and paused target before depending on the workflow.

See the [operator guide](admin-guide.md) for target configuration and troubleshooting, and the [developer README](../README.md) for package contracts.

---

For how to use Site Monitor, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
