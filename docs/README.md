# Site Monitor Docs

Package-local documentation for `capell-app/site-monitor`.

Status: Available. These docs describe implemented package behaviour plus remaining screenshot certification work.

| Document                                | Use                                                                    |
| --------------------------------------- | ---------------------------------------------------------------------- |
| [Overview](overview.md)                 | Operator value, runtime behavior, setup, safety, and screenshot notes. |
| [Screenshot manifest](screenshots.json) | Marketplace and docs screenshot capture contract.                      |

## Verification

Focused package verification:

- `vendor/bin/pest packages/site-monitor/tests --configuration=phpunit.xml`
- `vendor/bin/phpstan analyse packages/site-monitor/src packages/site-monitor/tests --configuration=phpstan.neon --memory-limit=-1`
- `COMPOSER=composer.local.json composer preflight`
