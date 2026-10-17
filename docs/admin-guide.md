# Site Monitor Operator Guide

Site Monitor checks HTTP availability, certificate expiry and domain expiry from your Capell application host. It records check history and incidents in admin. Its release catalogue maturity is Stable, but distribution remains preview-only until installation and release readiness are verified.

## Before Enabling Monitoring

A developer must install the entitled package, run its migrations, synchronise package permissions and verify `capell:site-monitor:doctor`. Keep the Laravel scheduler and a queue worker running. Scheduled checks are queued; a healthy web page alone does not prove either background process is running.

Set the queue worker's `--memory` limit above the application's measured boot footprint. A fully installed application can exceed Laravel's default 128 MB before its first check; the worker then exits after a job with `Memory limit exceeded`. Supervise restarts and verify that pending checks are consumed, rather than treating a started process as a healthy worker.

Keep independent monitoring for the application host itself. Site Monitor cannot report an outage while its own scheduler, queue or database is unavailable. Incident events are integration points, not a built-in promise of email, SMS or on-call delivery.

## Add A Target

Open **Monitoring > Site Monitor targets** in admin with a global operator account. Create a target with a meaningful name and public HTTP or HTTPS URL. Site-scoped access does not grant global monitoring control.

Choose the check type:

- **HTTP status:** compare the returned response with the expected minimum and maximum status codes. A slow response can produce a warning even when its status is accepted.
- **SSL certificate:** compare the presented certificate expiry with the configured warning window. This is an expiry check, not a complete certificate-chain or security audit.
- **Domain expiry:** obtain expiry from a configured RDAP provider. Unsupported suffixes and unavailable records produce an explicit failed check rather than a guessed expiry.

Set the interval, timeout and consecutive failure threshold. The scheduler interval bounds actual frequency. A threshold of two avoids opening an incident for the first isolated failure; choose it according to the service's tolerance, not to hide persistent failure.

Save the target and use **Run check**. This queues work, so inspect the next recorded result before treating the target as operational. Verify the queue worker consumes the job and the last-checked time changes.

## Read Results And Incidents

Use the dashboard to review enabled targets, current states and open incidents. Open a target to inspect its latest result and timestamps. Passing, warning, failing and unknown are distinct states; an unknown target has not established a successful check.

A failing target opens an incident after its configured failure threshold. Further failures update the existing incident rather than creating a new one each time. A passing result resolves its open incident automatically. The incident resource also supports recording operator resolution.

Connect `SiteMonitorIncidentOpened` and `SiteMonitorIncidentResolved` to the application's approved notification or incident workflow. Test that integration independently, including recipient selection and failed delivery. These events do not themselves send external alerts.

## Pause And Retention

Disable a target when checks should stop. Automatic discovery must retain that decision, its configured thresholds and its recorded health state. Discovery refreshes source identity; it must not silently reset operator settings.

Prune old runs through the package's retention action. Latest target results and incident-linked runs are retained. Agree retention and incident evidence requirements before introducing automated pruning.

## Outbound Safety

Public destinations only are allowed by default. Local hostnames and private or reserved network addresses are rejected. HTTP redirects are checked hop by hop; certificate sockets connect to a validated address while retaining the hostname for TLS SNI.

Do not enable private targets in production. The development-only override is for an explicitly isolated test environment. A failed safety check is a rejected target, not a reason to bypass the guard.

## Troubleshooting

| Symptom                                                    | Check                                                                                                                             |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Admin screens absent                                       | Entitlement, installed-package activation, migrations and the operator's effective permissions.                                   |
| Run check reports queued but no new result appears         | Queue connection, worker health and failed jobs.                                                                                  |
| Scheduled checks stop                                      | Scheduler health, schedule configuration and due-target timestamps.                                                               |
| Domain expiry unavailable                                  | The target suffix has a configured RDAP endpoint and that provider returns an expiration event.                                   |
| SSL expiry unavailable                                     | The target presents a readable certificate on port 443 and the host permits the outbound connection.                              |
| Target is repeatedly failing                               | Latest error, accepted status range, timeout and failure threshold; investigate the service before changing the expected outcome. |
| Incident opens but nobody is notified                      | The application's event-to-alert integration and its delivery evidence.                                                           |
| Checks show success while the whole host is down elsewhere | Use independent external monitoring; checks from this host cannot observe its own total outage.                                   |
