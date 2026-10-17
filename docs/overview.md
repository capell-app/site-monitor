# Site Monitor

<!-- prettier-ignore-start -->

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
