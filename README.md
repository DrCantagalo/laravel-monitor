# Laravel Monitor (v0.1.24)

**Laravel Monitor** is an experimental package designed to test the initial installation flow for a lightweight Laravel package providing basic CRM tools, access monitoring, and anti-scraper features. Designed to track visits, manage sessions, and detect potentially malicious scrapers.

> ⚠️ **This is an early testing release.**  
> API and config shape may still change between minor versions. See `CHANGELOG.md` for what each release actually added/fixed.

## Remember-me (returning visitor recognition)

When `MonitorMethod` creates a new `Monitor` record for a first-time
visitor (session-based, non-bot request), it generates a random token,
stores it in `data['id-token']`, and attaches it to the response as a
long-duration cookie so the same browser can be recognized again after its
PHP session expires.

Contract for the front-end of the host application:

- **Cookie name**: `config('monitor.remember_cookie')`, default
  `monitor_id_token`. Duration: `config('monitor.remember_cookie_days')`
  days, default 1825 (5 years). The package sets this cookie itself — the
  front-end never needs to create or read it directly (it isn't meant to
  be parsed from `document.cookie`; the browser just carries it back
  automatically on every request).
- **Automatic reconnection (since v0.1.22)**: `MonitorMethod` now also
  checks the `monitor_id_token` cookie directly, on the very first request
  of a new PHP session — before any front-end JS has had a chance to run.
  This closes a race present in earlier versions, where the first page
  load of a new session always created a brand-new `Monitor` (overwriting
  the cookie with a fresh token) before the dedicated endpoint below could
  ever run, permanently losing the original visitor's identity. Host apps
  don't need to do anything for this — it's transparent — but it means the
  cookie alone is now sufficient; the dedicated endpoint is a
  belt-and-suspenders option, not a requirement, for host apps that want
  to force reconnection at a specific point (e.g. right after consuming a
  cookie-consent flow that may have delayed the first tracked request).
- **Endpoint**: `GET /monitor/remember-me`. No request body needed — the
  cookie above travels with the request automatically. Calling it is
  optional since v0.1.22 (see above), but still supported for host apps
  that already integrate with it, or that want to trigger reconnection
  explicitly instead of relying on the automatic first-request check.
  Response: `{"success": true}` when a matching visitor was found and
  merged into the current session, or `{"success": false, "message": "..."}`
  when there's no cookie yet (first-ever visit) or no matching record
  (cookie is stale/invalid).
- Calling the endpoint sets `session(['remember_me' => $token])`; the
  `MonitorMethod` middleware picks that up on the very same request (it
  runs after the controller, on the way back out) and merges the returning
  visitor into the current PHP session.

## Arbitrary visitor data (segmentation/tags)

Lets the host application's front-end attach arbitrary key/value pairs
(language, tags, preferences, etc.) to the `Monitor` record of the current
visitor session — a segmentation/tagging base, not a CRM/lead system yet.

- **Endpoint**: `GET /monitor/update-data?data[key]=value`. Requires an
  active monitor session (i.e. `MonitorMethod` must have already run at
  least once for this visitor — same precondition as `remember-me`). No
  request body needed, query string only, same GET-to-avoid-CSRF rationale
  as `remember-me`.
- Nested query params are read as-is: `?data[lang]=pt&data[tags][]=newsletter`
  merges `lang` and `tags` into the Monitor's `data`.
- **Protected keys**: `sessions`, `ips`, `visits`, `page`, `id-token`, `ua`
  are silently ignored if present in the payload — these are written
  exclusively by `MonitorMethod`/`Monitor::newVisit`, and letting the
  front-end overwrite them would corrupt tracking. Every other key is
  accepted freely (schema intentionally left open — see below).
- Response: `{"success": true, "monitor_id": <id>}` on success;
  `{"success": false, "message": "..."}` (400) when there's no active
  monitor session yet, or (422) when no `data` payload was sent.
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
  (`Monitor::where('data->user_id', $id)->get()`, joining the rows
  yourself) — never a physical merge of the raw rows, which would race
  under concurrent writes from multiple devices.
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
  `user_id` is in `MonitorController::PROTECTED_DATA_KEYS` — the
  public `update-data` endpoint (see "Arbitrary visitor data" above)
  can never overwrite it, so a visitor's own front-end JS can't spoof
  a different `user_id` onto their row.

## Ephemeral read token + dedicated CORS (dashboard direct fetch)

The dashboard (`monitor.cantagalo.it`) can call `/monitor/handler?action=getData`
directly from the end user's browser instead of always proxying through the
host application's server. The permanent `local_token` never leaves the host
application's backend — only a short-lived, read-only token does.

- **`issueReadToken`** (`Authorization: Bearer <local_token>`, same auth as
  the other admin actions): generates a random token, stores it in cache for
  `config('monitor.read_token_ttl_minutes')` minutes (default 15), and
  returns `{"success": true, "token": "...", "expires_at": "..."}`.
- The token returned by `issueReadToken` is accepted as a bearer **only for
  the `getData` action**. `clearData`, `updateBlockedIps`, `updateRules`, and
  `issueReadToken` itself always require the permanent `local_token` — a
  read token cannot mint another token or do anything beyond reading.
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

## Scraper signal detection

For requests **without an active session** (API calls, bots, most real
scrapers — `MonitorMethod` delegates these to `AnonymousVisitorTracker`
instead of the session-based tracker), each request is scored against a
small set of heuristics before being recorded. This is detection only —
it marks the `Monitor` record, it never blocks anything by itself
(blocking is `flagScraperPath`/`updateBlockedIps`, both actions your own
dashboard/automation can trigger after inspecting these flags).

Signals checked by `AnonymousVisitorTracker::detectScraperSignals`:

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