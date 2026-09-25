# Laravel Monitor

**Laravel Monitor** is an experimental package designed to test the initial installation flow for a lightweight Laravel package providing basic CRM tools, access monitoring, and anti-scraper features. Designed to track visits, manage sessions, and detect potentially malicious scrapers.

> ⚠️ **This is an early testing release.**  
> API and config shape may still change between minor versions. See `CHANGELOG.md` for what each release actually added/fixed.

## Requirements

The package depends on Laravel's [Cache](https://laravel.com/docs/cache)
facade — any configured cache driver works, including the framework
defaults `file` and `database` (no external service required). Redis and
Memcached are supported as well. Since `0.30.0`, a failure of the cache
store itself (Redis/Memcached down, etc) no longer takes the blocking
checks down with it: `isBlocked()`/`isPathBlocked()` fall back to
querying the database directly instead of assuming a request is not
blocked — see "Fail-safe against cache store outages" below.

## Updating

First-time setup is `composer require drcantagalo/laravel-monitor` followed
by `php artisan monitor:install` (interactive: terms, publish
config/migrations, migrate, register the installation). `monitor:install`
is **not** meant to run again on every update — running it a second time
would re-ask every prompt for a project that's already set up.

**Dashboard is optional (since `0.31.0`)**: right before asking for the
site URL, `monitor:install` asks whether you want to use the hosted
dashboard/interface (monitor.cantagalo.it). Answering no skips the URL
question and the remote registration entirely — no `local_token` is
generated, and the package's public route (`/monitor/handler`) is never
registered (`config('monitor.dashboard.enabled', true)`, checked in
`MonitorServiceProvider::boot()`). You still get local tracking (the
`Monitor` model, `monitor:audit-paths`, `monitor:export-denylist`, etc) —
just nothing sent to or exposed for the SaaS dashboard. Default is `true`
(yes), so existing installations that never rerun `monitor:install` keep
working exactly as before.

Declining is guaranteed to take effect (since `0.35.0`): if
`config/monitor.php` hasn't been published yet (you answered no to the
config-publish prompt above), `monitor:install` publishes it itself before
writing `dashboard.enabled = false`, so the route really is skipped instead
of silently falling back to the `true` default. If the published file is
from a version older than `0.31.0` and doesn't have the `dashboard` key at
all, the command inserts the block instead of relying on the regex used
for normal updates. In the rare case none of that manages to persist the
value (e.g. `config/monitor.php` isn't writable), the command warns you
explicitly instead of finishing silently, and tells you how to disable the
route by hand (`'dashboard' => ['enabled' => false]` +
`php artisan config:clear`, and `php artisan route:clear` too if routes
are cached).

After a plain `composer update drcantagalo/laravel-monitor` (bumping to a
new version of an already-installed project), run:

```
php artisan monitor:update
```

This keeps your published `config/monitor.php` (a static copy created by
`vendor:publish --tag=monitor-config`) in sync with the package, without
touching anything you've customized:

- Adds any config key that's new in this version (with its explanatory
  comment carried over from the package template) — these keys are
  otherwise invisible in your published file until you update it, even
  though `mergeConfigFrom()` already covers them at runtime with the
  package default.
- **Never** overwrites a key your published file already has — including
  `dashboard_origin` or any other value you've customized. If a key's
  value is still whatever the package used to default to (i.e. you never
  actually customized it and it's now stale), the command only reports
  it — updating it is your call, not the command's.
- Updates the `version` key to the actually-installed version (read from
  Composer, not from the package template), so
  `config('monitor.version')` reported by `monitor:install`'s handshake
  stays accurate.
- Warns about any key your published file still has that no longer
  exists in the current template — usually means it was removed or
  renamed in a breaking change (check `CHANGELOG.md`).

Always safe to run again — it's idempotent (a second run with no new
package version is a no-op).

`composer update` only refreshes `vendor/` — it never runs `migrate`.
Package migrations are auto-discovered at runtime
(`loadMigrationsFrom()`, no publish needed), but still need to actually
run against your database. If the command finds package migrations that
haven't been applied yet, it lists them and asks (default yes) whether to
run `php artisan migrate` right there — same pattern as the migration
prompt in `monitor:install`.

`monitor:update` is fully translated (en/it/pt), same as `monitor:install`
— but it doesn't ask which language to use every time, since it's meant
to be run routinely rather than once. It reuses the language chosen
during `monitor:install` (persisted in
`storage/monitor/installation.json`); falls back to English if that file
or the `lang` key isn't there (installations done before this).

## Aggregated dashboard totals (`getData`)

`GET /monitor/handler?action=getData` — same auth as the other read
actions (permanent `local_token` **or** the ephemeral read token from
`issueReadToken`).

**Breaking change in `0.10.0`**: this endpoint used to return every
`monitors` row as-is (`{"success": true, "data": [...]}`), which meant
loading the *entire* table into memory and serializing it in one response.
That's fine on a fresh install, but it reliably exhausts PHP's
`memory_limit` once the table grows into the tens of thousands of rows
(confirmed in production at ~19.5k rows — see CHANGELOG `[0.10.0]`).
`getData` now returns only pre-aggregated totals, computed without ever
loading a full `Monitor` row into PHP:

```json
{
  "success": true,
  "visitors_total": 19532,
  "visits_total": 84210,
  "sessions_total": 21044,
  "unique_ips_total": 8117,
  "blocked_attempts_total": 342,
  "package_version": "0.47.0",
  "config_version": "0.46.0"
}
```

- **`visitors_total`**: `Monitor::count()` — one row per recognized
  device/browser (see "Remember-me" above).
- **`visits_total`**: `COUNT(*)` of `monitor_visits` (see "Visits"
  below) — one row per **new PHP session**, not per page view. A visitor
  browsing multiple pages in the same session is one visit. Only counts
  visits recorded since `0.42.0` (until `0.41.0` this was a `SUM` of
  `data.visits` over the JSON blob, which is no longer written) and stays
  `0` with `monitor.track_visits` turned off.
- **`sessions_total`**: since `0.42.0` a visit **is** a PHP session, so
  this is the same number as `visits_total`. Both keys are kept in the
  response for backward compatibility.
- **`unique_ips_total`**: `IpStat::count()` — reuses `monitor_ip_stats`
  (see "Per-IP stats" below), which already keeps exactly one row per
  unique IP ever seen. Deliberately **not** a dedupe of
  `monitor_visit_ips` across every `Monitor`.
- **`blocked_attempts_total`**: unchanged, see "Blocked-attempt counter"
  below.
- **`package_version`** (since `0.46.0`): the actually-installed package
  version, read from `Composer\InstalledVersions::getPrettyVersion(
  'drcantagalo/laravel-monitor')` — **not** from `config('monitor.version')`.
  Deliberately not the config value: `config/monitor.php` is published
  (`vendor:publish --tag=monitor-config`) as a **static copy** in the
  host project, so once published its `'version'` key stays frozen at
  whatever it was the last time `monitor:install`/`monitor:update` wrote
  it — it drifts from the real installed version the moment the package
  is updated without running `monitor:update` right after (same problem
  `MonitorUpdateCommand` already solves for that specific key by reading
  `InstalledVersions` itself, ver `MonitorUpdateCommand`). `getData`
  always reflects what Composer actually installed. `null` if the
  package somehow isn't registered in Composer's `installed.php` (e.g. a
  manual symlink outside the normal Composer flow) — fails open, never
  breaks `getData`.
- **`config_version`** (since `0.47.0`): `config('monitor.version')` —
  the frozen value from the host project's **published**
  `config/monitor.php`, i.e. whatever `monitor:install`/`monitor:update`
  last wrote into it (see `MonitorUpdateCommand`). Sent deliberately
  *alongside* `package_version`, not instead of it: the two keys
  diverging (`package_version` ahead of `config_version`) is exactly the
  signal that the client ran `composer update` but never followed up
  with `php artisan monitor:update` — which means both the published
  config *and* the package's pending migrations are out of date (see
  `MonitorUpdateCommand::confirmPendingMigrations()`). Clients on a
  package version older than `0.47.0` don't send this key at all.
- `visitors_total`/`visits_total`/`sessions_total`/`unique_ips_total`
  (added in `0.10.0`) share a short, fixed cache TTL
  (`config('monitor.data_totals_cache_ttl_seconds')`, default `45`
  seconds — same rationale as `block_results_cache_ttl_seconds`) and
  fail open to `0` if the underlying table/column isn't there yet on an
  older, not-yet-migrated install (logged via `Log::warning`).
  `package_version`/`config_version` are **not** cached (plain
  in-memory lookups, no I/O) and `blocked_attempts_total` has its own
  separate cache, see "Blocked-attempt counter" below.

If your integration was reading the raw `data` array from `getData`,
there is no drop-in replacement — it was removed entirely rather than
turned into a paginated sample, since no known consumer needed row-level
detail from this specific action (row-level detail is what `getPages`/
`getVisitorsByIp`/`getUserMonitors` are for). See CHANGELOG `[0.10.0]` for
the full rationale.

## Remember-me (returning visitor recognition)

When `MonitorMethod` creates a new `Monitor` record for a first-time
visitor (session-based, non-bot request), it generates a random token,
stores it in the `monitors.id_token` column (unique index; until `0.41.0`
it lived in `data['id-token']` and was looked up by scanning the JSON of
every row), and attaches it to the response as a long-duration cookie so the same browser can be recognized again after its
PHP session expires.

> ⚠️ **Breaking change in v0.2.0**: the public `GET /monitor/remember-me`
> route was removed — the package no longer opens an HTTP route for this
> without the host app's explicit awareness. Use `Monitor::recognize()`
> below instead (server-side, no HTTP round-trip). See CHANGELOG for the
> migration note.

Contract for the host application:

- **Cookie name**: `config('monitor.remember_cookie')`, default
  `monitor_id_token`. Duration: `config('monitor.remember_cookie_days')`
  days, default 1825 (5 years). The package sets this cookie itself — the
  host app never needs to create or read it directly (it isn't meant to
  be parsed from `document.cookie`; the browser just carries it back
  automatically on every request).
- **Automatic reconnection (since v0.1.22)**: `MonitorMethod` also
  checks the `monitor_id_token` cookie directly, on the very first request
  of a new PHP session — before anything else has had a chance to run.
  This closes a race present in earlier versions, where the first page
  load of a new session always created a brand-new `Monitor` (overwriting
  the cookie with a fresh token) before anything below could ever run,
  permanently losing the original visitor's identity. Host apps don't
  need to do anything for this — it's transparent — but it means the
  cookie alone is now sufficient; `Monitor::recognize()` is a
  belt-and-suspenders option, not a requirement, for host apps that want
  to force reconnection at a specific point (e.g. right after consuming a
  cookie-consent flow that may have delayed the first tracked request).
- **`Monitor::recognize(): bool`**. Call it server-side, from within the
  same request that should pick up the returning visitor — it reads the
  cookie above off the current request (`request()->cookie(...)`, no HTTP
  call involved) and looks up the matching `Monitor` row. Returns `true`
  when a matching visitor was found (and merged into the current PHP
  session), `false` when there's no cookie yet (first-ever visit) or no
  matching record (cookie is stale/invalid).

  ```php
  use Drcantagalo\LaravelMonitor\Facades\Monitor;

  if (Monitor::recognize()) {
      // returning visitor recognized and merged into this session
  }
  ```
- Under the hood, `recognize()` sets `session(['remember_me' => $token])`;
  the `MonitorMethod` middleware picks that up on the very same request
  (it runs after your code, on the way back out) and merges the returning
  visitor into the current PHP session — same mechanism as before, just
  triggered by a direct method call instead of an HTTP request.

## Arbitrary visitor data (segmentation/tags)

Lets the host application attach arbitrary key/value pairs (language,
tags, preferences, etc.) to the `Monitor` record of the current visitor
session — a segmentation/tagging base, not a CRM/lead system yet.

> ⚠️ **Breaking change in v0.2.0**: the public `GET /monitor/update-data`
> route was removed — same rationale as `remember-me` above. Use
> `Monitor::tag()` below instead.

- **`Monitor::tag(array $data): bool`**. Call it server-side. Requires an
  active monitor session (i.e. `MonitorMethod` must have already run at
  least once for this visitor — same precondition as `recognize()`).
  Returns `true` on success; `false` when there's no active monitor
  session yet, or when `$data` is empty.

  ```php
  use Drcantagalo\LaravelMonitor\Facades\Monitor;

  Monitor::tag(['lang' => 'pt', 'tags' => ['newsletter']]);
  ```
