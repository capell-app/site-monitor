# Site Monitor

<!-- prettier-ignore-start -->

The Site monitor dashboard counts how many of your listed web addresses are passing, showing a warning or failing. Each address you add is called a target, and your own server checks it every five minutes by default. The checks run as background jobs, which your host or developer must have running. Only global administrators see the dashboard, and the Monitor targets list shows each target's latest result.

There are three kinds of check. A page check passes when the site answers with a status you choose, and anything slower than one and a half seconds shows as a warning. A certificate check warns 30 days before expiry. A domain check looks the domain up in its public registration record and warns 45 days before renewal, for .com, .org, .co.uk and a few other common endings.

By default, two failed checks in a row on a target open an incident, which is a logged outage, and the next passing check closes it. Staff can also close or reopen one by hand. Warnings never open incidents, and nobody is emailed or messaged. Results are kept for 30 days, and logged outages are listed under Monitor incidents.

# Site Monitor

Site Monitor brings public HTTP availability, certificate expiry, domain expiry and incident history into Capell admin. Operators can configure targets, queue checks, inspect failures and record incident resolution without putting probes in the public page-rendering path.

Site Monitor is a free first-party package with Stable maturity. Check the release catalogue for current version verification and availability. Maturity, installation access and evidence for a particular release are separate decisions.

## Operational Scope

Checks run from your application host through its scheduler and queue. HTTP checks validate response status and record response time. Certificate and RDAP checks report expiry or an explicit lookup failure. Repeated failures open an incident at the configured threshold; a passing check resolves it.

Private or reserved destinations are rejected by default. Pausing a discovered target preserves the operator's settings and recorded state. Certificate probes use a validated network address while retaining the original hostname for TLS SNI.

Incident events let a developer integrate notifications with the application's approved alerting system. They do not automatically provide email, SMS or on-call delivery. Keep independent external monitoring for failures that also stop this application host.

## Adoption Checks

Verify entitlement and installation, migrations, effective permissions, scheduler, queue worker and `capell:site-monitor:doctor` in a non-production host. Exercise a passing target, repeated failure, incident opening, recovery and paused target before depending on the workflow.

See the [operator guide](admin-guide.md) for target configuration and troubleshooting, and the [developer README](../README.md) for package contracts.

---

For how to use Site Monitor, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
