# Laravel Monitor (v0.15.0)

**Laravel Monitor** is an experimental package designed to test the initial installation flow for a lightweight Laravel package providing basic CRM tools, access monitoring, and anti-scraper features. Designed to track visits, manage sessions, and detect potentially malicious scrapers.

> ⚠️ **This is an early testing release.**  
> API and config shape may still change between minor versions. See `CHANGELOG.md` for what each release actually added/fixed.

## Updating

First-time setup is `composer require drcantagalo/laravel-monitor` followed
by `php artisan monitor:install` (interactive: terms, publish
config/migrations, migrate, register the installation). `monitor:install`
is **not** meant to run again on every update — running it a second time
would re-ask every prompt for a project that's already set up.

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
package version is a no-op). No confirmation prompts, since this is
routine maintenance, not first-time setup.

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
  "blocked_attempts_total": 342
}
```

- **`visitors_total`**: `Monitor::count()` — one row per recognized
  device/browser (see "Remember-me" above).
- **`visits_total`**: `SUM` of `data.visits` across every row, computed in
  SQL directly on the JSON column (`JSON_EXTRACT`/`json_extract`
  depending on the driver) — never loads a row into PHP.
- **`sessions_total`**: `SUM` of the length of `data.sessions` per row
  (`JSON_LENGTH`/`json_array_length`), also computed in SQL. This is a
  count of recorded sessions, **not** a cross-row deduplicated count —
  in practice a session id only ever belongs to one `Monitor` row, so
  this already reflects the real total in the overwhelming majority of
  installs, but there's no dedicated table enforcing that globally
  (unlike IPs, see below).
- **`unique_ips_total`**: `IpStat::count()` — reuses `monitor_ip_stats`
  (see "Per-IP stats" below), which already keeps exactly one row per
  unique IP ever seen. Deliberately **not** a scan/dedupe of
  `data.ips` across every `Monitor` row.
- **`blocked_attempts_total`**: unchanged, see "Blocked-attempt counter"
  below.
- All four new totals share a short, fixed cache TTL
  (`config('monitor.data_totals_cache_ttl_seconds')`, default `45`
  seconds — same rationale as `block_results_cache_ttl_seconds`) and
  fail open to `0` if the underlying table/column isn't there yet on an
  older, not-yet-migrated install (logged via `Log::warning`).

If your integration was reading the raw `data` array from `getData`,
there is no drop-in replacement — it was removed entirely rather than
turned into a paginated sample, since no known consumer needed row-level
detail from this specific action (row-level detail is what `getPages`/
`getVisitorsByIp`/`getUserVisits` are for). See CHANGELOG `[0.10.0]` for
the full rationale.

## Remember-me (returning visitor recognition)

When `MonitorMethod` creates a new `Monitor` record for a first-time
visitor (session-based, non-bot request), it generates a random token,
stores it in `data['id-token']`, and attaches it to the response as a
long-duration cookie so the same browser can be recognized again after its
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

## User listing (`getUsers`, `getUserVisits`)

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
  (`SUM(data.visits)`/`MAX(updated_at)`) and pagination run in SQL,
  grouped by the same indexed generated column `Monitor::forUserId()`
  uses on MySQL (`monitors_user_id`) — never the raw `data->user_id`
  expression, for the same index-matching reason documented above.
  - Since `0.12.0`: `visits_count` sums `data.visits` per `user_id`
    (same portable MySQL/SQLite JSON expression `visitsTotal()` uses in
    `getData`) instead of counting `Monitor` rows. A `Monitor` row is
    reused across sessions for the same device/browser (reconnected via
    the remember-me cookie), not created per visit, so counting rows
    used to always give `1` for a user who only ever visits from the
    same browser.
- **`getUserVisits`**: given `user_id` (required, `422` if missing),
  paginated listing of that user's raw `Monitor` rows (via
  `Monitor::forUserId($id)`, newest first) — `id`, `data` (pages, IPs,
  session ids, everything already tracked per device/browser),
  `created_at`, `updated_at`. Same `page`/`per_page` params as
  `getUsers`.
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
  `getBlockedIps`, `getBlockedPaths`, `getUsers`, `getUserVisits`,
  `getBlockResults`)**.
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
`404` (`data.not_found[path] = true`) — aggregated by `getPages` (see
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

- **`flagScraperPath`** (`Authorization: Bearer <local_token>`, same auth
  as `updateBlockedIps`/`clearData` — never accepted with the ephemeral
  read token): `POST /monitor/handler?action=flagScraperPath` with
  `{"path": "wp-admin/install.php"}` (host-less; a leading `/` is
  stripped if present). Two things happen:
  1. The path is inserted into `monitor_blocked_paths`. From then on,
     `MonitorMethod` rejects (`403`) any request whose path matches,
     **regardless of host** — an installation shared by multiple
     subdomains is protected on all of them at once, since the block
     check ignores the host prefix that `data.page` uses.
  2. Every IP already recorded (`data.ips`) against a `Monitor` that
     visited that path is blocked in `monitor_blocked_ips` (`source:
     'scraper-path'`), same mechanism as `updateBlockedIps`.
  - Response: `{"success": true, "path": "...", "blocked_ips": [...]}`,
    or `{"success": false, "message": "No path provided"}` (422) if
    `path` is missing/empty.

- **`unflagPath`** (same auth as `flagScraperPath`): reverts it —
  `POST /monitor/handler?action=unflagPath` with
  `{"path": "wp-admin/install.php"}` removes the path from
  `monitor_blocked_paths` and clears the corresponding
  `MonitorMethod::isPathBlocked()` cache entry immediately. Response:
  `{"success": true, "path": "...", "was_flagged": true|false}` (`false`
  when the path wasn't flagged to begin with — not an error). Does
  **not** unblock the IPs `flagScraperPath` may have blocked because of
  that path — use `unblockIp` for those individually.

- **`markPathSafe`** (same auth as `flagScraperPath`, since `0.4.0`):
  `POST /monitor/handler?action=markPathSafe` with
  `{"path": "old-campaign-link"}` (host-less, same convention as
  `flagScraperPath`) records the path in `monitor_path_reviews` with
  `status: 'safe'` and `reviewed_at: now()`. Purely a review-state flag —
  unlike `flagScraperPath`, it blocks nothing; it just removes the path
  from `getPages`' `pending_review` queue (see below) once a human has
  confirmed a recurring `404` isn't a scraper probe (e.g. an old link
  that was removed on purpose). Response: `{"success": true, "path":
  "...", "status": "safe"}`, or `{"success": false, "message": "No path
  provided"}` (422) if `path` is missing/empty.
- **`unmarkPathSafe`** (same auth): reverts it — `POST
  /monitor/handler?action=unmarkPathSafe` with `{"path":
  "old-campaign-link"}` deletes the `monitor_path_reviews` row, so the
  path goes back to the default `pending` state. Response: `{"success":
  true, "path": "...", "was_safe": true|false}` (`false` when the path
  wasn't marked safe to begin with — not an error).

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
  `source: 'scraper-path'` for IPs blocked automatically by
  `flagScraperPath`) — same table, so both paths compose: an IP blocked
  manually stays blocked even if later also matched by a path flag, and
  vice versa. The per-IP block-check cache
  (`config('monitor.blocked_ip_cache_ttl')`, default 60s) is invalidated
  immediately for every IP in the request, so the block takes effect on
  the very next request instead of waiting out the cache TTL.

- **`unblockIp`** (same auth as `updateBlockedIps`): reverts it (and any
  `flagScraperPath` block on that IP) — `POST /monitor/handler?action=unblockIp`
  with `{"ip": "203.0.113.7"}` removes the IP from `monitor_blocked_ips`
  (whatever its `source`) and clears the block-check cache immediately.
  Response: `{"success": true, "ip": "...", "was_blocked": true|false}`
  (`false` when the IP wasn't blocked to begin with — not an error), or
  `{"success": false, "message": "No valid IP provided"}` (422) if `ip`
  is missing/invalid.

**Note**: `updateBlockedIps`/`unblockIp` never touch `blocked_until`/
`strike_count`/`lifetime_offense_count`/`last_offense_at` — those columns
only exist for the automatic path below. A manually blocked IP stays
permanent exactly as before, with no expiration and no escalation.

## Temporary, escalating IP blocking (`ScraperBlocker`)

Since `0.15.0`, `monitor_blocked_ips` supports **temporary, escalating**
blocks (`ScraperBlocker::registerOffense(string $ip, string $source)`),
modeled after fail2ban/CrowdSec rather than a static blocklist. This is the
mechanism behind *automatic* blocking (honeypot hits, scraper-signal
thresholds — see the changelog entries for the versions that wire each
trigger up); manual blocking via `updateBlockedIps`/`blockIps` is
unaffected and stays permanent (see note above).

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
- **Each subsequent offense** (before the block from the previous one
  fully decays, see below) doubles the exponent on `strike_count`: 2nd
  offense → `2^2 = 4h`, 3rd → `2^3 = 8h`, 4th → `2^4 = 16h`, and so on.
  `lifetime_offense_count` simply increments by 1 every time,
  unconditionally.
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
- **Honeypot hits** (`flagScraperPath`): any IP seen hitting a path
  flagged as a honeypot registers an offense immediately — a single hit
  is enough, no signal count needed, since a path nobody legitimate would
  ever request is already the highest-confidence signal available.
  Before `0.16.0` this called `BlockedIp::firstOrCreate()` directly
  (permanent, static block from the first hit); it now goes through the
  same escalating/expiring mechanism as every other automatic block.

Curating which paths count as a honeypot stays 100% manual (a human still
decides which routes nobody legitimate would ever hit) — only what
happens when one is hit follows the escalation system above.

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

Since `0.9.0`, every request rejected with `403` by `MonitorMethod` (both
branches: the IP itself is in `monitor_blocked_ips`, **or** the path it
hit is in `monitor_blocked_paths` — including a brand-new IP that was
never separately blocked, hitting an already-flagged honeypot path)
increments a per-IP counter in the new `monitor_block_results` table
(`ip` unique, `counter`, `last_attempt_at`). This is a raw "how many
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
  (logged via `Log::warning`) and the request is still blocked (`abort(403)`
  runs unconditionally, outside the try/catch).
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
they survive every later visit); `safe` is left out of the upsert
entirely, same as before — only `markIpSafe`/`unmarkIpSafe` set it.

Since `0.8.0`, the table also carries a `safe` column (boolean, default
`false`) — a persisted, human-reviewed verdict on that IP, set/cleared
via `markIpSafe`/`unmarkIpSafe` (see below) and **never** touched by
`IpStat::recordVisit()`. This matters because `flagged`/`flagged_signals`
are not cumulative (see above) — a bot-like burst from an IP a human
already reviewed and marked safe can still flip `flagged` back to `true`
on a later request. `safe` is what actually survives that: it's the
field `getVisitorsByIp`'s review queue (`filter=flagged` and its default
ordering) respects, not the raw `flagged` column.