- **Protected keys**: `sessions`, `ips`, `visits`, `page`, `id-token`, `ua`,
  `user_id` (`Drcantagalo\LaravelMonitor\Support\Monitor::PROTECTED_DATA_KEYS`)
  are silently ignored if present in `$data` — these are written
  exclusively by `MonitorMethod`/`Monitor::newVisit`, and letting a caller
  overwrite them would corrupt tracking. Every other key is accepted
  freely (schema intentionally left open — see below).
- Design note: the schema is deliberately unconstrained so it can later
  support linking a visitor to a real lead/contact, an opt-in shared
  blacklist across sites, or an external IP-reputation feed — none of
  which this action builds today.

## Authenticated user tagging

Ties a `Monitor` row (device/browser) to the host app's authenticated
user, for CRM linkage later ("if this visitor has logged in before, tag
their row with that").

- **Contract: tag, not merge.** The Monitor data model is 1 row per
  device/browser, recognized via the `remember_cookie` (see
  Remember-me above) — a user logged in on 2 devices already produces
  2 rows today, and that's expected. This feature does **not** change
  that: it only writes `data['user_id']` (`Auth::id()`) onto the
  current device's row, alongside `ua`/`ips`/`page`. It never merges
  or reassigns rows by `user_id`. If you need "one record per
  customer" for a CRM view, do that aggregation at read time
  (`Monitor::forUserId($id)->get()`, joining the rows yourself — see
  "Querying by user_id" below) — never a physical merge of the raw
  rows, which would race under concurrent writes from multiple
  devices.
- **When it runs**: every request tracked by `SessionVisitorTracker`
  (session-based visitors) where `Auth::check()` is true, right after
  the Monitor row for the current request has already been
  found/created. Anonymous tracking (`AnonymousVisitorTracker`, no
  session — used for the 404/scraper-detection flow above) is
  unaffected; this is a session-visitor-only feature.
- **Config**: `track_authenticated_user` (default `true`). Set to
  `false` to opt out — e.g. host apps without `Auth` configured, or
  that don't want this data for privacy-policy reasons.
- **Protected key**: like `sessions`/`ips`/`visits`/`page`/`id-token`/`ua`,
  `user_id` is in
  `Drcantagalo\LaravelMonitor\Support\Monitor::PROTECTED_DATA_KEYS` — a
  call to `Monitor::tag()` (see "Arbitrary visitor data" above) can never
  overwrite it, so it can't be spoofed onto a row by mistake.

## Querying by user_id (CRM index)

To support CRM lookups ("show me every device row for this customer")
without a full table scan, the migrations add a generated column
`monitors_user_id` (extracted from `data['user_id']`, VIRTUAL, MySQL-only —
see caveat below) with an index (`monitors_user_id_idx`) on the `monitors`
table.

- **Use `Monitor::forUserId($id)`, not `Monitor::where('data->user_id',
  $id)`.** This is not just a style preference: `where('data->user_id',
  $id)` compiles to `json_unquote(json_extract(\`data\`, '$."user_id"'))`,
  and even though that's the *exact* expression the generated column is
  defined with, MySQL's optimizer does not match it to the column/index
  automatically — confirmed via `EXPLAIN` on real MySQL 8 (`type: ALL`,
  full table scan, `possible_keys: NULL`). Only querying the generated
  column by name uses the index. `Monitor::forUserId($id)` (a scope on the
  `Monitor` model) does this for you: `where('monitors_user_id', $id)`.
- **Why the scope casts `$id` to a string**: `monitors_user_id` is
  `VARCHAR`. Comparing it against a native PHP int through PDO (e.g.
  `Auth::id()`, which is an int) makes MySQL list the index under
  `possible_keys` but not actually use it (`key: NULL`) — an implicit
  type-conversion cost, also confirmed via `EXPLAIN`. `forUserId()` casts
  to `(string)` internally so the comparison is always string-vs-string
  and the index is used (`type: ref`) regardless of what type you pass
  in.
- **MySQL-only.** The generated column's expression
  (`json_unquote(json_extract(...))`) is MySQL syntax. The migration is
  driver-aware: it only creates the generated column + index when
  `Schema::getConnection()->getDriverName() === 'mysql'`, and is a no-op
  on any other driver (sqlite, pgsql) — `down()` is guarded the same way.
  `data['user_id']` itself is always written regardless of driver, this
  only affects whether lookups by it are indexed. On a non-MySQL host,
  `Monitor::forUserId($id)` automatically falls back to
  `where('data->user_id', $id)` (no index, full scan, but correct)
  instead of erroring on the missing generated column — note this
  fallback does **not** cast `$id` to string like the MySQL path does:
  SQLite's `json_extract` returns the JSON value in its native storage
  type (e.g. an integer for `{"user_id": 42}`), and `'42'` (text) never
  equals `42` (integer) there, so the fallback must compare against the
  same type `$id` was passed in as (in practice always an int, from
  `Auth::id()`).

## User listing (`getUsers`, `getUserMonitors`)

Same auth as `getData`/`getPages`/`getVisitorsByIp` (permanent
`local_token` **or** the ephemeral read token from `issueReadToken`) —
built for a dashboard's CRM view: "who are my authenticated users, and
what did each of them do".

- **`getUsers`**: paginated, aggregated listing — one row per
  `user_id` seen in `data['user_id']` (see "Authenticated user
  tagging" above; rows without a `user_id` are excluded). Params:
  `page` (default `1`), `per_page` (default `25`, max `100`). Response:
  `{"success": true, "data": [{"user_id": "42", "visits_count": 7,
  "last_activity": "2026-08-30T12:00:00+00:00", "name": null, "email":
  null}, ...], "meta": {"page", "per_page", "total", "last_page"}}`,
  ordered by `last_activity` descending. Aggregation
  (`COUNT` of visits/`MAX(monitors.updated_at)`) and pagination run in
  SQL, grouped by the same indexed generated column
  `Monitor::forUserId()` uses on MySQL (`monitors_user_id`) — never the
  raw `data->user_id` expression, for the same index-matching reason
  documented above.
  - `visits_count` counts `monitor_visits` rows per `user_id` (a
    `LEFT JOIN`, so a `Monitor` with no visit recorded yet still counts
    as `0`) instead of counting `Monitor` rows. A `Monitor` row is
    reused across sessions for the same device/browser (reconnected via
    the remember-me cookie), not created per visit, so counting rows
    used to always give `1` for a user who only ever visits from the
    same browser. (`0.12.0`–`0.41.0` summed `data.visits` from the JSON
    blob instead, which is no longer written.)
- **`getUserMonitors`** (since `0.48.0`, replacing `getUserVisits` —
  see "⚠️ Breaking" in CHANGELOG `[0.48.0]`): given a `user_id`
  (`{"success": false, "message": "user_id is required"}`, `422`, if
  missing/empty), a **paginated** listing of that user's `Monitor` rows
  (devices/browsers), via `Monitor::forUserId($id)` — the exact same
  response shape as `getIpMonitors` below (`id`, `created_at`,
  `updated_at`, `data` sanitized, `ips`, `visits_count`), sharing its
  `hydrateMonitorRows` helper. Params: `page` (default `1`), `per_page`
  (default `20`, max `100`). Ordered by `updated_at` descending, `id`
  descending as a deterministic tiebreaker — same pattern as
  `getIpMonitors`. From here, the dashboard navigates user → Monitors →
  a specific Monitor's full visit history (`getMonitorVisits`), the same
  way `getIpMonitors` already lets it navigate from an IP.
  - `user_id` is cast to `int` before calling `Monitor::forUserId()` —
    see "Querying by user_id" above for why passing the raw query-string
    value through would silently return zero rows on any non-MySQL host.
  - Unlike the `getUserVisits` action it replaces, this does **not**
    rebuild `data.page`/`data.ips` or attach each row's last 20 visits —
    it returns the same lean shape as `getIpMonitors` (`ips`,
    `visits_count`), leaving the full per-Monitor journey to
    `getMonitorVisits` on demand. Avoids loading potentially hundreds of
    visits per row just to list a user's devices.
- **`name`/`email`**: the package never queries a host app's `users`
  table (arbitrary schema, out of scope for a host-agnostic package).
  Instead, `getUsers` opportunistically reads `data['name']`/
  `data['email']` off that user's most recently updated `Monitor` row
  — they only appear when the host app already called
  `Monitor::tag(['name' => $user->name, 'email' => $user->email])` (see
  "Arbitrary visitor data" above; `name`/`email` are not in
  `PROTECTED_DATA_KEYS`) somewhere in its own request lifecycle, e.g.
  right after login. Without that call, both come back `null` and the
  dashboard falls back to displaying the raw `user_id`.

Cached the same way as `getVisitorsByIp`/`getBlockedIps`
(`Cache::remember`, TTL `config('monitor.listings_cache_ttl_minutes')`,
the shared `monitor:listings:version` counter) — since this data
changes on every tracked visit rather than through an explicit admin
action, staleness here is bounded by the TTL alone, same as `getPages`.

### Sanitizing the `data` blob (since `0.46.0`)

Every response that exposes a `Monitor`'s `data` blob (`getUserMonitors`
above, `getIpMonitors` below) runs it through
`Support\DataSanitizer::sanitize()` first — one shared helper, so the rule
lives in exactly one place instead of being duplicated per action. Today
it only strips the legacy `id-token` key: before `0.42.0`
(`monitors.id_token`, its own unique-indexed column) the remember-me
cookie's value lived at `data['id-token']`, and the migration that
introduced the column only **read** that key to backfill the new column —
it never deleted it from the blob. A `Monitor` row created before `0.42.0`
can therefore still carry the raw remember-me token (effectively a
credential — whoever has it can be recognized as that visitor) inside
`data` forever, unless sanitized on the way out. The real `id_token`
column itself is a separate concern and is handled the ordinary way: every
action's `select()`/`toArray()` simply never includes that column in the
first place.

## Ephemeral read token + dedicated CORS (dashboard direct fetch)

The dashboard (`monitor.cantagalo.it`) can call `/monitor/handler?action=getData`
directly from the end user's browser instead of always proxying through the
host application's server. The permanent `local_token` never leaves the host
application's backend — only a short-lived, read-only token does.

- **`issueReadToken`** (`Authorization: Bearer <local_token>`, same auth as
  the other admin actions): generates a random token, stores it in cache for
  `config('monitor.read_token_ttl_minutes')` minutes (default 15), and
  returns `{"success": true, "token": "...", "expires_at": "..."}`.
- The token returned by `issueReadToken` is accepted as a bearer **only
  for read-only actions (`getData`, `getPages`, `getVisitorsByIp`,
  `getVisitorPaths`, `getBlockedIps`, `getBlockedPaths`, `getUsers`,
  `getUserMonitors`, `getBlockResults`, `getIpMonitors`, `getMonitorVisits`
  — `getIpMonitors`/`getMonitorVisits` since `0.46.0`, `getUserMonitors`
  since `0.48.0`)**.
  `clearData`, `pruneData`,
  `updateBlockedIps`, `unblockIp`, `flagScraperPath`, `unflagPath`,
  `updateRules`, and `issueReadToken` itself always require the
  permanent `local_token` — a read token cannot mint another token or
  do anything beyond reading.
- **CORS**: routes under `monitor/*` carry their own dedicated CORS
  middleware (`MonitorCors`) — it does not read or depend on the host
  application's `config/cors.php`, since every client site has a different
  Laravel install. The allowed origin is `config('monitor.dashboard_origin')`,
  defaulting to `https://monitor.cantagalo.it` (no manual configuration
  required). Preflight `OPTIONS` requests get a `204` with the CORS headers
  attached.

## 404 tracking + scrapper path blocking

`MonitorMethod` records, per visited path, whether the response was a
`404` (`monitor_page_hits.not_found`, sticky once set; `data.not_found[path]`
until `0.41.0`) — aggregated by `getPages` (see
"Paginated page listing" below) into a `not_found` flag per path, letting
a dashboard flag paths that don't actually exist on the monitored site (a
common scraper tell: `/wp-admin/install.php` on a site that isn't
WordPress). Since `0.10.0`, `getData` no longer exposes raw `Monitor` rows
at all (see "Aggregated dashboard totals" below) — this per-path detail
only ever came from `getPages`.

