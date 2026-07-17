# Site Monitor availability

Site Monitor is currently a Pipeline package. Its dashboard, resources, commands, schedule, and schema are still review-required until certification and host-install verification are complete. Do not use it as a production monitoring or incident-management surface yet.

## What is in the package

The package contains the foundations for external HTTP-status, SSL-certificate, domain-expiry, and incident monitoring. The intended operator surfaces include monitor targets, check results, incidents, and a health dashboard.

Those foundations are not a promise that the screens are available, installed, or safe to rely on in a host application. Availability depends on certification and verification of the package in that host.

## Before adopting it

1. Confirm that the package has been certified for the Capell version and host application you are running.
2. Have a developer verify the installation, migrations, outbound URL safeguards, schedule, and queue processing in a non-production environment.
3. Confirm how check failures, queued jobs, and incident records are handled by that host before adding monitoring targets.
4. Keep an established production monitoring service in place until those checks are complete.

## What to use today

Use your existing production monitoring and incident-management tools for uptime, certificate expiry, domain expiry, alerting, and escalation. Treat Site Monitor's current code and documentation as a review scaffold for the future certified surface, not an operator runbook.

## Troubleshooting

| What you see                                  | What it means                                                                 | What to do                                                                                                      |
| --------------------------------------------- | ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| The Site Monitor screens are absent           | The package is not certified or installed as an operator surface in this host | Do not add routes or targets manually; ask your developer to verify package certification and host installation |
| A scheduled check or queue job is not running | The package has not yet been verified for production operation in this host   | Continue using your existing monitoring service and have a developer complete the certification checks          |
| You need an uptime alert now                  | Site Monitor is not an approved production alerting solution yet              | Configure the alert in your established monitoring service                                                      |
