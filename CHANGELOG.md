# Changelog

All notable changes to this project will be documented in this file.

---

## [0.1.0] - 2025-11-19
### Added
- Initial testing release.
- Installation command (`monitor:install`).
- Storage of installation and authentication tokens.
- Remote registration request to monitoring interface.
- Basic middleware and route loading.
- Config and migration publishing.

---

## [0.1.1] - 2025-11-21
- Bug fix.

---

## [0.1.2] - 2025-11-24
- Bug fix.

---

## [0.1.3] - 2025-11-24
- Bug fix.

---

## [0.1.4] - 2025-12-21
- Bug fix.

---

## [0.1.5] - 2025-11-24
- Bug fix.

---

## [0.1.6] - 2025-12-21
- Bug fix.

---

## [0.1.7] - 2025-12-21
- Bug fix.

---

## [0.1.8] - 2025-12-21
- Bug fix.

---

## [0.1.9] - 2026-08-06
### Added
- Support for Laravel 13 (`laravel/framework: ^13.0`).

---

## [0.1.14] - 2026-08-08
### Fixed
- `MonitorMethod` was registered as a global middleware (`prependMiddleware`),
  running outside/before `StartSession` in the pipeline. Its session writes
  (`session(['monitor_id' => ...])`) happened after `StartSession` had
  already persisted the session, so they were silently lost — every request
  created a new `Monitor` instead of reusing the one from the visitor's
  session. Now registered inside the `web` group, after `StartSession`
  (`appendMiddlewareToGroup`), so session writes persist correctly.

---

## [0.1.15] - 2026-08-08
### Added
- Scraper/bot detection heuristic in `MonitorMethod`, applied to
  session-less requests: high request frequency from the same IP in a
  short window, empty/known-bot user-agent, and missing common browser
  headers. Each signal that fires is recorded in
  `data.flags.scraper_signals`; `data.flags.scraper` is set once enough
  signals fire (`monitor.scraper_signal_threshold`). Detection only —
  does not block anything yet.

---

## [0.1.16] - 2026-08-08
### Added
- `updateBlockedIps` implemented for real: persists a list of IPs to the
  new `monitor_blocked_ips` table (`ip`, `source`, defaulting to
  `manual`).
- `MonitorMethod` now checks the incoming IP against that table (cached
  per `monitor.blocked_ip_cache_ttl`) before any tracking, and aborts
  with `403` if blocked.

---

## [0.1.19] - 2026-08-11
### Fixed
- `MonitorMethod` recorded the visited path (`data.page`) without the
  host, so multi-domain/multi-subdomain sites sharing a single
  installation lost track of which subdomain a page belonged to (e.g.
  `/dashboard/3/blacklist` didn't say if it came from `app.example.com`
  or `admin.example.com`). Tracked paths are now prefixed with the
  request host (e.g. `app.example.com/dashboard/3/blacklist`).

---

## [0.1.20] - 2026-08-12
### Added
- Public `Monitor::skipTracking()` facade (backed by
  `Drcantagalo\LaravelMonitor\Support\Monitor`) so host applications can
  mark the current request as "don't track" — previously this required
  reaching directly into an undocumented internal session key
  (`session('avoid_monitor')`) copied out of the package source.
- `config('monitor.skip_session_key')`, configurable name for the session
  flag read by `SessionVisitorTracker` (was hardcoded to `avoid_monitor`).

---

## Future versions
Planned:
- Monitoring API hooks
- Dashboard integration
- Event sampling
- Network activity analysis
