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

## [0.1.30] - 2026-08-21
### Added
- New `getVisitorsByIp` action on `MonitorController` (also accepted
  with the ephemeral read token): paginated/filterable listing of
  `monitor_ip_stats`. Params: `page`, `per_page` (max 100), `filter`
  (`all|flagged|clean|blocked`), `date_from`/`date_to` (filters by
  `last_seen`). Response: `{success, data: [{ip, visit_count,
  first_seen, last_seen, flagged, flagged_signals, blocked}], meta:
  {page, per_page, total, last_page}}`.
- New `getBlockedIps`/`getBlockedPaths` actions (same auth): plain
  paginated listing of `monitor_blocked_ips`/`monitor_blocked_paths`
  (`page`/`per_page` only). Response shape mirrors the other listing
  actions.
- All three query their tables directly via `Model::paginate()` (no
  JSON blob to aggregate, unlike `getPages`). Cached the same way as
  `getPages` (`Cache::remember`, TTL
  `config('monitor.listings_cache_ttl_minutes')`, default 5) but with
  its own version counter (`monitor:listings:version`), bumped by
  `updateBlockedIps`/`unblockIp`/`flagScraperPath`/`unflagPath`. See
  README, "Paginated visitor/blocklist listing".

---

## [0.1.31] - 2026-08-21
### Added
- New `pruneData` action on `MonitorController` (requires the
  permanent `local_token`, same as `clearData`/`updateBlockedIps` — not
  accepted with the ephemeral read token): partial cleanup, unlike
  `clearData`'s full truncate. Params: `older_than_days` (required,
  non-negative integer, `422` if missing/invalid), `only_scraper_flagged`
  (optional boolean, default `false`). Deletes `Monitor` rows with
  `updated_at` older than the cutoff and `monitor_ip_stats` rows with
  `last_seen` older than the cutoff; when `only_scraper_flagged=true`,
  restricts to rows flagged as scraper (`data.flags.scraper` on
  `Monitor`, `flagged` on `IpStat`). Response: `{success,
  monitors_deleted, ip_stats_deleted}`. Invalidates the `getPages`/
  `getVisitorsByIp` listing caches when anything was actually deleted.
  See README, "Partial cleanup (pruneData)".

---

## [0.2.0] - 2026-08-23
### Changed — BREAKING
- Removed the two public visitor-facing HTTP routes,
  `GET /monitor/update-data` and `GET /monitor/remember-me`, along with
  `MonitorController::updateData()`/`rememberMe()`. The package no
  longer opens public routes in a host application without its explicit
  awareness — those two existed purely so the host app's front-end
  could call them directly, which is exactly the kind of implicit
  public surface this removes.
- Added `Monitor::tag(array $data): bool` and `Monitor::recognize(): bool`
  on the `Monitor` facade
  (`Drcantagalo\LaravelMonitor\Facades\Monitor`) as the server-side
  replacement — same underlying logic as the removed controller
  methods (session lookup, protected-key filtering, remember-me cookie
  lookup), just called directly instead of over HTTP. Both return a
  plain bool instead of an HTTP/JSON response; `recognize()` now also
  verifies the cookie actually matches a `Monitor` row before returning
  `true` (the old endpoint returned `success: true` whenever the cookie
  was merely present, deferring the real match to
  `SessionVisitorTracker::track()` later in the same request).
- `MonitorController::PROTECTED_DATA_KEYS` moved to
  `Drcantagalo\LaravelMonitor\Support\Monitor::PROTECTED_DATA_KEYS`
  (public constant) so it survives the controller methods it backed.
- **Migration**: if your host app's front-end was calling
  `GET /monitor/update-data` or `GET /monitor/remember-me` directly
  (e.g. via `fetch`/AJAX from the browser), those requests will now
  404. Move the call server-side instead: replace it with
  `Monitor::tag([...])` / `Monitor::recognize()` in the controller/route
  handler serving that page, before the response is sent. See README,
  "Remember-me" and "Arbitrary visitor data".
- The `local_token`/`issueReadToken` auth scheme for the `/monitor/handler`
  admin/dashboard endpoint is unaffected by this change.

---

