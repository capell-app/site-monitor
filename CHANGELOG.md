# Changelog

## Unreleased

- Added alert snoozing: set "Snooze alerts until" on a target and incidents are still opened and recorded but no notification is sent until that time. An incident opened during a snooze also skips its recovery alert.
- Verified that target timeouts below one second (down to 100ms) reach the HTTP request unrounded, and documented it.
- Added keyword and latency checks to page targets: a page check fails when the optional expected keyword is missing from the response, or when the response is slower than the optional `max_response_ms` limit (slower than `warning_response_ms` still only warns).
- Added maintenance windows per target: failures inside the window are recorded but open no incident and send no alert, and do not count towards the failure threshold.
- Added flap detection: a target whose up/down state changes `flap_change_threshold` times within its last `flap_window_runs` runs is marked flapping and sends one alert; outage and recovery alerts pause until it settles. Set `flap_window_runs` to 0 to switch it off.
- Added `incident_retention_days` (default 365): the scheduled run now deletes resolved incidents older than that, keeping open incidents. Previously only check results were pruned.
- Added configurable Slack and generic HTTPS webhook delivery for incident openings and automatic recoveries, with optional webhook bearer authentication and independent delivery failures.
- Removed credentials, query strings and fragments from target addresses in incident alerts.
- Fixed the SSL certificate check reporting a host-name mismatch as unavailable instead of untrusted on OpenSSL 4, which words the error differently.
- Added a security weakness check with package-owned posture evidence.
