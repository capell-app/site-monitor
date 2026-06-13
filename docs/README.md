# Site Monitor Docs

Package-local documentation for `capell-app/site-monitor`.

Status: Review-ready. These docs describe implemented package behaviour plus remaining certification checks.

| Document                                | Use                                                                 |
| --------------------------------------- | ------------------------------------------------------------------- |
| [Overview](overview.md)                 | Owner value, runtime behavior, setup, safety, and screenshot notes. |
| [Screenshot manifest](screenshots.json) | Marketplace and docs screenshot capture contract.                   |

## Verification

Do not mark this package released until focused package tests and host install verification pass:

- `vendor/bin/pest packages/site-monitor/tests --configuration=phpunit.xml`
- `vendor/bin/phpstan analyse packages/site-monitor/src packages/site-monitor/tests --configuration=phpstan.neon --memory-limit=-1`
- `COMPOSER=composer.local.json composer preflight`