> **Requires a `Route::fallback()` in the `web` middleware group to catch
> genuinely nonexistent paths.** `MonitorMethod` only runs for requests
> that actually reach a matched route (it's route-group middleware, not
> global) — a path with **no matching route at all** never enters the
> `web` group and never sees the middleware, so it can't be tracked as
> 404, on a vanilla Laravel install with no fallback route. This still
> covers 404s returned by a matched route/controller (e.g. `abort(404)`
> for a missing resource) either way. Adding a fallback route to
> `routes/web.php` closes the gap for completely unknown paths too — but
> **the closure must return a real `404` HTTP status**, not just a view
> that looks like one:
>
> ```php
> // Wrong — view() alone responds 200 OK, so MonitorMethod (and every
> // crawler/monitoring tool) sees a successful page, not a 404.
> Route::fallback(fn () => view('errors.404'));
>
> // Right — the status code is what actually matters here.
> Route::fallback(fn () => response()->view('errors.404', [], 404));
> // or simply:
> Route::fallback(fn () => abort(404));
> ```
>
> This is an easy mistake to make and easy to miss in manual testing (the
> page *looks* identical either way) — it was found live in more than one
> host app integrating this package, always with the same root cause: a
> `view(...)` call with no explicit status.
>
> **Since `0.39.0`, this also decides whether a honeypot's first-hit `404`
> (see "Honeypot hits" below) is actually indistinguishable from a genuine
> one.** `MonitorMethod`'s own `abort(404)` runs *before* this fallback is
> ever reached, so the two responses only end up byte-identical because
> Laravel's exception handler renders the same thing for any thrown `404`
> — a `resources/views/errors/404.blade.php`, if the host app has one
> (picked automatically for both), or the framework's own default 404 page
> otherwise. A fallback that returns a body built by hand instead — e.g.
> `Route::fallback(fn () => response('Not found', 404))` — breaks that
> equivalence: the honeypot 404 (rendered by the exception handler) and the
> fallback's genuine 404 (the hand-built body) would have different
> content even though both are status `404`, reopening the same tell this
> feature exists to close. Stick to one of the two patterns above.

- **`flagScraperPath`** (`Authorization: Bearer <local_token>`, same auth
  as `updateBlockedIps`/`clearData` — never accepted with the ephemeral
  read token): `POST /monitor/handler?action=flagScraperPath` with
  `{"path": "wp-admin/install.php"}` (host-less; a leading `/` is
  stripped if present). Two things happen:
  1. The path's row in `monitor_paths` gets `status: 'trap'`
     (`updateOrCreate`, since `0.20.0` — overwrites a `'safe'` status if
     the path was previously marked safe via `markPathSafe`, making
     flagging and marking safe mutually exclusive; see "Path review
     state" below). From then on, `MonitorMethod` rejects any request
     whose path matches, **regardless of host** — an installation shared
     by multiple subdomains is protected on all of them at once, since the
     block check ignores the host prefix that tracked paths carry
     (`host/path`, in `monitor_page_hits.path`). **Since
     `0.39.0`**, the rejection is `404` (indistinguishable from any other
     nonexistent route) on the first hit from an IP not yet blocked, and
     `403` from then on once that IP is itself in `monitor_blocked_ips` —
     see "Honeypot hits" below for why.
  2. Every IP already recorded (`monitor_visit_ips`) against a `Monitor` that
     visited that path is blocked in `monitor_blocked_ips` (`source:
     'scraper-path'`), same mechanism as `updateBlockedIps`.
  - Response: `{"success": true, "path": "...", "blocked_ips": [...]}`,
    or `{"success": false, "message": "No path provided"}` (422) if
    `path` is missing/empty.
  - **Guard since `0.21.0`**: before either step runs, the same `Monitor`
    scan used to find IPs to block also checks whether the path already
    resolved as a real, non-404 page in some host (`data.not_found`
    empty/false for a matching key). If so, the call is **refused** —
    nothing is written to `monitor_paths`, no IP is blocked — and the
    response is `{"success": false, "message": "\"login\" also resolves
    as a live route at \"cantagalo.it/login\" — refusing to flag it as a
    trap"}` (422). Because the block ignores host, flagging a path that's
    a real route on even one host would 403 real users on every other
    host that route shares the suffix with. No override in this version.
  - **Fixed in `0.20.1`**: the `Monitor` table scan for step 2 used to
    load every row into memory at once (`Monitor::all()`), same class of
    bug as `getData` in `0.10.0` above — confirmed exhausting PHP-FPM's
    `memory_limit` in production at ~31.7k rows, returning a bare 500
    with no body. Now uses `Monitor::cursor()` (one row hydrated at a
    time), same matching logic, no response shape change.
  - **Fixed in `0.26.0`**: `Monitor::cursor()` still meant the guard and
    the IP scan cost time proportional to the whole `monitors` table on
    every call (same bug class fixed for `getPages`/`getVisitorPaths`/
    `PathsAuditor::audit()` in `0.23.0`-`0.25.0`, now on the write side —
    confirmed timing out in production at ~41k `Monitor` rows). Both the
    live-route guard and the IP lookup now read from `monitor_page_hits`
    (`0.23.0`): the guard is a suffix-index lookup against
    `WHERE not_found = false` (same technique as
    `PathsAuditor::liveTrafficSuffixIndex()`), and the IP scan resolves
    the small set of matching `monitor_page_hits` rows
    (`WHERE not_found = true`) first, then reads `monitor_visit_ips` only
    for those specific `monitor_id`s (until `0.41.0` this decoded
    `Monitor.data.ips`). Same matching logic, no response shape change.

- **`flagScraperPaths`** (same auth as `flagScraperPath`, since `0.19.0`):
  batch version — `POST /monitor/handler?action=flagScraperPaths` with
  `{"paths": ["wp-admin/install.php", ".env", ...]}`. Same effect as
  calling `flagScraperPath` once per path (each path blocked, every IP
  that visited any of them blocked too), but as a single request instead
  of one HTTP call per path — avoids bursting the caller's own client
  with N requests when flagging dozens/hundreds of paths at once (e.g. a
  "select many, flag as trap" bulk action, or an automated triage run).
  Also more efficient server-side: the `Monitor` table scan needed to
  find IPs that visited the flagged paths happens once for the whole
  batch, not once per path. Invalid entries (non-string, empty after
  trimming) are silently skipped rather than failing the whole batch.
  Response: `{"success": true, "paths": [...only the ones actually
  flagged...], "rejected": [{"path": "...", "reason": "..."}, ...],
  "blocked_ips": [...]}`, or `{"success": false, "message": "No paths
  provided"}` / `"No valid paths provided"` (422) if `paths` is
  missing/empty or every entry was invalid.
  - **Guard since `0.21.0`**: same live-route check as `flagScraperPath`,
    applied per path — a path that resolves as a real route on some host
    is rejected and reported under `rejected` (with why), without
    aborting the rest of the batch. `rejected` is always present (empty
    array when nothing was refused).
  - **Fixed in `0.26.0`**: same fix as `flagScraperPath` above, but it
    matters even more here — the whole-table scan used to happen once per
    batch (still `O(monitors rows)`, not `O(paths in the batch)`), so a
    large batch (e.g. `TriageMonitorPathsJob`) made the fixed per-call
    cost of the old scan land on every triage run. Same `resolveScraperPathTargets()`
    helper as the singular version, called once for the whole batch.

- **`unflagPath`** (same auth as `flagScraperPath`): reverts it —
  `POST /monitor/handler?action=unflagPath` with
  `{"path": "wp-admin/install.php"}` removes the path's `status: 'trap'`
  row from `monitor_paths` and clears the corresponding
  `MonitorMethod::isPathBlocked()` cache entry immediately. Response:
  `{"success": true, "path": "...", "was_flagged": true|false}` (`false`
  when the path wasn't flagged to begin with — not an error). Does
  **not** unblock the IPs `flagScraperPath` may have blocked because of
  that path — use `unblockIp` for those individually.

- **`markPathSafe`** (same auth as `flagScraperPath`, since `0.4.0`):
  `POST /monitor/handler?action=markPathSafe` with
  `{"path": "old-campaign-link"}` (host-less, same convention as
  `flagScraperPath`) records the path in `monitor_paths` with
  `status: 'safe'` and `reviewed_at: now()` (`updateOrCreate`, since
  `0.20.0` — overwrites a `'trap'` status if the path was previously
  flagged via `flagScraperPath`, making the two mutually exclusive).
  Purely a review-state flag — unlike `flagScraperPath`, it blocks
  nothing; it just removes the path from `getPages`' `pending_review`
  queue (see below) once a human has confirmed a recurring `404` isn't a
  scraper probe (e.g. an old link that was removed on purpose). Response:
  `{"success": true, "path": "...", "status": "safe"}`, or `{"success":
  false, "message": "No path provided"}` (422) if `path` is
  missing/empty.
  - **Guard since `0.21.0`**: only writes `status: 'safe'` when at least
    one hit with `data.not_found = true` exists for that path in some
    host — otherwise the review protects nothing (it's orphaned data
    from the start). Rejected with `{"success": false, "message":
    "\"...\" has no recorded 404 — marking it safe would not protect
    anything"}` (422) when there's no such evidence. No override in this
    version.
  - **Fixed in `0.26.0`**: the guard used to scan and JSON-decode
    `Monitor.data.not_found` row by row (`Monitor::cursor()`) — same bug
    class fixed for `getPages`/`getVisitorPaths`/`PathsAuditor::audit()`
    in `0.23.0`-`0.25.0`, now on the write side. Now a suffix-index
    lookup against `monitor_page_hits WHERE not_found = true` (same
    technique as `PathsAuditor::liveTrafficSuffixIndex()`), no `Monitor`
    scan at all. Same guard behavior, no response shape change.
- **`markPathsSafe`** (same auth as `flagScraperPath`, since `0.19.0`):
  batch version of `markPathSafe` — `POST
  /monitor/handler?action=markPathsSafe` with `{"paths":
  ["old-campaign-link", ...]}`. Same rationale as `flagScraperPaths`:
  avoids one HTTP request per path when clearing many entries from the
  `pending_review` queue at once. No blocking side effect, same as the
  singular version. Response: `{"success": true, "paths": [...only the
  ones actually marked...], "rejected": [{"path": "...", "reason":
  "..."}, ...]}`, or `{"success": false, "message": "No paths provided"}`
  / `"No valid paths provided"` (422).
  - **Guard since `0.21.0`**: same 404-evidence check as `markPathSafe`,
    applied per path — a path with no recorded 404 anywhere is rejected
    and reported under `rejected`, without aborting the rest of the
    batch.
  - **Fixed in `0.26.0`**: same fix as `markPathSafe` above — the
    per-batch `Monitor` scan (still `O(monitors rows)` even though it
    checked every path in the batch in one pass) is now a single suffix-
    index lookup against `monitor_page_hits`, built once for the whole
    batch.

- **`unmarkPathSafe`** (same auth): reverts it — `POST
  /monitor/handler?action=unmarkPathSafe` with `{"path":
  "old-campaign-link"}` deletes the path's `status: 'safe'` row from
  `monitor_paths`, so the path goes back to the default `pending` state.
  Response: `{"success": true, "path": "...", "was_safe": true|false}`
  (`false` when the path wasn't marked safe to begin with — not an
  error).

> **Path review state (`monitor_paths`)**: since `0.20.0`, a path's
> `trap`/`safe` status lives in a single table, one row per path (no row
> = `pending`, the default). Before `0.20.0` these were two independent
> tables (`monitor_blocked_paths` for `trap`, `monitor_path_reviews` for
> `safe`) with no relationship between them — a path could end up marked
> both at once, a contradictory state the old code didn't catch (seen
> live in production). `flagScraperPath(s)`/`markPathSafe(s)` now write
> to the same row (`updateOrCreate`), so flagging a path clears a prior
> `safe` status and vice versa. See CHANGELOG `[0.20.0]` for the
> migration that merges the old tables (existing data included; a
> conflicting path is resolved to `trap`, logged via `Log::warning`
> during the migration).

## Manual IP blocking (`updateBlockedIps`)

Blocks a list of IPs outright — `MonitorMethod` rejects (`403`) any
request from a blocked IP before any tracking/detection logic runs,
regardless of host or session state. This is the same underlying
mechanism `flagScraperPath` uses automatically for IPs seen on a flagged
path; `updateBlockedIps` is the manual/direct version, for blocking IPs
that weren't (or don't need to be) tied to a specific path.

- **Endpoint**: `POST /monitor/handler?action=updateBlockedIps`,
  `Authorization: Bearer <local_token>` (the permanent admin token — same
  auth as `clearData`/`flagScraperPath`/`updateRules`/`issueReadToken`;
  the ephemeral read token from `issueReadToken` is never accepted here).