## [0.1.32] - 2026-08-22
### Fixed
- `2026_08_21_000000_create_monitor_ip_stats_table` migration failed on
  real MySQL (`SQLSTATE[42000]: ... Invalid default value for
  'last_seen'`) — `first_seen`/`last_seen` were non-nullable `timestamp`
  columns with no default, which MySQL 8 rejects under
  `explicit_defaults_for_timestamp` (only the sandbox's SQLite test
  environment let this slide, so it went unnoticed until a real deploy).
  Added `->useCurrent()` to both columns.

---

## [0.2.1] - 2026-08-30
### Added
- New `getUsers` action: paginated, aggregated listing of visitors by
  `user_id` (CRM), for a dashboard's "who are my authenticated users"
  view. Returns `user_id`, `visits_count`, `last_activity` (max
  `updated_at` across that user's `Monitor` rows), and — only when
  already present in `data` via `Monitor::tag(['name' => ..., 'email'
  => ...])` — `name`/`email`. Aggregation and pagination run entirely
  in SQL, grouped by the indexed generated column
  (`monitors_user_id`, added in `0.1.25`) on MySQL, never the raw
  `data->user_id` expression (same reasoning as `Monitor::forUserId()`
  — see README, "Querying by user_id").
- New `getUserVisits` action: given a `user_id`, paginated listing of
  that user's `Monitor` rows (via `Monitor::forUserId()`) — the same
  data each row already carries (pages, IPs, timestamps), nothing new
  captured.
- Both added to the read-only token group (`getData`/`getPages`/etc):
  accept the permanent `local_token` or the ephemeral read token from
  `issueReadToken`, cached the same way as `getVisitorsByIp`/
  `getBlockedIps` (`Cache::remember` + the `monitor:listings:version`
  counter).

See README, "User listing (`getUsers`, `getUserVisits`)".

---

## [0.3.0] - 2026-08-31
### Changed — BREAKING
- `getPages` no longer aggregates a scraper signal at the path level:
  removed `'scraper'` from `MonitorController::PAGES_FILTERS` and from
  the per-path array built by `buildPagesResult` (it used to bubble
  `data.flags.scraper` from whichever visitor hit the path up to the
  path itself — misleading, since a path like `/` could be marked
  "possible scraper" just because one bot passed through it once).
  Response shape drops the `scraper` field from each `getPages` item;
  the `filter=scraper` value is no longer accepted (`422` if used).
- The scraper heuristic (`ScraperSignalDetector`) is unaffected and
  keeps running exactly as before — it's now purely an IP/visitor-level
  concept, read via `getVisitorsByIp`/`monitor_ip_stats`.
  `flagScraperPath`/`BlockedPath` (the honeypot mechanism: blocks a path
  + the IPs that already visited it) is also unaffected — it never
  depended on this field.
- **Migration**: if your dashboard/front-end reads `row.scraper` from
  `getPages` items or sends `filter=scraper`, drop both — filter on
  `getVisitorsByIp`'s `flagged`/`safe` fields instead for IP-level
  scraper signal.

See README, "Paginated page listing (`getPages`)".

---

## [0.4.0] - 2026-08-31
### Added
- Path is now a persisted review entity, not just an on-the-fly
  aggregate of `data.page`. New `monitor_path_reviews` table (`path`,
  `status` — `pending` default or `safe`, `reviewed_at`) + new model
  `PathReview`.
- Two new write actions on `MonitorController`, same auth as
  `flagScraperPath` (permanent `local_token` only): `markPathSafe`
  (`{"path": "..."}` → records the path as reviewed/`safe`) and
  `unmarkPathSafe` (reverts it — deletes the review row, path goes back
  to the default `pending` state). Neither blocks anything; it's purely
  a review-state flag consumed by `getPages`.
- `getPages`/`buildPagesResult` now exposes a `status` field
  (`pending`/`safe`) per path, matched by suffix against
  `monitor_path_reviews` the same way `blocked` already is against
  `monitor_blocked_paths`.

### Changed — BREAKING
- `getPages`'s default `filter` (when the parameter is omitted) is now
  `pending_review` — `not_found = true` AND `status != 'safe'` AND
  `blocked = false` — instead of `all`. This is the "still needs a human
  look" queue, meant to become the dashboard's default listing. Callers
  that relied on the implicit default returning every path must now pass
  `filter=all` explicitly. `pending_review` is also available as an
  explicit filter value.

See README, "Paginated page listing (`getPages`)" and the new
`markPathSafe`/`unmarkPathSafe` entries right after `unflagPath`.

---

## [0.5.0] - 2026-08-31
### Added
- New `monitor:export-denylist {--format=apache|nginx}` command:
  generates a web-server deny-list snippet from `monitor_blocked_ips`
  (`Require not ip x.x.x.x` per line for Apache, `deny x.x.x.x;` for
  Nginx), written to `config('monitor.denylist_path')` (default
  `storage_path('app/monitor/denylist.conf')`). New
  `Support\DenylistExporter` class backs both the command and the
  auto-export hook below.
- New config keys: `denylist_auto_export` (bool, default `false`),
  `denylist_format` (`apache`/`nginx`, default `apache`), `denylist_path`.
- Opt-in auto-export: when `denylist_auto_export` is `true`, the file is
  regenerated automatically every time `monitor_blocked_ips` changes
  (`updateBlockedIps`/`unblockIp`/`flagScraperPath`). Fails open — a
  write error is logged, never breaks the block/unblock action itself.
  Off by default; a fresh install never writes to disk without this
  being explicitly enabled.

See README, "Web-server deny-list export (`monitor:export-denylist`)".

---

## [0.6.0] - 2026-08-31
### Added
- New read-only action `getVisitorPaths` (`GET/POST
  /monitor/handler?action=getVisitorPaths`, `ip=...`, same auth as
  `getVisitorsByIp` — permanent `local_token` or the ephemeral read
  token): given an IP, scans every `Monitor` whose `data.ips` contains
  it and aggregates the paths (`data.page`) it's been seen on, sorted by
  hits descending. Lets the dashboard confirm visually that an IP is a
  scraper before blocking it. No pagination/caching — small per-IP
  result set, called on demand.

See README, "Paginated visitor/blocklist listing", new
`getVisitorPaths` entry right after `getVisitorsByIp`.

---

## [0.7.0] - 2026-08-31
### Changed
- **Breaking**: `pruneData`'s selective-delete parameter renamed
  `only_scraper_flagged` -> `only_blocked`, and its matching logic
  changed from the live heuristic signal (`Monitor.data.flags.scraper`,
  `IpStat.flagged` — automatic, non-cumulative, reflects only the last
  request seen from that IP, never reviewed by a human) to
  `monitor_blocked_ips` (an IP the user actually confirmed/blocked via
  `updateBlockedIps`/`flagScraperPath`). Fixes a permanent-data-loss risk:
  `pruneData` could previously delete rows for an IP based purely on an
  unreviewed false positive from the heuristic.
- `pruneMonitors` now matches by checking whether any IP in a `Monitor`
  row's `data.ips` is present in `BlockedIp::pluck('ip')`, same style of
  matching already used by `flagScraperPath`/`buildVisitorsResult`.
  `IpStat` selective delete now filters `whereIn('ip', ...)` against the
  same blocked-IP list instead of `where('flagged', true)`.

See README, "Partial cleanup (`pruneData`)".

---

## [0.8.0] - 2026-08-31
### Added
- `monitor_ip_stats` gains a persisted `safe` boolean column (default
  `false`) — an IP is a review entity now, mirroring the
  `monitor_path_reviews`/`markPathSafe` pattern added for paths in
  `0.4.0`, but as a column on the existing per-IP row instead of a
  separate table (`monitor_ip_stats` is already one row per IP).
- New actions on `MonitorController`, same auth as `markPathSafe`/
  `flagScraperPath` (permanent `local_token` only): `markIpSafe`
  (`{"ip": "..."}` → sets `safe = true`, `IpStat::updateOrCreate` so it
  also works for an IP with no tracked visits yet) and `unmarkIpSafe`
  (reverts — sets `safe = false`; the row itself is never deleted, since
  it carries real visit history, unlike a `monitor_path_reviews` row).
- `getVisitorsByIp` now exposes `safe` per IP, and its `filter=flagged`
  excludes IPs marked `safe`. Regardless of the requested filter,
  results are also always ordered with `flagged = true AND safe = false`
  rows first — the actual "needs review" queue — before falling back to
  the existing `visit_count desc` ordering within each group.

### Fixed
- `IpStat.flagged`/`flagged_signals` reflect only the most recent
  request from an IP, not a cumulative signal (see `0.1.28`) — so a
  human marking an IP safe could be silently undone by the very next
  request from it re-tripping the heuristic. `safe` is never touched by
  `IpStat::recordVisit()`, so it now survives that; `flagged` itself
  keeps updating on every visit for audit/history, but the "needs
  review" queue exposed by `getVisitorsByIp` reads `safe`, not the raw
  `flagged` value, to decide what still needs a human look.

See README, "Per-IP stats (`monitor_ip_stats`)" and "Paginated
visitor/blocklist listing".

---

## [0.9.0] - 2026-08-31
### Added
- New `monitor_block_results` table (`ip` unique, `counter`,
  `last_attempt_at`) + new model `BlockResult` — a raw tally of blocked
  attempts per IP, independent of `monitor_ip_stats` (which only tracks
  requests that were actually let through).
- `MonitorMethod` now increments that counter right before every
  `abort(403)`, covering both branches that lead to a block: the IP
  itself already in `monitor_blocked_ips`, **and** the path it hit being
  in `monitor_blocked_paths` (a brand-new IP that was never separately
  blocked, hitting an already-flagged honeypot path, still counts).
  Increment is a single atomic `DB::table('monitor_block_results')->upsert(...)`
  (Laravel's query builder, portable across MySQL/SQLite) instead of
  `firstOrCreate` + `increment` — the latter is two round-trips and races
  under concurrent hits from the same IP, a common shape for a bot
  hammering a blocked endpoint. Wrapped in the same fail-open
  `try`/`catch (QueryException)` pattern already used by the rest of
  `MonitorMethod` — a not-yet-migrated table logs a warning and skips the
  increment, it never stops the request from being blocked.
- New field `blocked_attempts_total` (`SUM(counter)`) on the existing
  `getData` response — reuses the same client-side fetch that already
  powers the dashboard's KPI cards instead of adding a dedicated endpoint
  for one number.
- New read action `getBlockResults`: paginated `{ip, counter}` listing
  ordered by `counter` descending, same auth as `getVisitorsByIp`/
  `getBlockedIps` (permanent `local_token` or the ephemeral read token).
  Fails open to an empty page if the table isn't migrated yet.
- New config key `block_results_cache_ttl_seconds` (default `45`).
  `blocked_attempts_total`/`getBlockResults` use this short, fixed TTL
  instead of the versioned cache scheme shared by `getPages`/
  `getVisitorsByIp`/etc (`invalidatePagesCache`/`invalidateListingsCache`)
  — that scheme assumes rare mutation (a manual admin action bumps the
  version once); this counter can increment on every single request from
  a hammering bot, and bumping a shared cache version that often would
  thrash the cache for every other unrelated listing on the dashboard.

See README, "Blocked-attempt counter (`monitor_block_results`)".

---

## [0.10.0] - 2026-09-02
### Fixed
- **Memory exhaustion in `getData`** (`MonitorController::getData()`):
  the action loaded `Monitor::all()` — every row of `monitors`,
  unpaginated — into a single Eloquent collection and serialized it as
  one JSON response. Confirmed in production (cantagalo.it) throwing
  `Allowed memory size of 134217728 bytes exhausted` once the table
  reached ~19.5k rows (~6.7MB of raw JSON in the `data` column alone,
  before accounting for per-row Eloquent object overhead) — well past
  PHP-FPM's 128MB `memory_limit`. Every other read action in this
  controller (`getPages`, `getVisitorsByIp`, `getUsers`, etc.) already
  paginated or aggregated in SQL; `getData` was the one action still
  doing a full unbounded load, unchanged since it was first written.

### Changed
- **Breaking: `getData` response shape.** The raw `data` array is gone —
  replaced with four SQL-computed totals: `visitors_total` (row count),
  `visits_total` (`SUM` of `data.visits` via `JSON_EXTRACT`/
  `json_extract`), `sessions_total` (`SUM` of `data.sessions` array
  length via `JSON_LENGTH`/`json_array_length`), and `unique_ips_total`
  (`IpStat::count()`, reusing the `monitor_ip_stats` table introduced in
  `0.8.0` instead of re-deriving IP uniqueness from `data.ips`).
  `blocked_attempts_total` (added in `0.9.0`) is unchanged. None of the
  four new totals ever instantiates a full `Monitor` row in PHP — the
  first three are pure SQL aggregates over the JSON column, and the
  fourth is a `COUNT(*)` against an already-denormalized table, so the
  fix doesn't just paginate the memory problem into a slower shape, it
  removes the unbounded PHP-side aggregation entirely.
  - Decision: **no** paginated-sample replacement for the raw `data`
    array. No known consumer of `getData` needed row-level detail from
    this specific action — the home-page dashboard only used it to
    compute the same totals client-side, and row-level detail is
    already available from `getPages`/`getVisitorsByIp`/`getUserVisits`.
    Adding a partial `data` sample back would keep a foot-gun for larger
    installs (any per-page limit is still an arbitrary cap that hides
    data) for no consumer that actually needs it.
  - `sessions_total` counts recorded sessions (`SUM` of per-row array
    lengths), not a cross-row deduplicated count like
    `unique_ips_total` — there's no `monitor_session_stats` table to
    dedupe against. In practice a session id belongs to exactly one
    `Monitor` row (`Monitor::newVisit()` already dedupes within a row
    before pushing), so this matches the real unique count in the
    overwhelming majority of installs; it's a documented approximation
    rather than a guaranteed exact figure, unlike `unique_ips_total`.
  - New config key `data_totals_cache_ttl_seconds` (default `45`,
    same TTL/rationale as `block_results_cache_ttl_seconds` — these
    totals mutate on every tracked request, so they use a short fixed
    TTL outside the versioned `getPages`/`getVisitorsByIp` cache scheme).
    All four totals fail open to `0` (logged via `Log::warning`) if the
    backing table/column isn't migrated yet on an older install.

See README, "Aggregated dashboard totals (`getData`)".

---

## [0.11.0] - 2026-09-02
### Fixed
- **Race condition in `IpStat::recordVisit()`**: the old
  `where('ip', $ip)->first()` + `create()`/`save()` sequence wasn't
  atomic. Two concurrent requests from the same IP (common with bots
  hammering a site) could both see no existing row, both attempt
  `create()`, and the second would violate the `monitor_ip_stats_ip_unique`
  constraint and throw an uncaught `QueryException` — a real `500` for
  the visitor/bot making that request, confirmed in production
  (cantagalo.it logs, `Duplicate entry '<ip>' for key
  'monitor_ip_stats_ip_unique'`, multiple hits within the same second).
  Replaced with a single atomic
  `DB::table('monitor_ip_stats')->upsert(...)` (`ON DUPLICATE KEY
  UPDATE`/`ON CONFLICT` depending on the driver) — same pattern already
  used by `MonitorMethod::recordBlockedAttempt()` for
  `monitor_block_results`. `first_seen`/`created_at` only appear in the
  insert values (never in the update clause), so they're preserved
  across every later visit; `safe` is untouched either way, same as
  before.

See README, "Per-IP stats (`monitor_ip_stats`)".

---

## [0.12.0] - 2026-09-02
### Fixed
- **`filter=flagged` no longer includes already-blocked IPs**: an IP
  already present in `monitor_blocked_ips` has already been confirmed as
  a scraper by a human — it no longer needs to show up in the
  "possible scraper" review queue exposed by `getVisitorsByIp`. Added
  `whereNotIn('ip', $blockedIps)` to the `flagged` branch of
  `buildVisitorsResult()` (the `clean` and `blocked` branches already
  handled this correctly). It still shows up under `filter=blocked`, as
  before.
- **Per-user "Visits" count was always 1**: `buildUsersResult()` counted
  `Monitor` rows (`COUNT(*)`) grouped by `user_id` to build the
  `visits_count` shown by `getUsers` — but a `Monitor` row is reused
  across sessions for the same device/browser (reconnected via the
  remember-me cookie, see `SessionVisitorTracker::track()`), not created
  per visit. A user who always returns from the same browser therefore
  always had exactly 1 row, so `visits_count` was always 1 regardless of
  how many times they actually visited.

  Two related bugs, fixed together:
  1. `data.visits` (the field meant for this, already incremented by
     `Monitor::newVisit()`) was only touched by the 2 remember-me
     reconnection paths in `SessionVisitorTracker::track()` — the normal
     "session already tracked" path (the most common one, every
     subsequent request within the same browser session) never
     incremented it. Now it does, on every tracked request.
  2. `buildUsersResult()` now sums `data.visits` per `user_id`
     (`jsonNumericSumExpression()`, the same portable MySQL/SQLite
     expression already used by `visitsTotal()` in `getData`) instead of
     counting rows. Rows with no `visits` key (data from before this
     version) are simply excluded from the sum rather than causing an
     error.

See README, "Per-IP stats (`monitor_ip_stats`)" and "Aggregated dashboard
totals (`getData`)".

---

## [0.13.0] - 2026-09-02
### Removed
- **Breaking**: `denylist_auto_export` config key removed, along with the
  automatic regeneration it triggered on every `updateBlockedIps`/
  `unblockIp`/`flagScraperPath` call. It rewrote the *entire* deny-list
  file (not incrementally) on every single call — scales badly when a
  user reviews and blocks a large queue of flagged IPs one page at a
  time, for a freshness guarantee most consumers didn't actually need
  faster than their own web server already reloads config (typically
  once a day via cron, not in real time). If your published
  `config/monitor.php` still has `'denylist_auto_export' => true`, it
  simply becomes an inert key now — no error, just no longer read
  anywhere.

### Added
- **`exportDenylist` API action**: `POST /monitor/handler?action=exportDenylist`
  (same auth as `updateBlockedIps`/`clearData` — permanent `local_token`
  only, never accepted with the ephemeral read token, since it writes to
  disk on the host server). Regenerates the deny-list file on demand and
  returns `{"success": true, "path": "..."}`. Gives the consuming app
  explicit control over *when* to export instead of a flag that decides
  by itself — call it from a dashboard button, your own cron hitting the
  API, or anywhere else that makes sense for your deployment. The
  `monitor:export-denylist` artisan command is unaffected and keeps
  working exactly as before.

See README, "Web-server deny-list export (`monitor:export-denylist`)".

---

## [0.14.0] - 2026-09-02
### Added
- **`monitor:update` artisan command**: syncs the published copy of
  `config/monitor.php` with the package's current template after a
  `composer update`. Adds new config keys introduced by newer package
  versions (preserving their template comment), reports (but never
  overwrites) keys whose value differs from the template default, warns
  about keys the template no longer has, and always refreshes `version`
  to match the actually installed version
  (`Composer\InstalledVersions::getPrettyVersion()`). Run it after every
  `composer update drcantagalo/laravel-monitor` — see README, "Updating".

See README, "Updating".

---

## [0.15.0] - 2026-09-03
### Added
- **Temporary, escalating IP blocking** (`Support/ScraperBlocker::registerOffense()`),
  the base infrastructure for automatic blocking (laravel-monitor 95) —
  first offense blocks for 2h, each subsequent offense before full decay
  doubles the duration (4h, 8h, 16h, ...), a configurable cooldown decays
  `strike_count` back down after quiet periods, and a separate,
  never-decaying `lifetime_offense_count` eventually promotes the block
  to permanent once it crosses a threshold — kept separate from
  `strike_count` specifically so a patient attacker who paces reoffenses
  at or above the cooldown can't stay under the escalation forever (see
  README, "Why two counters, not one"). Modeled after fail2ban/CrowdSec
  instead of a static blocklist, since IPs get reused over time (CGNAT,
  dynamic residential/cloud IPs) and a permanent block can end up
  punishing an unrelated later visitor. This release only adds the
  infrastructure and the schema/query changes needed for it to work
  correctly — nothing yet calls `registerOffense()` automatically (that's
  upcoming automatic-blocking work); manual blocking via
  `updateBlockedIps`/`blockIps` is untouched and stays permanent as
  before.
- New `monitor_blocked_ips` columns: `blocked_until` (nullable, indexed —
  `null` means permanent), `strike_count`, `lifetime_offense_count`,
  `last_offense_at`.
- New config keys: `auto_block_strike_decay_cooldown_days` (default `30`),
  `auto_block_permanent_after_lifetime_offenses` (default `10`),
  `denylist_export_interval_hours` (default `24`).

### Changed
- **Breaking**: `MonitorMethod::isBlocked()` and
  `Support/DenylistExporter::build()` now treat a `blocked_until` in the
  past as not blocked / not exported. Any consumer with custom code
  querying `monitor_blocked_ips` directly and assuming every row is an
  active, permanent block needs to account for the new columns.
  `DenylistExporter::build()` additionally excludes a temporary block
  whose remaining time is shorter than
  `config('monitor.denylist_export_interval_hours')`, to avoid a stale
  web-server-level deny entry outliving the application-level block. See
  README, "Temporary, escalating IP blocking" and "Web-server deny-list
  export".

---

## Future versions
Planned:
- Monitoring API hooks
- Dashboard integration
- Event sampling
- Network activity analysis
