# Laravel Monitor (v0.5.0)

**Laravel Monitor** is an experimental package designed to test the initial installation flow for a lightweight Laravel package providing basic CRM tools, access monitoring, and anti-scraper features. Designed to track visits, manage sessions, and detect potentially malicious scrapers.

> ⚠️ **This is an early testing release.**  
> API and config shape may still change between minor versions. See `CHANGELOG.md` for what each release actually added/fixed.

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
  (`COUNT(*)`/`MAX(updated_at)`) and pagination run in SQL, grouped by
  the same indexed generated column `Monitor::forUserId()` uses on
  MySQL (`monitors_user_id`) — never the raw `data->user_id`
  expression, for the same index-matching reason documented above.
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
  `getBlockedIps`, `getBlockedPaths`, `getUsers`, `getUserVisits`)**.
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
`404` (`data.not_found[path] = true`) — lets a dashboard built on top of
`getData` flag paths that don't actually exist on the monitored site (a
common scraper tell: `/wp-admin/install.php` on a site that isn't
WordPress).

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

**Auto-export** (opt-in, default off): set `config('monitor.denylist_auto_export')`
to `true` to regenerate the file automatically every time
`monitor_blocked_ips` changes (`updateBlockedIps`/`unblockIp`/
`flagScraperPath` — not `unflagPath`, which never touches that table).
Uses `config('monitor.denylist_format')` since there's no CLI flag to read
from at that point. Fails open: a write error (e.g. permissions) is logged
and never breaks the block/unblock action itself. The `artisan` command
keeps working manually regardless of this flag — a fresh install doesn't
start writing to disk without the consuming app explicitly opting in.

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

See "Paginated visitor/blocklist listing" below for the read/pagination
action on top of this table (`getVisitorsByIp`).

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
  "blocked": false}, ...], "meta": {"page", "per_page", "total",
  "last_page"}}`.
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
- `only_scraper_flagged` (optional boolean, default `false`): when
  `true`, restricts the delete to rows flagged as scraper —
  `data.flags.scraper` on `Monitor`, the `flagged` column on
  `IpStat` — instead of every row past the cutoff.

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