- **Request body**: `{"ips": ["203.0.113.7", "198.51.100.42"]}`. Each
  entry is validated with `filter_var(..., FILTER_VALIDATE_IP)` (accepts
  both IPv4 and IPv6); invalid entries are silently skipped rather than
  failing the whole request.
- **Response**: `{"success": true, "blocked": ["203.0.113.7", "198.51.100.42"]}`
  listing only the IPs that actually validated and got (or already were)
  blocked. `{"success": false, "message": "No IPs provided"}` (422) when
  `ips` is missing/empty/not an array; `{"success": false, "message": "No valid IPs provided"}`
  (422) when every entry failed validation.
- Persisted in `monitor_blocked_ips` with `source: 'manual'` (vs.
  `source: 'scraper-path'`/`'auto-signal'`/etc. for IPs blocked
  automatically) — same table, same mechanism (`ScraperBlocker::registerOffense`,
  see below), so every source composes into one reputation history per IP.
  The per-IP block-check cache (`config('monitor.blocked_ip_cache_ttl')`,
  default 60s) is invalidated immediately for every IP in the request, so
  the block takes effect on the very next request instead of waiting out
  the cache TTL.

- **`unblockIp`** (same auth as `updateBlockedIps`): reverts it (and any
  automatic block on that IP) — `POST /monitor/handler?action=unblockIp`
  with `{"ip": "203.0.113.7"}` removes the IP from `monitor_blocked_ips`
  (whatever its `source`) and clears the block-check cache immediately.
  Response: `{"success": true, "ip": "...", "was_blocked": true|false}`
  (`false` when the IP wasn't blocked to begin with — not an error), or
  `{"success": false, "message": "No valid IP provided"}` (422) if `ip`
  is missing/invalid. Unlike `updateBlockedIps`, `unblockIp` does **not**
  go through `ScraperBlocker` — it deletes the `monitor_blocked_ips` row
  outright, resetting `strike_count`/`lifetime_offense_count` completely.
  A manual unblock normally means "this should never have been blocked"
  (an internal test IP, a false positive), so resetting the history is
  the right behavior — different from letting a temporary block expire
  on its own.

**Since `0.29.0`**: `updateBlockedIps` goes through the same escalating
mechanism as automatic blocking (see below) instead of creating a
permanent `BlockedIp` directly — see "Temporary, escalating IP blocking"
for what that means for a manually blocked IP (it now expires and can
become permanent through repeated offenses, same as an automatically
blocked one). `unblockIp` is unchanged (see above).

## Temporary, escalating IP blocking (`ScraperBlocker`)

Since `0.15.0`, `monitor_blocked_ips` supports **temporary, escalating**
blocks (`ScraperBlocker::registerOffense(string $ip, string $source)`),
modeled after fail2ban/CrowdSec rather than a static blocklist. Originally
only the *automatic* path used this (honeypot hits, scraper-signal
thresholds — see the changelog entries for the versions that wire each
trigger up); **since `0.29.0`, manual blocking via `updateBlockedIps` uses
the exact same mechanism** (`source: 'manual'`) — unifying manual and
automatic reputation into a single escalation ladder: recidivism from
either source (an IP re-blocked manually after a previous block expired,
or re-flagged automatically) accumulates toward the same
`lifetime_offense_count`, eventually becoming permanent
(`auto_block_permanent_after_lifetime_offenses`, default 10) regardless of
which path triggered each individual offense. `unblockIp` stays outside
this ladder — see above.

Why temporary: IPs get reused over time (CGNAT, dynamic residential IPs,
elastic cloud IPs), so a permanent block from one bad actor can end up
punishing an unrelated legitimate visitor who inherits that IP months
later. Escalating, self-expiring blocks make automatic blocking safe
enough to not require a human reviewing every flagged IP first.

Two separate counters drive this, on purpose — see "Why two counters"
below for the bug this avoids:

- `strike_count`: decays over time, drives only how long *this* block
  lasts.
- `lifetime_offense_count`: never decays, only ever increments, drives
  the permanent-promotion threshold.

- **First offense**: `strike_count`/`lifetime_offense_count` both start
  at `1`, block lasts `2^1 = 2` hours.
- **Each subsequent offense** — an offense committed *after* the previous
  block has expired (the IP came back and reoffended) — doubles the
  exponent on `strike_count`: 2nd offense → `2^2 = 4h`, 3rd → `2^3 = 8h`,
  4th → `2^4 = 16h`, and so on. `lifetime_offense_count` increments by 1
  each time.
- **One attack is one offense (since `0.44.0`)**: while an IP's block is
  in force (permanent, or temporary and not yet expired), a new offense
  counts for nothing — `strike_count`, `lifetime_offense_count`,
  `blocked_until`, `last_offense_at` and `source` stay exactly as they are.
  The counters exist so an IP that "regenerated" can come back later and,
  if it reoffends after each block, be blocked for longer and eventually
  permanently; how many infractions it committed inside a single attack is
  irrelevant. Before `0.44.0`, flagging many honeypot paths at once
  (`flagScraperPaths`, e.g. the AI triage marking 125 paths) registered one
  offense *per path* for every IP that had hit them: 125 strikes and a
  permanent block from the first batch. `flagScraperPaths` now also
  registers a single offense per IP for the whole batch.
- **Decay**: every `config('monitor.auto_block_strike_decay_cooldown_days')`
  (default `30`) days that pass *without* a new offense from that IP,
  `strike_count` drops by 1 (never below 1) the next time that IP offends
  again — so an IP that goes quiet is treated as less of a repeat offender
  for how long the *next* block lasts. `lifetime_offense_count` is
  **never** touched by decay.
- **Permanent promotion**: once `lifetime_offense_count` reaches
  `config('monitor.auto_block_permanent_after_lifetime_offenses')`
  (default `10`), `blocked_until` is set to `null` (permanent) instead of
  a new expiry — regardless of what `strike_count` currently is.
- `source` is overwritten with whatever triggered the latest offense (the
  most recent trigger is the relevant one for that column).

**Why two counters, not one**: an earlier version of this feature used a
single counter for both decay and the permanent threshold. With linear
decay (−1 strike per full cooldown period, floored at 1), any IP that
reoffends at an interval ≥ the cooldown always gets decayed back to the
floor before the new offense is added — once that counter reaches `2`,
it's stuck there forever (`2 − 1 = 1`, `+1 = 2`, repeat), no matter how
many times the IP reoffends over months or years. A patient attacker who
simply waits out the cooldown between attacks would never reach the
permanent threshold. Splitting the counters fixes this: `strike_count`
still decays and correctly reflects "how hot is this IP *right now*" for
sizing the current block, while `lifetime_offense_count` is a simple,
un-decaying tally of "how many times has this IP ever offended" that
guarantees even a well-paced repeat offender eventually crosses the
permanent threshold — it just takes longer, proportional to how patient
they are, which is the correct tradeoff (monthly reoffending for two
years is objectively less severe than daily reoffending, but should
still end in a permanent block if it never stops).

`MonitorMethod::isBlocked()` treats a `blocked_until` in the past as not
blocked — the existing `blocked_ip_cache_ttl` cache (default 60s) already
guarantees an expired block disappears from the application within that
window, no separate job/cron needed.

**Fail-safe against cache store outages (since `0.30.0`)**:
`isBlocked()`/`isPathBlocked()` catch `\Throwable` around the
`Cache::remember` call, separately from the existing
`catch (QueryException)` (table not migrated yet, unchanged, still
assumes `false`). If the cache store itself is unavailable (Redis/Memcached
down, etc), it does **not** assume `false` — that would let a blocked
IP/path through for the whole outage window — it runs the same query
directly against the database instead, bypassing the cache, and logs a
warning. Scoped to just these two methods, since they run on every
request to the protected site; the dashboard-only caches (`getPages`,
`getVisitorsByIp`/listings, `block_results`) don't need this — a
temporary dashboard error is a much smaller cost than letting a scraper
through.

**Clears `monitor_ip_stats.flagged` on block (since `0.26.1`)**: right
after saving the block, `registerOffense()` also sets
`monitor_ip_stats.flagged = false` for that IP and invalidates the
dashboard listings cache (same mechanism as the manual block/unblock/flag
actions). Without this, an IP that gets auto-blocked never goes through
the trackers again to recalculate `flagged` (`MonitorMethod` rejects the
request with 403 before tracking runs), so it stayed shown as "possible
scraper" forever even after being blocked. `flagged_signals` is left
untouched — it's the historical record of what tripped the block, not a
live status.

### Automatic triggers (since `0.16.0`)

Two triggers call `ScraperBlocker::registerOffense()` automatically — no
human reviewing the flagged-IP queue required:

- **Scraper-signal threshold**: `SessionVisitorTracker`/
  `AnonymousVisitorTracker` already run `ScraperSignalDetector` on every
  tracked request to decide `data.flags.scraper`. When the number of
  signals triggered on a single request reaches
  `config('monitor.auto_block_signal_threshold')` (default `3`, higher
  than `scraper_signal_threshold`'s default of `2` on purpose —
  auto-blocking acts without a human, so it deserves more confidence than
  a flag meant for review), that IP gets an offense registered. Both
  trackers wire this up, not just the session one — real scrapers
  typically don't carry a session, so covering only
  `SessionVisitorTracker` would miss the common case.
- **Honeypot hits**: any IP seen hitting a path flagged as a honeypot
  registers an offense immediately — a single hit is enough, no signal
  count needed, since a path nobody legitimate would ever request is
  already the highest-confidence signal available. This holds both at the
  moment a path is flagged (`flagScraperPath`/`flagScraperPaths`, sweeping
  every IP that already visited it) and for any hit that happens
  afterwards (`MonitorMethod`, since `0.37.0`): the first request from an
  IP that isn't already blocked registers the offense and blocks it before
  the request is denied; once blocked, later hits from the same IP just
  take the same rejection like any other blocked IP — one offense per
  block cycle, not one per request, so a bot hammering the honeypot
  doesn't escalate to a permanent block by itself. Before `0.16.0` this
  called `BlockedIp::firstOrCreate()` directly (permanent, static block
  from the first hit); it now goes through the same escalating/expiring
  mechanism as every other automatic block.
  - **`404`, not `403`, on that first hit (since `0.39.0`)**: a `403`
    response is a tell — it lets a scanner tell the monitored honeypot
    path apart from every other nonexistent path, which returns `404`,
    turning the very defense meant to be invisible into a trivial oracle
    for finding it. The rejection is now `abort(404)` on the request that
    registers the offense (the offense itself, and the
    `monitor_block_results` counter below, still happen exactly as
    before — only the HTTP status changes), and stays `403` from the
    *second* hit onward, once the IP is itself in `monitor_blocked_ips` —
    same as any other already-blocked IP hitting any other path. This
    keeps the CGNAT false-positive diagnostic intact (a shared IP that
    gets blocked still shows `403` on every subsequent request, so it's
    easy to spot in logs) while closing the 404-vs-403 tell on the first
    hit.

Curating which paths count as a honeypot stays 100% manual (a human still
decides which routes nobody legitimate would ever hit) — only what
happens when one is hit follows the escalation system above. Two things
keep a honeypot path from tripping up legitimate traffic: list it as
`Disallow` in `robots.txt` (well-behaved bots won't request it) and never
link it anywhere in your own HTML (avoids prefetchers/link checkers
triggering a false positive). A shared IP behind CGNAT still pays the
first strike's 2h block if it does get hit — that's an accepted cost of
the honeypot being cheap to operate.

### Periodic cleanup (since `0.17.0`)

`monitor_blocked_ips` rows never mattered forever — once a temporary block
expires and its decay cooldown passes, the row has nothing left to
contribute (`ScraperBlocker::registerOffense()` only reads it if that IP
offends again). `Support/BlockedIpCleaner::maybeCleanup()` deletes those
rows periodically, with no cron of your own to configure: it runs the same
way Laravel's own session garbage collection does
(`Illuminate\Session\Middleware\StartSession::collectGarbage()`), except
on a deterministic cached timestamp instead of a probability — called from
both trackers on every tracked request, it only actually sweeps once
`config('monitor.blocked_ips_cleanup_interval_hours')` (default `1`) has
passed since the last sweep. On a low-traffic site this means cleanup runs
on the first visit *after* the interval elapses, not on the clock exactly
— the same limitation Laravel's session GC has, not a regression.

A row is only deleted when **all** of these hold:
- `blocked_until` is not `null` (a permanent block is never touched).
- `blocked_until` is already in the past (expired).
- `config('monitor.auto_block_strike_decay_cooldown_days')` has already
  passed since `last_offense_at` (the decay window is over too, not just
  the block itself).
- `lifetime_offense_count === 1`.

That last condition protects the fix described in "Why two counters, not
one" above: deleting a row with `lifetime_offense_count >= 2` would erase
its repeat-offense history, letting a patient attacker who paces reoffenses
get a free reset every cleanup cycle — the same plateau hole, through a
different door. Only an IP that offended exactly once in its lifetime and
never came back is safe to forget; that should be the vast majority of
isolated flags (most never reoffend), so the table stays small in practice
even while permanently keeping every row with 2+ offenses.

## Blocked-attempt counter (`monitor_block_results`)

Since `0.9.0`, every request rejected by `MonitorMethod` (both branches:
the IP itself is in `monitor_blocked_ips`, **or** the path it hit has
`status: 'trap'` in `monitor_paths` — including a brand-new IP that was
never separately blocked, hitting an already-flagged honeypot path)
increments a per-IP counter in the new `monitor_block_results` table
(`ip` unique, `counter`, `last_attempt_at`) — regardless of whether the
response was `403` or, since `0.39.0`, the `404` a first-time honeypot
hit now gets (see "Honeypot hits" above); the counter tracks blocked
attempts, not any particular status code. This is a raw "how many
times has this IP been turned away" tally, independent of `monitor_ip_stats`
(which only tracks requests that were actually let through/tracked).

- **Atomic upsert**: the increment is a single
  `DB::table('monitor_block_results')->upsert(...)` call (Laravel's query
  builder — portable across MySQL/SQLite, generating `ON DUPLICATE KEY
  UPDATE`/`ON CONFLICT` as appropriate for the active driver), not a
  `firstOrCreate` + `increment` pair — the latter is two round-trips and
  races when concurrent requests from the same IP hit the same blocked
  endpoint at once (a common shape for a bot hammering a honeypot path),
  potentially under-counting.
- **Fail-open**: wrapped in the same `try`/`catch (QueryException)`
  pattern as the rest of `MonitorMethod` — if `monitor_block_results`
  hasn't been migrated yet in some environment, the increment is skipped
  (logged via `Log::warning`) and the request is still blocked (`abort()`
  runs unconditionally, outside the try/catch, with the `403`/`404`
  status decided as described above).
- **`blocked_attempts_total`**: a new field on the existing `getData`
  response (`SUM(counter)` across every row) — reuses the same
  client-side fetch that already powers the dashboard's KPI cards instead
  of adding a dedicated endpoint for one number.
- **`getBlockResults`**: new paginated, read-token-eligible action (same
  auth as `getVisitorsByIp`/`getBlockedIps` — permanent `local_token` or
  the ephemeral read token) — `GET /monitor/handler?action=getBlockResults`,
  params `page` (default `1`), `per_page` (default `20`, max `100`).
  Response: `{"success": true, "data": [{"ip": "203.0.113.7", "counter": 42},
  ...], "meta": {"page", "per_page", "total", "last_page"}}`, ordered by
  `counter` descending (most-blocked IPs first). If the table isn't
  migrated yet, returns an empty page instead of erroring (same fail-open
  principle as above).
- **Caching**: both `blocked_attempts_total` and `getBlockResults` use a
  short, fixed TTL (`config('monitor.block_results_cache_ttl_seconds')`,
  default `45` seconds) — deliberately **not** the versioned cache scheme
  shared by `getPages`/`getVisitorsByIp`/etc
  (`invalidatePagesCache`/`invalidateListingsCache`). That scheme assumes
  rare mutation (a manual admin action bumps the version once); this
  counter can increment on every single request from a hammering bot, and
  bumping a shared cache version that often would thrash the cache for
  every other unrelated listing on the dashboard.

See CHANGELOG `[0.9.0]` for the full rationale.

## Web-server deny-list export (`monitor:export-denylist`)

Generates a deny-list snippet from `monitor_blocked_ips`, for blocking IPs
at the web-server level (Apache/Nginx) instead of/in addition to the
application-level block in `MonitorMethod`. Useful once the blocked-IP
list grows large enough that rejecting requests before they even reach PHP
is worth it.

```
php artisan monitor:export-denylist --format=apache
php artisan monitor:export-denylist --format=nginx
```

- **`--format`**: `apache` or `nginx`. If omitted, falls back to
  `config('monitor.denylist_format')` (default `apache`).
- **Output path**: `config('monitor.denylist_path')` (default
  `storage_path('app/monitor/denylist.conf')`), directory created
  automatically if it doesn't exist yet.
- **Apache** format: one `Require not ip x.x.x.x` per line — meant to be
  `Include`d from the vhost config. Apache re-reads included files
  automatically, no reload needed.
- **Nginx** format: one `deny x.x.x.x;` per line. Nginx does **not** pick
  up config changes on its own — you (or your own cron/deploy hook) need
  to run `nginx -s reload` after the file changes. The package
  deliberately does not attempt to trigger this itself (the web app
  process isn't the right place to reload the web server).

**Exporting**: the package never regenerates this file on its own — you
decide when. Three ways to trigger it:

- Run `php artisan monitor:export-denylist` by hand, whenever you want.
- **`exportDenylist`** (`Authorization: Bearer <local_token>`, same auth as
  `updateBlockedIps`/`clearData` — never accepted with the ephemeral read
  token, since it writes to disk on the host server): `POST
  /monitor/handler?action=exportDenylist`, no body needed. Regenerates the
  file using `config('monitor.denylist_format')` and returns `{"success":
  true, "path": "..."}` (or `{"success": false, "message": "..."}` with a
  500 if the write fails, e.g. permissions). This is what a dashboard/UI
  button ("Export denylist now") calls.
- Schedule your own cron on the host server, e.g. to re-export once a day
  right before Apache/Nginx would otherwise pick up a stale file:

  ```
  0 3 * * * cd /path/to/your/app && php artisan monitor:export-denylist >> /dev/null 2>&1
  ```

There used to be a `denylist_auto_export` config flag that regenerated the
file automatically on every `updateBlockedIps`/`unblockIp`/
`flagScraperPath` call — removed (see CHANGELOG) because it rewrites the
*entire* file on every single call, which scales badly when reviewing a
large queue of flagged IPs one by one, for a freshness guarantee most
consumers didn't need faster than their own web server reloads config
anyway.

**`denylist_export_interval_hours`** (default `24`): since `0.15.0`,
temporary blocks (see "Temporary, escalating IP blocking" above) are only
included in the generated file if the time remaining until `blocked_until`
is at least this many hours — a block expiring sooner than your next
scheduled export would never get its `Require not ip`/`deny` line removed
in time, leaving the web server blocking an IP the application has already
released. Set this to match the actual frequency of whatever cron you
configure to run `monitor:export-denylist`/`exportDenylist` (e.g. `24` for
the daily example above). Permanent blocks (`blocked_until = null`) are
always included, no matter this setting.

## Auditing reviewed paths (`monitor:audit-paths`)

Manual, on-demand audit (never automatic) of every `monitor_paths` row
(`trap` and `safe`) against the consuming application's real routes and
live traffic — catches a path reviewed the wrong way even when the guard
described above (since `0.21.0`) had nothing to check against yet, because
the colliding route was never actually visited (no `Monitor` traffic
exists for it at all).

```
php artisan monitor:audit-paths
```

Prints a table of findings (empty table + a confirmation message if
nothing collides) and exits `0` either way — this command reports, it
never fixes. Each finding: the `path`, its current `status`
(`trap`/`safe`), the matched route (`uri` + `source`: `route_table` or
`traffic`), and a `severity` (`high` for a colliding `trap` — risk of
blocking a real user — `info` for a colliding `safe`, which only means
the review was unnecessary: a path that's genuinely a live route was
never a threat to begin with).

Two checks, unioned (a row can match either, or both — `route_table` wins
when both match, since it's the more precise signal):

- **`route_table`**: the path is tested against every registered route's
  compiled regex (`Route::getRoutes()`) — dynamic parameters (`users/
  {id}`, custom `where()` constraints, etc.) resolve correctly since this
  reuses Symfony's own route compiler instead of reimplementing parsing.
  Route domain is deliberately ignored (mirrors `isPathBlocked()`'s
  host-agnostic surface: "this route exists on whatever domain the
  installation serves"). Fallback routes (`Route::fallback(...)`, commonly
  registered so honeypot paths reach `MonitorMethod` at all — see the
  `routes/web.php` note in this README) are excluded on purpose: their
  regex matches literally any path, which would make this check always
  positive and useless.
- **`traffic`**: same live-route criterion as the `0.21.0` guard —
  `data.not_found` empty/false for some `Monitor` hit whose key matches
  the path. Catches a resource that never goes through Laravel's router
  (a static file, etc.) that `route_table` alone wouldn't see. Since
  `0.25.0`, this reads from `monitor_page_hits` (`0.23.0`) via one SQL
  query plus an O(1) per-suffix set lookup, instead of decoding
  `Monitor.data` for every row — see the `monitor_page_hits` note under
  [Paginated page listing (`getPages`)](#paginated-page-listing-getpages)
  below; before `0.25.0` this scanned the entire `Monitor` table in PHP on every
  call and could exceed a typical 10s HTTP timeout on installations with
  tens of thousands of `Monitor` rows (confirmed in production).

Synchronous, no job/queue involved — it's regex matching in memory
against the route table plus SQL-backed lookups against
`monitor_page_hits`, order of seconds even against thousands of
`monitor_paths` rows (confirmed: production has ~4,900 today, well under
a second). No `MonitorAiTriageRun`-style async/polling needed — that
command is async because of external AI API latency, not data volume.

- **`auditPaths`** (`Authorization: Bearer <local_token>`, same auth as
  `flagScraperPath`/`clearData` — never accepted with the ephemeral read
  token): `POST /monitor/handler?action=auditPaths`, no body needed.
  Response: `{"success": true, "findings": [{"path": "...", "status":
  "trap"|"safe", "matched_route": {"uri": "...", "source":
  "route_table"|"traffic"}, "severity": "high"|"info"}, ...]}` (empty
  array when nothing collides).

Undoing a finding is still manual — this command/action never calls
`unflagPath`/`unmarkPathSafe` on its own, by design; whoever reviews the
report decides.

## Scraper signal detection

Every tracked request — with or without an active session — is scored
against a small set of heuristics before being recorded, via the shared
`ScraperSignalDetector`. This is detection only — it marks the `Monitor`
record, it never blocks anything by itself (blocking is
`flagScraperPath`/`updateBlockedIps`, both actions your own
dashboard/automation can trigger after inspecting these flags).

Signals checked by `ScraperSignalDetector::detect`:

- **`high_frequency`**: more than `config('monitor.scraper_frequency_threshold')`
  requests (default `5`) from the same IP within
  `config('monitor.scraper_frequency_window_seconds')` seconds (default
  `10`).
- **`empty_user_agent`**: the request has no `User-Agent` header at all.
- **`known_bot_user_agent`**: the `User-Agent` contains (case-insensitive)
  any substring from `config('monitor.scraper_known_bot_user_agents')` —
  ships with a default list covering common crawlers/bots/HTTP clients
  (`bot`, `spider`, `curl`, `python-requests`, `headlesschrome`,
  `ahrefsbot`, etc.); override via config publish to extend or replace it.
- **`missing_browser_headers`**: at least 2 of `Accept`,
  `Accept-Language`, `Accept-Encoding` are absent — real browsers always
  send all three, most scripted HTTP clients don't set any of them by
  default.
- **`high_cumulative_visits`**: the IP's total visit count (`monitor_ip_stats.visit_count`,
  including the current request) reaches
  `config('monitor.scraper_cumulative_visits_threshold')` (default
  `5000`). Independent of and complementary to `high_frequency` above —
  that one catches a burst in a short window, this one catches a
  "patient" scraper that spreads a high volume over a long time and never
  bursts (e.g. one request per minute for weeks), which never trips
  `high_frequency` no matter how long it keeps going.

Every signal that fires is appended to `data.flags.scraper_signals`
(array of strings, e.g. `["empty_user_agent", "missing_browser_headers"]`)
on that visitor's `Monitor` record. `data.flags.scraper` is `true` once
the number of signals that fired reaches
`config('monitor.scraper_signal_threshold')` (default `2`) — a single
weak signal (e.g. just a missing `Accept-Language`) isn't enough on its
own, avoiding false positives from unusual-but-legitimate clients.

## Per-IP stats (`monitor_ip_stats`)

Every tracked request also upserts a row in `monitor_ip_stats` — one row
per unique IP, keyed on `ip`, via `IpStat::recordVisit()` — as a
lightweight index for listing/paginating/filtering visitors by IP without
scanning every `Monitor.data.ips` JSON array (that scan doesn't paginate
or filter well at any real volume). Columns: `visit_count` (incremented
on every tracked request from that IP), `first_seen`/`last_seen`
(timestamps), and `flagged`/`flagged_signals` — mirroring the most
recent `ScraperSignalDetector` result for that IP, same semantics as
`data.flags.scraper` on `Monitor` (reflects the latest request, not an
accumulated OR of every request ever seen from that IP).

Since `0.11.0`, `recordVisit()` is a single atomic
`DB::table('monitor_ip_stats')->upsert(...)` call (`ON DUPLICATE KEY
UPDATE`/`ON CONFLICT` depending on the driver) instead of a
`where('ip', $ip)->first()` followed by `create()`/`save()` — the old
non-atomic version let two concurrent requests from the same IP both see
no existing row and both try to `create()`, and the second one violated
the `ip` unique constraint and threw an uncaught `QueryException` (a real
500 for the visitor/bot making the request). `first_seen`/`created_at`
are only ever written on insert (never touched by the update clause, so
they survive every later visit).

**Removed in `0.29.0`**: the table carried a `safe` column (boolean, a
persisted human-reviewed verdict on an IP, set/cleared via `markIpSafe`/
`unmarkIpSafe`) between `0.8.0` and `0.28.x`. Dropped as a design
decision — IP is not a stable enough identity (dynamic pools, CGNAT,
recycled IPs) to justify a permanent whitelist based on it alone,
unlike `monitor_paths`' `safe` status (see "Path review state" above),
which is unaffected. There is no replacement action: someone reviewing
the `flagged` queue in `getVisitorsByIp` and deciding an IP isn't a
scraper simply moves on — there's no dedicated "discard" action anymore,
just block (via `updateBlockedIps`) if it is one.

See "Paginated visitor/blocklist listing" below for the read/pagination
action on top of this table (`getVisitorsByIp`).

## IP classification (`monitor_ip_labels`)

Since `0.49.0`: an optional bot/human classification + free-form tags +
note per IP, stored in its own table (`monitor_ip_labels`, one row per
`ip`, unique). **This is annotation only** — it never affects blocking,
scraper detection, or path triage. An IP is an unstable identity (dynamic
pools, CGNAT, recycled addresses — the same reasoning behind removing
`monitor_ip_stats.safe` in `0.29.0`, see "Per-IP stats" above); a wrong
label here can only mislabel, never wrongly allow or block anyone.

Every IP starts **undefined** by the *absence* of a row, not a row with
null fields — a row with no `kind`, no `tags`, and no `note` is deleted
rather than kept around empty (every write action below does this
automatically).

- **`kind`**: `bot`, `human`, or `null` (undefined). Set via `setIpKind`.
- **`tags`**: a short list of free-form strings, normalized on every
  write (trimmed, lowercased, deduplicated, capped at 20 tags of 40
  characters each — longer/duplicate entries are silently dropped, not
  rejected). Set via `setIpLabels` (full replace, one IP) or `setIpTags`
  (add/remove one tag, one or many IPs).
- **`note`**: an optional free-text note. Set via `setIpLabels`.
- **`source`**: `manual` (default) or `ai` (since `0.49.0`, written by
  the AI IP-triage flow — see home-page task 241). Refers to **`kind`**
  specifically, not to tags/note. **Guaranteed by the package itself,
  not just the consumer**: a `source=ai` write never overwrites a `kind`
  already set with `source=manual` (that IP is silently skipped and
  reported back, not an error), and `source=ai` tag writes are always a
  merge (add-only) — they can never remove an existing tag. A manual
  write always wins and always records `source=manual`.
- **`classified_at`**: when `kind` was last (re)written by `setIpKind`.
  Not touched by `setIpLabels`/`setIpTags` (those don't touch `kind`
  either).

**Never touched by `pruneData`, the automatic prune (`DataPruner`), or
`clearData`**: those purge `monitor_ip_stats`/`Monitor`/`monitor_visits`
by age or truncate everything, but a human's (or the AI's) classification
of an IP must survive that — it isn't tracking data, it's a decision
someone made about that IP, and it should stay put until someone
explicitly changes it.

### Write actions (`local_token` only, same auth as `updateBlockedIps`)

- **`setIpKind`**: `ips` (array) or `ip` (single string, same param
  either way), `kind` (`bot`/`human`/`null`, `422` if anything else),
  `source` (`manual` default | `ai`). Invalid/non-IP entries in `ips` are
  silently dropped (same best-effort pattern as `flagScraperPaths`);
  `422` only if none of the given IPs are valid. Response: `{"success":
  true, "applied": ["1.2.3.4"], "ignored": [{"ip": "5.6.7.8", "reason":
  "manual classification protected"}]}`.
- **`setIpLabels`**: `ip` (single, `422` if missing/invalid), `tags`
  (array, replaces the full set — normalized as described above), `note`
  (string or omitted/null to clear). Always `source=manual`; doesn't
  touch `kind`/`classified_at`. This is the detail-view "edit
  classification" form's save action.
- **`setIpTags`**: `ips`/`ip` (same as `setIpKind`), `tag` (single
  string, normalized; `422` if empty after normalization), `op` (`add`
  default | `remove`), `source` (`manual` default | `ai`). `op=remove`
  with `source=ai` is rejected with `422` (`"source=ai cannot remove
  tags"`) — the AI triage flow only ever adds. This is the bulk-action
  case ("add tag to selected IPs" in the dashboard) as well as what the
  AI triage job calls per classified bot.

### Read action

- **`getIpTags`**: every tag currently in use across `monitor_ip_labels`
  + how many IPs carry it, sorted by count descending — feeds the tag
  autocomplete in the dashboard. No pagination (the tag vocabulary is
  small by nature, unlike the IP count). Same auth as `getData`
  (permanent `local_token` **or** the ephemeral read token). Response:
  `{"success": true, "data": [{"tag": "amazon", "count": 12}, ...]}`.

See "Paginated visitor/blocklist listing" below for how classification
surfaces in `getVisitorsByIp`, and "User listing" above for how it
surfaces in `getIpMonitors`/`getUserMonitors`'s `ips` field.

## Paginated page listing (`getPages`)

`GET /monitor/handler?action=getPages` — same auth as `getData` (the
permanent `local_token` **or** the ephemeral read token from
`issueReadToken`). Aggregates `monitor_page_hits` (`hits`/`not_found`,
across every `Monitor`) into one entry per path (`host/path`) instead of
shipping raw `Monitor` rows. As of `0.3.0`, this listing no
longer carries a scraper signal at the path level — a path like `/` could
end up marked "possible scraper" just because one bot happened to pass
through it once. The scraper heuristic still runs exactly the same, it's
just scoped to the IP/visitor level now (see `getVisitorsByIp` below).
`flagScraperPath`'s honeypot mechanism (block a path + the IPs that
already visited it) is unaffected — it never depended on this field.

Since `0.4.0`, each path also carries a review `status` — `pending`
(default, never reviewed) or `safe` (marked via `markPathSafe`, see
above) — sourced from `monitor_paths` (`status = 'safe'` rows; see "Path
review state" above), matched by suffix the same way
`blocked`/`monitor_paths` (`status = 'trap'`) already was. Since `0.20.3`,
`status` can only be `'safe'` when the path is also `not_found: true` —
`monitor_paths` matches by suffix regardless of host, on purpose (so one
review protects every subdomain with the same trap), but that means a
short/generic path (e.g. `login`) reviewed as safe on a host where it's a
404 must never leak the `'safe'` badge to a *different* host where the
same suffix is a real, non-404 route. A `clean` path (`not_found: false`)
always reports `status: 'pending'`, no matter what any `monitor_paths` row
says.

- `page` (default `1`), `per_page` (default `20`, max `100`).
- `filter`: `pending_review` (**default when `filter` is omitted**:
  `not_found = true` AND `status != 'safe'` AND `blocked = false` — the
  "still needs a human look" queue), `all` (the full dump — pass this
  explicitly to get the old default-listing behavior back), `404` (path
  was ever hit while the response was a 404), `clean` (not 404, not
  blocked), `blocked` (path has `status: 'trap'` in `monitor_paths`,
  matched by suffix the same way `flagScraperPath` does), `safe` (since
  `0.20.3`: path has `status: 'safe'` — see below). An unknown `filter`
  value returns `422`.
- `date_from`/`date_to` (optional, any format `Carbon`/the DB driver
  accepts for a `where` comparison): filters by the **`Monitor` row's**
  `updated_at`, not a per-page-hit timestamp — the schema has no
  per-visit timestamp (one row aggregates every page a visitor hit), so
  this is "that visitor was active in this window", not "this path was
  hit on this exact date". Good enough to narrow down recent activity;
  don't rely on it for exact per-hit auditing.
- Response: `{"success": true, "data": [{"path": "example.com/a",
  "hits": 12, "not_found": false, "blocked": false, "status": "pending"},
  ...], "meta": {"page": 1, "per_page": 20, "total": 47, "last_page": 3}}`.

Result is cached (`Cache::remember`, TTL
`config('monitor.pages_cache_ttl_minutes')`, default 5 minutes) keyed by
a hash of the request params. Since the array/file cache drivers don't
support `Cache::tags()`, invalidation works via a version counter
instead: `flagScraperPath`/`unflagPath`/`markPathSafe`/`unmarkPathSafe`
bump it, which changes every `getPages` cache key at once — old entries
are simply never read again and expire on their own TTL, rather than
being individually deleted.

**Since `0.23.0`**, the aggregation itself no longer scans and
JSON-decodes every `Monitor` row on a cache miss. A `monitor_page_hits`
table (one row per `Monitor`+path, unique on `(monitor_id, path)`) is
kept in sync automatically whenever a `Monitor` is saved — via a model
event, so this works no matter how the row was written (the trackers,
`Monitor::create()` directly, `tinker`, tests) — and `getPages` now
aggregates it with a single `GROUP BY` query (`SUM(hits)`,
`MAX(not_found)`), joined against `monitors.updated_at` only when
`date_from`/`date_to` are given. This fixed a real production timeout:
with ~35k `Monitor` rows, the old PHP-side scan measured ~85s on a cache
miss, well past the 10s timeout a typical consumer (e.g. `home-page`)
uses to call this endpoint. Upgrading runs a one-time backfill migration
that populates `monitor_page_hits` from whatever `Monitor.data` already
exists — expect it to take roughly as long as the old per-request scan
used to (a few seconds per ~1k rows), but it only runs once, at migrate
time, not on every `getPages` call.

## Paginated visitor/blocklist listing (`getVisitorsByIp`, `getBlockedIps`, `getBlockedPaths`)

Same auth as `getData`/`getPages` (permanent `local_token` **or** the
ephemeral read token from `issueReadToken`).

- **`getVisitorsByIp`**: paginated/filterable listing of
  `monitor_ip_stats` (one row per unique IP, maintained by
  `IpStat::recordVisit()` on every tracked request — see "Per-IP stats"
  above). Params: `page` (default `1`), `per_page` (default `20`, max
  `100`), `filter` (`all` default, `flagged`, `clean`, `blocked`, and
  since `0.49.0` the sub-filters `clean_bots`, `clean_humans`,
  `clean_unclassified`, `clean_ai_queue` — see "IP classification"
  above; an IP counts as `blocked` if it has an active row in
  `monitor_blocked_ips` (`BlockedIp::active()`: permanent, or temporary
  and not yet expired); unknown value returns `422`), `date_from`/
  `date_to` (optional, filters by the
  row's `last_seen` — "this IP was active in this window", same
  approximation as `getPages`), and since `0.49.0` `tag`/`kind`
  (optional, combinable with any `filter` above — e.g. `filter=blocked`
  `kind=bot` lists blocked bots). Response: `{"success": true, "data":
  [{"ip": "1.2.3.4", "visit_count": 12, "first_seen": "...",
  "last_seen": "...", "flagged": false, "flagged_signals": null,
  "blocked": false, "kind": null, "tags": [], "note": null, "source":
  null, "classified_at": null}, ...], "meta": {"page", "per_page",
  "total", "last_page"}}`. The `kind`/`tags`/`note`/`source`/
  `classified_at` fields (since `0.49.0`) come from `monitor_ip_labels`
  — see "IP classification" above.
  - Regardless of which `filter` is requested, results are always
    ordered with `flagged = true` rows first (the "possible scraper"
    work queue), falling back to the existing `visit_count desc`
    ordering within each group. **Removed in `0.29.0`**: between `0.8.0`
    and `0.28.x`, this also excluded/deprioritized IPs marked `safe` —
    there is no `safe` status for IPs anymore (see "Per-IP stats"
    above), so `filter=flagged` and the default ordering now depend only
    on `flagged`.
  - Since `0.12.0`: `filter=flagged` also excludes IPs already present in
    `monitor_blocked_ips` — an IP that's already been blocked has already
    been confirmed as a scraper, so it no longer needs to show up in the
    "possible scraper" queue too. It still shows up under
    `filter=blocked`, as before.
  - **Since `0.33.0`**: `filter=blocked` no longer starts from
    `monitor_ip_stats` — it queries `monitor_blocked_ips` directly (the
    actual source of truth for whether an IP is blocked, same table
    `MonitorMethod::isBlocked()` reads) and only *optionally* enriches
    each row with `visit_count`/`first_seen`/`last_seen`/`flagged`/
    `flagged_signals` from `monitor_ip_stats` when that row still exists
    (`null`/`false` when it doesn't — the IP still shows up either way).
    Fixes a real bug: `monitor:prune --only-blocked --older-than-days=0`
    running hourly (see below) deletes a blocked IP's `monitor_ip_stats`
    row within about an hour of the block, which used to make the IP
    silently disappear from this listing even though it was still
    actually blocked. `date_from`/`date_to` now filter by
    `monitor_blocked_ips.last_offense_at` instead of `last_seen` under
    this filter, and the response for each row also gains
    `blocked_until`, `strike_count`, `lifetime_offense_count`,
    `last_offense_at` and `source` (all from `monitor_blocked_ips`) — the
    existing fields keep their shape, nothing is removed.
  - **Since `0.41.0`**: `filter=blocked` only lists **active** blocks
    (`BlockedIp::active()`) — a temporary block whose `blocked_until` has
    already expired no longer appears in this listing, same "blocked"
    definition used everywhere else in the package (the `blocked` field
    on `filter=all`/`flagged`/`clean` rows since `0.40.0`, and
    `MonitorMethod::isBlocked()`). Before `0.41.0` this tab intentionally
    listed expired blocks too, as a history/reputation view; that was
    inconsistent with the rest of the package and was never actually
    requested, so it's reverted. The expired row itself is untouched in
    `monitor_blocked_ips` (still feeds `strike_count`/
    `lifetime_offense_count` for the escalation ladder) — it just stops
    showing up here.
- **`getVisitorPaths`** (since `0.6.0`): given an `ip`
  (`{"success": false, "message": "No valid IP provided"}`, `422`, if
  missing/invalid), finds every `Monitor` that's ever seen that IP and
  aggregates the paths (`monitor_page_hits`) it's been seen on — lets you
  confirm visually that an IP is a scraper before blocking it. No
  pagination/caching: the result set per IP is small and this is a
  lookup triggered on demand (e.g. expanding a row in the dashboard), not
  loaded on every page view. Response: `{"success": true, "ip": "1.2.3.4",
  "paths": [{"path": "example.com/wp-admin/install.php", "hits": 3},
  ...]}`, sorted by hits descending.
  - **Since `0.24.0`**: no longer scans and JSON-decodes every `Monitor`
    row to find which ones contain the IP (same bug class fixed for
    `getPages` in `0.23.0` — the cost used to be proportional to the size
    of `Monitor`, not to that IP's activity). A `monitor_visit_ips` table
    (one row per `Monitor`+ip, kept in sync by the same model event as
    `monitor_page_hits`) is looked up by `ip` via an index to get the
    relevant `monitor_id`s, then `monitor_page_hits` sums hits per path
    for just those ids — both steps in SQL. Upgrading runs a one-time
    backfill migration populating `monitor_visit_ips`.
- **`getIpMonitors`** (since `0.46.0`): given an `ip` (`{"success":
  false, "message": "No valid IP provided"}`, `422`, if missing/invalid,
  same validation as `getVisitorPaths`), a **paginated** listing of the
  actual `Monitor` rows (devices/browsers) ever seen from that IP —
  complements `getVisitorPaths` (which only aggregates paths, without
  exposing the `Monitor` rows themselves). Params: `page` (default `1`),
  `per_page` (default `20`, max `100`). Looked up the same way as
  `getVisitorPaths` (`monitor_visit_ips.ip`, indexed — never a scan of
  `Monitor`), ordered by `updated_at` descending (`id` descending as a
  deterministic tiebreaker). Response:
  ```json
  {
    "success": true,
    "data": [
      {
        "id": 42,
        "created_at": "2026-09-10T12:00:00.000000Z",
        "updated_at": "2026-09-23T08:15:00.000000Z",
        "data": {"ua": "Mozilla/5.0 ...", "flags": {"scraper": false, "scraper_signals": []}},
        "ips": [
          {"ip": "203.0.113.9", "kind": "bot", "tags": ["amazon"], "note": null, "source": "ai", "classified_at": "2026-09-24T10:00:00.000000Z"},
          {"ip": "203.0.113.14", "kind": null, "tags": [], "note": null, "source": null, "classified_at": null}
        ],
        "visits_count": 7
      }
    ],
    "meta": {"page": 1, "per_page": 20, "total": 1, "last_page": 1}
  }
  ```
  - `data` (the blob) is **sanitized** the same way as `getUserMonitors` —
    see "Sanitizing the `data` blob" below.
  - `ips`: **every** IP that `Monitor` has ever been seen from (not just
    the one searched for), and `visits_count`: `COUNT(*)` of
    `monitor_visits` for that `Monitor` — both resolved with one grouped
    `whereIn` query per page (not one query per row), same shared
    `hydrateMonitorRows` helper `getUserMonitors` uses.
    **Breaking since `0.49.0`**: each `ips` entry used to be a plain IP
    string; it's now an object carrying that IP's classification from
    `monitor_ip_labels` (`kind`/`tags`/`note`/`source`/`classified_at`,
    same fields as `getVisitorsByIp` — see "IP classification" above),
    so the dashboard can show a Monitor's IPs' classification without an
    extra call per IP.
  - Cached the same way as `getVisitorsByIp`/`getBlockedIps` (see below).
- **`getMonitorVisits`** (since `0.46.0`): given a `monitor_id`
  (`{"success": false, "message": "monitor_id is required"}` /
  `"monitor_id must be an integer"` / `"Monitor not found"`, `422`, when
  missing, non-integer, or not an existing `Monitor` id, respectively), a
  **paginated** listing of that device/browser's **full** visit history
  (`monitor_visits`), newest first (`id` descending). Unlike
  `getIpMonitors`/`getUserMonitors` (which only expose `visits_count`, a
  number, per row), this action lets you page through the complete
  journey history of one specific `Monitor` on demand (e.g. an "see all
  visits" expansion in the dashboard). Params: `page` (default `1`), `per_page` (default `20`,
  max `50`). Response:
  ```json
  {
    "success": true,
    "data": [
      {
        "id": 981,
        "ip": "203.0.113.9",
        "paths": ["example.test/", "example.test/cart", "example.test/checkout"],
        "scraper": false,
        "created_at": "2026-09-23T08:00:00.000000Z",
        "updated_at": "2026-09-23T08:04:00.000000Z"
      }
    ],
    "meta": {"page": 1, "per_page": 20, "total": 1, "last_page": 1}
  }
  ```
  - `ip`: the IP that **opened** that specific visit — see "Visits"
    below (`monitor_visits.ip`, new in `0.46.0`); `null` for a visit
    recorded before this column existed (not backfilled).
  - `paths`: the full journey for that visit, in access order (same
    shape as each entry of `monitor_visits` — see "Visits" below).
  - Cached the same way as `getVisitorsByIp`/`getBlockedIps` (see below).
- **`getBlockedIps`** / **`getBlockedPaths`**: plain paginated listing
  of `monitor_blocked_ips` (`{"ip", "source", "created_at"}`) /
  `monitor_paths` rows with `status: 'trap'` (`{"path", "created_at"}`,
  same shape as before `0.20.0`) — no `filter` param, just
  `page`/`per_page`. Ordered newest-first.

Unlike `getPages` (which has to aggregate a JSON blob per `Monitor`
row in PHP), these three query normalized tables directly, so
filtering/ordering/pagination happen in SQL via a real
`Model::paginate()`.

Cached the same way as `getPages` (`Cache::remember` + a version
counter, TTL `config('monitor.listings_cache_ttl_minutes')`, default 5
minutes) but with its own counter (`monitor:listings:version`), kept
separate from `getPages`' so this change doesn't touch its already
released cache. `updateBlockedIps`, `unblockIp`, `flagScraperPath`, and
`unflagPath` all bump it, since every one of them changes blocked-state
data these three actions read.

## Partial cleanup (`pruneData`)

`GET /monitor/handler?action=pruneData` — same auth as `clearData`/
`updateBlockedIps`: requires the permanent `local_token`, **never**
accepted with the ephemeral read token from `issueReadToken`.

Complements `clearData` (full truncate of `Monitor`, unchanged) with a
partial, filtered delete:

- `older_than_days` (required, non-negative integer — `422` if
  missing or invalid): deletes `Monitor` rows whose `updated_at` is
  older than `now() - older_than_days` days, and `monitor_ip_stats`
  rows whose `last_seen` is older than the same cutoff.
- `only_blocked` (optional boolean, default `false`): when `true`,
  restricts the delete to rows belonging to an IP **currently blocked**
  in `monitor_blocked_ips` — matched via `monitor_visit_ips` on `Monitor`, the
  `ip` column on `IpStat` — instead of every row past the cutoff.
  > ⚠️ **Breaking change in v0.7.0**: this parameter was named
  > `only_scraper_flagged` and matched `data.flags.scraper`/
  > `IpStat.flagged` instead — the automatic, non-cumulative heuristic
  > signal from the *last* request seen from that IP, never reviewed by
  > anyone. That made `pruneData` capable of permanently deleting rows
  > for an IP on an unreviewed false positive. It now matches
  > `monitor_blocked_ips` (an IP the user actually confirmed/blocked)
  > instead.
  > ⚠️ **Behavior change in v0.38.0**: "blocked" here used to mean *any*
  > row in `monitor_blocked_ips`, including a temporary block whose
  > `blocked_until` had already expired — that row intentionally lives on
  > after expiry (it feeds the escalating block duration on the *next*
  > offense, see "Manual IP blocking"/`ScraperBlocker`), but treating it
  > as still-blocked for pruning purposes meant an IP that served its
  > temporary block and went back to being a normal visitor kept having
  > its tracking wiped every automatic cleanup cycle. `only_blocked` now
  > only matches a currently-active block (permanent, or temporary and
  > not yet expired) — same check `MonitorMethod` itself uses to decide
  > whether to block a request (`BlockedIp::active()`).

Response: `{"success": true, "monitors_deleted": 12, "ip_stats_deleted": 4,
"visits_deleted": 3}`. `visits_deleted` (its value was already computed by
`Support\DataPruner::prune()` since `0.42.0`, but this HTTP response never
surfaced it until `0.46.0` — fixed here) now also reflects the
`only_blocked=false` cutoff sweep described in "Visit retention" below.

Bumps the `getPages`/`getVisitorsByIp` listing cache version counters
(`invalidatePagesCache`/`invalidateListingsCache`) whenever something
was actually deleted from the corresponding table, same mechanism as
`flagScraperPath`/`updateBlockedIps` etc.

### `monitor:prune` (since `0.27.0`)

Artisan equivalent of `pruneData` above — same options, same underlying
`Support\DataPruner` (chunked/indexed, never `::all()`/`cursor()` over the
whole `Monitor` table), same cache invalidation — meant to run from the
consuming app's own scheduler instead of a manual HTTP request:

```
php artisan monitor:prune --only-blocked --older-than-days=0
```

- `--older-than-days=` (required, non-negative integer — command fails
  with a non-zero exit code if missing or invalid): same cutoff semantics
  as `pruneData`'s `older_than_days`.
- `--only-blocked` (optional flag, default off): same semantics as
  `pruneData`'s `only_blocked` — restrict the delete to rows belonging to
  a confirmed/blocked IP (`monitor_blocked_ips`).

**Automatic since `0.32.0`** — you no longer need to schedule anything
for this: `Support\DataPruner::maybeCleanup()` runs `prune(0, true)`
(purge tracking data for confirmed-blocked IPs) by itself, on a
deterministic cache-backed timer (`monitor.data_prune_interval_hours`,
default `1`), checked on every tracked request — same mechanism as
`Support\BlockedIpCleaner::maybeCleanup()`. An IP already purged under
this filter never reappears in it, so this stays cheap even checked on
every request. Manually scheduling `monitor:prune --only-blocked
--older-than-days=0` (e.g. via `Schedule::command(...)->hourly()` in the
consuming app) is now **optional/redundant** — the command itself still
exists for manual/administrative use (e.g. `--older-than-days` greater
than `0`, to prune by age without the blocked-IP filter, which is only
useful on demand).

**Concurrency-safe and capped since `0.36.0`** — because the automatic
trigger runs inline in a visitor's own request (no queue), two safeguards
protect it from running away on a busy site:

- **Lock**: `maybeCleanup()` takes an atomic `Cache::add` lock before
  pruning and releases it in a `finally` block. If two requests race past
  the interval check at the same time, only one actually prunes; the other
  is a no-op. A short, non-configurable safety TTL (`60s`) on the lock
  itself guards against a process dying before it can release it (e.g. an
  OOM kill) — it's not meant to bound how long a normal run takes.
- **Row cap**: `monitor.data_prune_max_rows_per_run` (default `1000`)
  caps how many `Monitor` rows a single automatic run deletes. If the
  backlog is larger than the cap, the run deletes one capped batch and
  **does not** advance the "last run" timestamp — the very next tracked
  request picks up where it left off (the query is re-run from scratch
  each time, not a frozen list, so nothing about a since-unblocked IP can
  go stale). `monitor_ip_stats` isn't capped — it's bounded by the size of
  `monitor_blocked_ips` itself, not by the tracking backlog, so it stays
  cheap without chunking.

This only affects the automatic trigger. `monitor:prune` and `pruneData`
(manual/administrative use) are always uncapped and delete everything
matching the filter in one go, exactly as before.

### Visit retention (since `0.42.0`)

`monitor.visits_retention_days` deletes `monitor_visits` rows whose
`updated_at` (last activity) is older than that, **independent of the age
of the parent `Monitor`**: a device recognized by the 5-year remember-me
cookie is never deleted by the prune above, so without this its visits
would pile up forever. It runs inside `DataPruner::prune()` — the
automatic trigger (capped by `data_prune_max_rows_per_run`, same "resume
on the next request" behavior), `monitor:prune` and `pruneData` — and the
visits of a deleted `Monitor` go away through the foreign key's
`ON DELETE CASCADE`.

> ⚠️ **Behavior change in `0.46.0`**: the default changed from `90` to
> `0` (**disabled** — retention is now opt-in/manual). Until `0.45.0`,
> `visits_retention_days` defaulted to `90` *and* the automatic trigger
> (`DataPruner::maybeCleanup()`, run on every tracked request) called
> `prune(0, true)`, which already included this retention sweep — meaning
> **every** installation that never published `config/monitor.php` (most
> of them) was already auto-deleting `monitor_visits` older than 90 days,
> silently, by accident. That was never the intent: the automatic trigger
> exists to purge tracking for already-confirmed-blocked IPs
> (`only_blocked=true`), not to sweep everyone's visits by age. Two
> concrete consequences:
> - **A site that never published `config/monitor.php`** loses the old
>   automatic 90-day visit retention on upgrade to `0.46.0` — visits now
>   accumulate indefinitely unless you opt back in (see below). This is
>   the majority of installations (publishing the config is optional).
> - **A site that already published `config/monitor.php`** keeps
>   whatever value it has in that file — `monitor:update` never
>   overwrites a key you've customized (see "Updating" above), so nothing
>   changes for you here.
>
> To restore automatic visit cleanup, either (a) publish
> `config/monitor.php` (if not already) and set `visits_retention_days`
> to a value `> 0` — the automatic trigger keeps honoring it exactly like
> before `0.46.0` — or (b) schedule
> `monitor:prune --older-than-days=X` (without `--only-blocked`) yourself,
> which as of `0.46.0` **also** sweeps `monitor_visits` — see next
> paragraph.
>
> **New in `0.46.0`**: `DataPruner::prune($olderThanDays, $onlyBlocked)`
> now *additionally* deletes `monitor_visits` rows older than the same
> `$olderThanDays` cutoff used for `Monitor`/`monitor_ip_stats`, whenever
> `$onlyBlocked` is `false` — independent of, and on top of,
> `visits_retention_days`. This is what `pruneData`
> (`only_blocked=false`, the dashboard's "partial cleanup" UI) and
> `monitor:prune --older-than-days=X` (without `--only-blocked`) use to
> let you manually sweep old visits from active devices even with
> retention disabled — the tool that replaces the old accidental
> automatic behavior. It's a second, independent `DELETE` (no
> double-counting: a row either query already removed just isn't found by
> the other), and its count is folded into the same `visits_deleted`.
> This new sweep **never** runs from the automatic trigger
> (`maybeCleanup()` always calls `prune(0, true)` — `only_blocked=true`),
> so the automatic path still never deletes a visit on its own with the
> default config, exactly as intended.

`monitor:prune`'s output and `DataPruner::prune()`'s return value gained a
`visits_deleted` count in `0.42.0`; since `0.46.0` it sums both sources
above, and the command's message says so (`"past
monitor.visits_retention_days"` when `--only-blocked` was passed, `"past
monitor.visits_retention_days and/or --older-than-days"` otherwise).

## Visits (`monitor_visits`, since `0.42.0`)

Where each piece of tracking data lives:

| Data | Lives in |
|---|---|
| Remember-me token (the cookie value) | `monitors.id_token` (unique index) |
| Device data: `ua`, `user_id`, `flags`, your own `tag()` data | `monitors.data` (JSON) |
| Hits per path (+ `not_found`) | `monitor_page_hits` |
| IPs seen per device | `monitor_visit_ips` |
| **The journey of each visit** | `monitor_visits` |
| **The IP that opened each visit** | `monitor_visits.ip` (since `0.46.0`) |

Until `0.41.0`, `page`/`not_found`/`ips`/`sessions`/`visits` were written
into the JSON blob and copied to the child tables by a model hook. From
`0.42.0` the child tables are the source of truth, written directly by the
trackers (`Monitor::recordHit()` is an atomic `hits = hits + 1` upsert;
`Monitor::recordIp()` an `insertOrIgnore`), and the blob no longer carries
those keys — reading `$monitor->data['page']` etc. now returns nothing.

`monitor_visits` has one row per **visit = one PHP session**: `paths` is the
list of paths hit during the visit **in access order, raw and without
counts** (consecutive repeats such as a page reload are kept — they're
diagnostic signal; collapse them when analysing, not when storing),
`created_at` is the start of the visit, `updated_at` is its last activity
(the server can't know when the user actually left), and `scraper` turns
`true` as soon as any request of the visit was flagged and never goes back
— the visit is still recorded, just marked, so funnel analyses can filter
`WHERE scraper = false`.

The visit is keyed by a `monitor_visit_id` stored **inside the session**,
not by the session id: Laravel regenerates the session id on login, which
would otherwise split a `login → dashboard` journey in two. A genuinely new
session has no `monitor_visit_id` and starts a new visit. Only the tracker
with a session creates visits; the anonymous one (API/bots/scrapers, no
session) never does. Every tracked request is added, including AJAX/API
calls inside the `web` group — call `Monitor::skipTracking()` for the ones
that shouldn't show up in the journey.

**`ip` (since `0.46.0`)**: the IP that **opened** the visit, written only
when the `monitor_visits` row is **created** (`Support\VisitRecorder::record()`)
— never rewritten on later requests of the same visit, even if the
visitor's IP changes mid-session (that's tracked separately, and
independently, by `monitor_visit_ips`/`SessionVisitorTracker::recordIpIfChanged()`).
Nullable: a visit recorded before this column existed keeps `ip = NULL`
forever — there's no reliable per-visit IP to backfill it from (only
`monitor_visit_ips`, which doesn't record which IP opened *which* visit,
existed before). Exposed by `getMonitorVisits` above.

Config (all in `config/monitor.php`; `monitor:update` adds missing keys):

- `track_visits` (default `true`): journey + IP + a 5-year cookie is
  personal data — turn it off if your privacy policy doesn't cover it.
- `visit_max_paths` (default `200`): once a visit reaches this many paths it
  only bumps `updated_at`.
- `visits_retention_days` (default `0` since `0.46.0`, was `90`): see
  "Visit retention" above, including the `0.46.0` behavior change.

`Monitor::skipTracking()` is unchanged. A concurrent pair of requests from
the same session can, rarely, drop one step of a journey (the visit row is
read-modify-written); hit counts are unaffected (atomic).

## Advanced usage

### Ignoring IPs (`monitor.ignore_ips`, since `0.43.0`)

Requests from IPs listed in `config('monitor.ignore_ips')` pass straight
through `MonitorMethod`: no `Monitor` row, no visit, no `monitor_ip_stats`
entry, no scraper signal, and **never blocked** — not by IP, not by a
honeypot path. The check is the first thing the middleware does, an
in-memory comparison before any database access, so it costs nothing per
request (it actually saves work). Entries are exact IPs or CIDR ranges,
IPv4 or IPv6. In `.env`, comma-separated:

```
MONITOR_IGNORE_IPS=203.0.113.7,127.0.0.1,::1,10.0.0.0/8
```

Built for the server's own traffic — a cron health check doing
`curl https://your-site` every minute has no cookie, so it used to create one
`Monitor` + one visit per run, inflate `monitor_ip_stats` (and flag itself as
a scraper) — and for trusted monitoring/admin IPs. Default is empty
(nobody is ignored). Requests from an ignored IP aren't tracked *retroactively*:
existing rows stay until pruned.

> ⚠️ Only list IPs that **can't be forged**. Behind a reverse proxy with an
> open `trustProxies` (`'*'`), a forged `X-Forwarded-For` would make a request
> look like it came from an ignored IP and escape the monitor. Also note a
> cloud instance's public IP can change on stop/start unless it's an
> Elastic/static IP — when it changes, the ignore silently stops matching
> (the noise comes back, nothing breaks).

### Skipping tracking for a request

`MonitorMethod` runs on every request in the `web` middleware group, so
any AJAX/API-style endpoint inside that group (a language switcher, a form
submit, etc.) gets counted as a page view and can overwrite the current
`Monitor` record's `data` with values that don't belong to a real page
visit. Call `Monitor::skipTracking()` before returning the response for
any request that shouldn't be tracked:

```php
use Drcantagalo\LaravelMonitor\Facades\Monitor;

Route::post('/lang/{locale}', function (string $locale) {
    Monitor::skipTracking();

    // ... switch locale ...

    return back();
});
```

Under the hood this just sets a session flag; `SessionVisitorTracker`
reads and clears it the next time `MonitorMethod` processes this session,
skipping its tracking logic for that one request. The session key used is
`config('monitor.skip_session_key')` (default `avoid_monitor`) — publish
the package config (`monitor-config` tag) to change it.

### Permanently excluding a route from tracking (`avoid-monitor`, since `0.45.0`)

`Monitor::skipTracking()` above is circumstantial — it has to be called
explicitly inside the controller/action handling the request, which is easy
to forget on a new route (that's exactly how it happens: a route gets added,
nobody remembers to call the facade inside it, and it starts polluting the
journey/`data` until someone notices). For a route or group that should
**never** be tracked — automatic polling, internal dedicated endpoints — put
that decision in the route definition itself instead of depending on someone
remembering the facade call inside it:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('avoid-monitor')->group(function () {
    Route::get('/dashboard/{installation}/triage-status', TriageStatusController::class);
});
```

Use whichever mechanism fits the shape of the decision:

- `Monitor::skipTracking()` — a request should be skipped *conditionally*,
  decided by something the controller checks at runtime (e.g. an endpoint
  shared by several callers that only skips tracking when nothing actually
  changed).
- `avoid-monitor` middleware — a route should *never* be tracked, full stop,
  independent of anything the controller does.

The middleware just calls `Monitor::skipTracking()` for you, before
`$next($request)` runs — same session flag, same mechanism, no separate code
path to keep in sync. Combining both on the same route (middleware present
*and* a manual `skipTracking()` call inside the action) is harmless: the
second call just re-sets the same flag the first one already set.

### `updateRules` (reserved, not implemented yet)

The handler action `updateRules` exists and is routed (same auth as
`updateBlockedIps`), but it's currently a stub — it always responds
`{"success": true, "message": "Monitoring rules updated (stub)"}` without
reading its input or changing any behavior. Don't build against it as a
real feature yet.