- **`markIpSafe`** (`Authorization: Bearer <local_token>`, same auth as
  `markPathSafe`/`flagScraperPath` — never accepted with the ephemeral
  read token): `POST /monitor/handler?action=markIpSafe` with
  `{"ip": "203.0.113.7"}` sets `safe = true` on the matching
  `monitor_ip_stats` row (`IpStat::updateOrCreate`, so it also works for
  an IP with no tracked visits yet — e.g. pre-registering a known
  partner IP). Doesn't block or unblock anything; purely a review-state
  flag. Response: `{"success": true, "ip": "...", "safe": true}`, or
  `{"success": false, "message": "No valid IP provided"}` (422) if `ip`
  is missing/invalid.
- **`unmarkIpSafe`** (same auth): reverts it — `POST
  /monitor/handler?action=unmarkIpSafe` with `{"ip": "203.0.113.7"}` sets
  `safe = false` on the matching row (the row itself is never deleted —
  unlike `unmarkPathSafe`, `monitor_ip_stats` rows carry real visit
  history, not just a review flag). Response: `{"success": true, "ip":
  "...", "was_safe": true|false}` (`false` when the IP wasn't marked
  safe to begin with — not an error).

See "Paginated visitor/blocklist listing" below for the read/pagination
action on top of this table (`getVisitorsByIp`), including how `safe`
affects its `flagged` filter and default ordering.

