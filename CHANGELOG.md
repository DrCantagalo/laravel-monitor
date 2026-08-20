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

## [0.1.21] - 2026-08-15
### Added
- 404 tracking: `MonitorMethod` now captures the response status code and
  passes it down to `SessionVisitorTracker`/`AnonymousVisitorTracker`,
  which record `data.not_found[path] = true` whenever a visited path
  returned a 404 — lets the dashboard flag paths that don't exist on the
  monitored site.
- `flagScraperPath` handler action (`local_token` auth, same gate as
  `updateBlockedIps`): flags a path (host-less, e.g.
  `wp-admin/install.php`) as a scrapper pattern. Inserts it into the new
  `monitor_blocked_paths` table (`BlockedPath` model) and blocks every
  IP already recorded as having visited that path (`monitor_blocked_ips`,
  `source = 'scraper-path'`).
- `MonitorMethod` now also rejects (`403`) any request whose path (sans
  host) matches an entry in `monitor_blocked_paths`, cached the same way
  as blocked IPs (`monitor.blocked_ip_cache_ttl`) — since the match
  ignores the host, this protects every subdomain sharing the same
  installation.

### Fixed
- `MonitorController::handle` read the `action` param via
  `$request->query()`, which only looks at the URL query string. Every
  write action called via `Http::post()` (`updateBlockedIps`, `clearData`,
  and the new `flagScraperPath`) sends `action` in the JSON body instead
  — `query()` never saw it, silently fell back to the `getData` default,
  and returned `{"success": true, "data": [...]}` without blocking/
  clearing/flagging anything. Callers had no way to tell, since the
  response still reported `success: true`. Switched to `$request->input()`
  (checks query string, form body, and JSON body), which is also how the
  existing `getData`/`issueReadToken` GET-based calls already worked by
  accident. Found and fixed while validating `flagScraperPath` end-to-end
  against a real Laravel app (harness) instead of only against `Http::fake`
  mocks, which can't catch a bug like this since they never execute the
  package's own controller code.

---

## [0.1.22] - 2026-08-16
### Fixed
- Remember-me never actually reconnected a visitor returning after their
  PHP session expired — the exact case it exists to solve.
  `SessionVisitorTracker::track()` only recognized a returning visitor
  via `session('remember_me')`, set exclusively by the dedicated
  `GET /monitor/remember-me` endpoint, which the host app's front-end
  can only call after the page has already loaded. But `MonitorMethod`
  already runs its tracking logic on that very first page load, before
  any front-end JS gets a chance to run — at that point neither
  `session('remember_me')` nor `session('monitor_id')` is set yet, so
  `track()` always fell straight into the "create new Monitor" branch,
  which immediately overwrites the `monitor_id_token` cookie with a
  fresh token. The browser applies that `Set-Cookie` before the page's
  own JS runs, so by the time the dedicated endpoint is finally called,
  the cookie it reads is already the brand-new one — the original
  visitor's identity is lost for good. `track()` now also checks the
  `monitor_id_token` cookie directly (it always reaches the server via
  the request header regardless of `httpOnly` — that flag only blocks
  `document.cookie` access, never blocks the backend) before falling
  through to creating a new row, closing the race. The dedicated
  endpoint is unchanged and still works for host apps that already rely
  on it. Found and fixed while migrating `home-page`'s remember-me
  integration to the package's intended contract (task home-page 48).

---

## [0.1.23] - 2026-08-19
### Fixed
- `monitor:install` duplicava o bloco `# Laravel Monitor` no `.gitignore`
  do app hospedeiro a cada execução (reinstalação, upgrade que roda o
  comando de novo). `MonitorInstallCommand::handle()` fazia
  `File::append()` sem checar se a entrada já existia. Agora só faz o
  append se `storage/monitor/installation.json` ainda não estiver
  presente no `.gitignore` (ou se o arquivo ainda nem existir).

---

## [0.1.24] - 2026-08-20
### Added
- Authenticated user tagging: `SessionVisitorTracker::track()` now
  writes `data['user_id']` (`Auth::id()`) onto the current
  device/browser's Monitor row whenever `Auth::check()` is true — a tag
  alongside `ua`/`ips`/`page`, never a merge across rows (a user logged
  in on 2 devices still gets 2 rows, each tagged with the same
  `user_id`). New config `track_authenticated_user` (default `true`,
  opt-out) for host apps without `Auth` configured or that don't want
  this data. Session-tracked visitors only (`SessionVisitorTracker`) —
  anonymous tracking (`AnonymousVisitorTracker`) is unaffected.

---

## Future versions
Planned:
- Monitoring API hooks
- Dashboard integration
- Event sampling
- Network activity analysis
