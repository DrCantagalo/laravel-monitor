# Laravel Monitor (v0.1.12)

**Laravel Monitor** is an experimental package designed to test the initial installation flow for a lightweight Laravel package providing basic CRM tools, access monitoring, and anti-scraper features. Designed to track visits, manage sessions, and detect potentially malicious scrapers.

> ⚠️ **This is an early testing release.**  
> The only purpose of v0.1.8 is validating installation, configuration storage, and server registration workflow.

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
- **Endpoint**: `GET /monitor/remember-me`. No request body needed — the
  cookie above travels with the request automatically. Call this once per
  page load, as early as possible (e.g. on page ready), for visitors that
  don't yet have an active recognized session. Response:
  `{"success": true}` when a matching visitor was found and merged into
  the current session, or `{"success": false, "message": "..."}` when
  there's no cookie yet (first-ever visit) or no matching record (cookie
  is stale/invalid).
- Calling the endpoint sets `session(['remember_me' => $token])`; the
  `MonitorMethod` middleware picks that up on the very same request (it
  runs after the controller, on the way back out) and merges the returning
  visitor into the current PHP session.

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