## Paginated page listing (`getPages`)

`GET /monitor/handler?action=getPages` — same auth as `getData` (the
permanent `local_token` **or** the ephemeral read token from
`issueReadToken`). Aggregates every `Monitor.data.page`/`data.not_found`
into one entry per path (`host/path`, same key format as `data.page`)
instead of shipping raw `Monitor` rows. As of `0.3.0`, this listing no
longer carries a scraper signal at the path level — a path like `/` could
end up marked "possible scraper" just because one bot happened to pass
through it once. The scraper heuristic still runs exactly the same, it's
just scoped to the IP/visitor level now (see `getVisitorsByIp` below).
`flagScraperPath`'s honeypot mechanism (block a path + the IPs that
already visited it) is unaffected — it never depended on this field.

Since `0.4.0`, each path also carries a review `status` — `pending`
(default, never reviewed) or `safe` (marked via `markPathSafe`, see
above) — sourced from `monitor_path_reviews`, matched by suffix the same
way `blocked`/`monitor_blocked_paths` already was:

- `page` (default `1`), `per_page` (default `20`, max `100`).
- `filter`: `pending_review` (**default when `filter` is omitted**:
  `not_found = true` AND `status != 'safe'` AND `blocked = false` — the
  "still needs a human look" queue), `all` (the full dump — pass this
  explicitly to get the old default-listing behavior back), `404` (path
  was ever hit while the response was a 404), `clean` (not 404, not
  blocked), `blocked` (path is in `monitor_blocked_paths`, matched by
  suffix the same way `flagScraperPath` does). An unknown `filter` value
  returns `422`.
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

