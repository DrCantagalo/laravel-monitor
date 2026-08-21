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

## [0.1.25] - 2026-08-20
### Added
- Index for CRM lookups by `user_id`: new migration adds a generated
  column `monitors_user_id` (extracted from `data['user_id']`, MySQL
  `VIRTUAL`, explicit short name to stay under MySQL's 64-char
  identifier limit) with an index (`monitors_user_id_idx`) on
  `monitors`. New `Monitor::forUserId($id)` query scope — use it
  instead of `where('data->user_id', $id)`, which does **not** use the
  index (confirmed via `EXPLAIN` on real MySQL 8: the optimizer does
  not match the raw JSON expression to the generated column
  automatically). The scope also casts `$id` to `string` internally,
  since comparing the `VARCHAR` generated column against a native PHP
  int silently defeats the index too. MySQL-only migration — see
  README "Querying by user_id".

---

## [0.1.26] - 2026-08-20
### Fixed
- Migration `2026_08_20_000000_add_user_id_index_to_monitors_table`
  (v0.1.25) was MySQL-only and **broke on any other driver** — the
  generated column expression (`json_unquote(json_extract(...))`) is
  MySQL syntax, and the migration ran unconditionally, so any host on
  sqlite/pgsql got `SQLSTATE[HY000]: unknown function: json_unquote()`
  on every `migrate`/`RefreshDatabase` run. Both `up()` and `down()` are
  now driver-aware: no-op on any driver other than `mysql` —
  `data['user_id']` keeps being written regardless of driver, this only
  affects whether lookups by it are indexed. `Monitor::forUserId()` now
  falls back to `where('data->user_id', ...)` when the generated column
  doesn't exist (i.e. outside MySQL) — no index, but correct, instead of
  a "column not found" error. See README, "Querying by user_id".

---

## [0.1.27] - 2026-08-21
### Added
- Scraper-signal detection (frequency, user-agent, missing browser
  headers) is now extracted into a shared `ScraperSignalDetector` and
  runs on **both** trackers. Previously only `AnonymousVisitorTracker`
  (requests without a session) evaluated it — `SessionVisitorTracker`
  (the common case for a real browser visit) never set
  `data.flags.scraper`/`data.flags.scraper_signals`, so scraper
  suggestions were almost always empty in practice.
- `unblockIp` action on `MonitorController`: reverts
  `updateBlockedIps`/`flagScraperPath` for a single IP — removes it from
  `monitor_blocked_ips` and clears the cache read by
  `MonitorMethod::isBlocked()`.
- `unflagPath` action on `MonitorController`: reverts `flagScraperPath`
  for a path — removes it from `monitor_blocked_paths` and clears the
  cache read by `MonitorMethod::isPathBlocked()`. Does not unblock the
  IPs `flagScraperPath` may have blocked because of that path (use
  `unblockIp` for those individually).

---

## [0.1.28] - 2026-08-21
### Added
- New `monitor_ip_stats` table (`ip` unique, `visit_count`, `first_seen`,
  `last_seen`, `flagged`, `flagged_signals`, timestamps) and `IpStat`
  model, maintained via `IpStat::recordVisit()` from both
  `AnonymousVisitorTracker` and `SessionVisitorTracker` on every tracked
  request. `flagged`/`flagged_signals` mirror the most recent
  `ScraperSignalDetector` result for that IP (same semantics as
  `data.flags.scraper` on `Monitor` — not an accumulated OR). This is
  the base for listing/paginating/filtering visitors by IP without
  scanning every `Monitor.data.ips` JSON array — no new query action
  reads it yet (that's a later task); this only creates and maintains
  the table.

---

## [0.1.29] - 2026-08-21
### Added
- New `getPages` action on `MonitorController` (also accepted with the
  ephemeral read token, same as `getData`): server-side aggregated,
  paginated, filterable path listing — never ships raw `Monitor` rows.
  Params: `page`, `per_page` (max 100), `filter`
  (`all|404|clean|scraper|blocked`), `date_from`/`date_to`. Response:
  `{success, data: [{path, hits, not_found, scraper, blocked}], meta:
  {page, per_page, total, last_page}}`. Cached (`Cache::remember`, TTL
  `config('monitor.pages_cache_ttl_minutes')`, default 5) by a hash of
  the request params plus a version counter that `flagScraperPath`/
  `unflagPath` bump on every call — invalidates every cached
  combination at once (array/file cache drivers don't support
  `Cache::tags()`). See README, "Paginated page listing (`getPages`)".

---

## Future versions
Planned:
- Monitoring API hooks
- Dashboard integration
- Event sampling
- Network activity analysis