## Paginated visitor/blocklist listing (`getVisitorsByIp`, `getBlockedIps`, `getBlockedPaths`)

Same auth as `getData`/`getPages` (permanent `local_token` **or** the
ephemeral read token from `issueReadToken`).

- **`getVisitorsByIp`**: paginated/filterable listing of
  `monitor_ip_stats` (one row per unique IP, maintained by
  `IpStat::recordVisit()` on every tracked request — see "Per-IP stats"
  above). Params: `page` (default `1`), `per_page` (default `20`, max
  `100`), `filter` (`all` default, `flagged`, `clean`, `blocked` — an
  IP counts as `blocked` if it's in `monitor_blocked_ips`; unknown
  value returns `422`), `date_from`/`date_to` (optional, filters by the
  row's `last_seen` — "this IP was active in this window", same
  approximation as `getPages`). Response: `{"success": true, "data":
  [{"ip": "1.2.3.4", "visit_count": 12, "first_seen": "...",
  "last_seen": "...", "flagged": false, "flagged_signals": null,
  "safe": false, "blocked": false}, ...], "meta": {"page", "per_page",
  "total", "last_page"}}`.
  - Since `0.8.0`: `filter=flagged` now additionally excludes IPs marked
    `safe` (`where('flagged', true)->where('safe', false)`) — an IP a
    human already reviewed and marked safe via `markIpSafe` no longer
    reappears in this queue, even if a later request from it flips the
    (non-cumulative) `flagged` column back to `true`. Regardless of which
    `filter` is requested, results are also always ordered with
    `flagged = true AND safe = false` rows first (the actual "needs
    review" work queue), falling back to the existing `visit_count desc`
    ordering within each group.
  - Since `0.12.0`: `filter=flagged` also excludes IPs already present in
    `monitor_blocked_ips` — an IP that's already been blocked has already
    been confirmed as a scraper, so it no longer needs to show up in the
    "possible scraper" queue too. It still shows up under
    `filter=blocked`, as before.
- **`getVisitorPaths`** (since `0.6.0`): given an `ip`
  (`{"success": false, "message": "No valid IP provided"}`, `422`, if
  missing/invalid), scans every `Monitor` whose `data.ips` contains that
  IP and aggregates the paths (`data.page`) it's been seen on — lets you
  confirm visually that an IP is a scraper before blocking it. No
  pagination/caching: the result set per IP is small and this is a
  lookup triggered on demand (e.g. expanding a row in the dashboard), not
  loaded on every page view. Response: `{"success": true, "ip": "1.2.3.4",
  "paths": [{"path": "example.com/wp-admin/install.php", "hits": 3},
  ...]}`, sorted by hits descending.
- **`getBlockedIps`** / **`getBlockedPaths`**: plain paginated listing
  of `monitor_blocked_ips` (`{"ip", "source", "created_at"}`) /
  `monitor_blocked_paths` (`{"path", "created_at"}`) — no `filter`
  param, just `page`/`per_page`. Ordered newest-first.

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
  restricts the delete to rows belonging to an IP present in
  `monitor_blocked_ips` (confirmed/blocked, not just flagged by the live
  heuristic) — matched via `data.ips` on `Monitor`, the `ip` column on
  `IpStat` — instead of every row past the cutoff.
  > ⚠️ **Breaking change in v0.7.0**: this parameter was named
  > `only_scraper_flagged` and matched `data.flags.scraper`/
  > `IpStat.flagged` instead — the automatic, non-cumulative heuristic
  > signal from the *last* request seen from that IP, never reviewed by
  > anyone. That made `pruneData` capable of permanently deleting rows
  > for an IP on an unreviewed false positive. It now matches
  > `monitor_blocked_ips` (an IP the user actually confirmed/blocked)
  > instead.

Response: `{"success": true, "monitors_deleted": 12, "ip_stats_deleted": 4}`.

Bumps the `getPages`/`getVisitorsByIp` listing cache version counters
(`invalidatePagesCache`/`invalidateListingsCache`) whenever something
was actually deleted from the corresponding table, same mechanism as
`flagScraperPath`/`updateBlockedIps` etc.

## Advanced usage

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

### `updateRules` (reserved, not implemented yet)

The handler action `updateRules` exists and is routed (same auth as
`updateBlockedIps`), but it's currently a stub — it always responds
`{"success": true, "message": "Monitoring rules updated (stub)"}` without
reading its input or changing any behavior. Don't build against it as a
real feature yet.