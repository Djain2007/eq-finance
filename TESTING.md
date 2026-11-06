# EQ Finance — Testing report

Build date: 2026-11-04 · Version 1.0.0

## Environment used

| Component | Version / notes |
|-----------|-----------------|
| PHP | 8.3.6 (CLI + Apache `mod_php`), extensions: curl, dom, pdo_mysql, mbstring, json, openssl |
| Database | MariaDB 10.11.14, database created with `database/install.sql` |
| Web servers | PHP built-in server with `tests/router.php`; **Apache 2.4 with `AllowOverride All`**, project installed in a sub-folder (`/eq-finance`) to mirror a cPanel sub-directory deployment |
| Network | **Outbound access to `www.google.com` was blocked by the build sandbox.** The HTTP transport was therefore exercised with a stub (see "Not verified" below). |

Two layers of tests were run:

1. **`php tests/run-tests.php`** — 91 automated assertions, in-process, against MariaDB, with the upstream transport replaced by fixtures. Result: **91 passed, 0 failed.**
2. **HTTP-level tests with `curl`** against the PHP built-in server and against Apache (`.htaccess` rules active).

## Results

### Admin panel

| Test | Result | How |
|------|--------|-----|
| First-run setup page shown when no admin exists; `/admin/`, `/admin/login.php`, `/admin/dashboard.php` all redirect to setup | Pass | curl |
| Setup rejects missing CSRF token (403), weak password (<12 chars), mismatched passwords | Pass | curl |
| Setup creates admin, redirects to login; setup afterwards returns **403** permanently | Pass | curl + DB |
| Password stored as bcrypt hash (`$2y$…`), never plaintext | Pass | DB inspection |
| Login with wrong password → generic error; 5 failures → 15-minute lockout; counter cleared on success | Pass | curl |
| Login success → 302 to dashboard; **session ID regenerated** at login (fixation protection) | Pass | cookie jar comparison |
| Session cookie: `HttpOnly`, `SameSite=Lax`, path scoped to `/admin/`; `Secure` set when served over HTTPS | Pass | cookie jar |
| Idle session expiry (config `admin.session_lifetime`) destroys the session and shows "Your session expired" on the login page | Pass | curl with lifetime set to 2 s |
| Unauthenticated access to every admin page redirects to login | Pass | curl |
| Logout: GET shows confirmation, POST (CSRF) ends session; dashboard afterwards redirects to login | Pass | curl |
| Dashboard, API Keys, API Usage, Request Logs, API Settings, Cache Settings, System Status render (HTTP 200) and pass `tidy` HTML validation with no errors/warnings | Pass | curl + tidy |
| Settings: validation errors (empty name, batch size > 50), save, maintenance mode toggles API to 503, disable/enable | Pass | curl + API |
| Admin password change: wrong current password rejected, valid change works, login with new password | Pass | curl |
| Cache Settings: purge all / purge one symbol / run cleanup | Pass | curl |
| XSS: key name `<script>alert(1)</script>` rendered escaped | Pass | curl |
| CSRF: POST without token → 403; POST with valid token but foreign `Origin` → 403 | Pass | curl |
| SQL injection attempts in log filters (`' OR 1=1--`, `1 OR 1`) → handled safely (prepared statements, 200, no error) | Pass | curl |

### API key management

| Test | Result |
|------|--------|
| Create key: format `eqf_live_` + 48 hex, shown once; DB stores SHA-256 hash and 15-char prefix only | Pass |
| Limits: blank/0 → unlimited (NULL); per-minute/day/month stored; expiry stored (UTC) | Pass |
| Disable → `403 KEY_DISABLED`; enable → works again | Pass |
| Rotate → new key issued with identical limits, old key `403 KEY_REVOKED`, `replaced_by_id` linked | Pass |
| Revoke → immediate `403 KEY_REVOKED`; revoked key cannot be re-enabled or edited | Pass |
| Expired key → `403 KEY_EXPIRED` | Pass |
| Last-used timestamp updated on each authenticated request | Pass |
| Usage statistics per key, link to filtered logs | Pass |

### API

| Test | Result |
|------|--------|
| `GET /api/v1/status` without key → 200, DB status; with DB down → 503 `degraded`; with no config → 503 `SERVICE_UNAVAILABLE` | Pass |
| Missing key → `401 MISSING_API_KEY` (+ `WWW-Authenticate`); malformed/unknown → `401 INVALID_API_KEY` | Pass |
| `Authorization: Bearer` forwarded through Apache (CGI header rule) and `X-API-Key` alternative | Pass |
| Missing `symbol` → `400 INVALID_REQUEST`; `FAKE:ABC` → `400 UNSUPPORTED_EXCHANGE`; malformed → `400 INVALID_SYMBOL`; lower-case accepted and normalised | Pass |
| Rate limit: 5/min key allows 5, 6th → `429` with `Retry-After` and `X-RateLimit-*` headers; **rejected requests refunded** (counter stays at 5) | Pass |
| Batch: dedup, per-symbol success/error, `meta.requested/succeeded/failed`; 11 symbols with max 10 → `400 BATCH_LIMIT_EXCEEDED`; each symbol costs one rate-limit unit | Pass |
| `/search` → `501 UNSUPPORTED_OPERATION` with supported exchanges | Pass |
| Unknown endpoint → `404 NOT_FOUND`; `POST` → `405 METHOD_NOT_ALLOWED` | Pass |
| Upstream failure (network blocked) → `502 SOURCE_UNAVAILABLE` with `meta.source_url`; nothing cached | Pass |
| Cache miss → `meta.cached=false`; second request → `cached=true`, **original** `retrieved_at` preserved, `cache_age_seconds`, `cache_expires_at` | Pass (unit) |
| Failed fetch never overwrites valid cached data; lock released after failure | Pass (unit) |
| Stale fallback (opt-in): served with `stale=true` + `upstream_error`; not served beyond max stale age; off by default | Pass (unit) |
| `SYMBOL_NOT_FOUND` → 404 and never cached | Pass (unit) |
| TTL 0 disables caching | Pass (unit) |
| Stampede: **6 concurrent processes** requesting the same uncached symbol → exactly **1 upstream fetch**, 5 cache hits | Pass |
| HTTPS enforcement: `force_https=true` redirects admin to HTTPS and rejects plain-HTTP API calls (`X-Forwarded-Proto: https` honoured for proxies) | Pass |

### Scraper / parser (offline)

| Test | Result |
|------|--------|
| Parser extracts name, price, currency, previous close, change, change %, day range, 52-week range, market cap (abbreviation expanded), avg volume, P/E, EPS, dividend yield, description, website, source timestamp from a fixture modelled on the current Google Finance markup | Pass |
| Missing field → `null` (never 0) and `availability[field]=false` | Pass |
| Negative moves keep their sign (aria-label "Down by") | Pass |
| Fallbacks: price from header text and currency from symbol when `data-*` attributes are absent; name from `<title>` | Pass |
| "Couldn't find any match" page → `SYMBOL_NOT_FOUND`; unrelated page → `EXTRACTION_FAILED`; empty body → failure | Pass |
| Transport error mapping: timeout → `SOURCE_UNAVAILABLE`; "unusual traffic" page → `SOURCE_BLOCKED`; `/sorry/` redirect → `SOURCE_BLOCKED` | Pass |
| Redirect policy: only `https://www.google.com/finance/quote/…` is followed (max 2 hops); anything else is rejected | Pass (code path + unit for /sorry/) |

### Security / deployment (Apache, `.htaccess` active, sub-folder install)

| Test | Result |
|------|--------|
| Direct access to `config/config.php`, `config/config.example.php`, `database/install.sql`, `app/bootstrap.php`, `storage/logs/app.log`, `storage/`, `tests/run-tests.php`, `api/v1/handlers/stock.php`, `cron/`, `cron/.htaccess`, `.htaccess`, `.gitignore`, `README.md` → **403** | Pass |
| `cron/cleanup.php` reachable over HTTP but returns 403 without the configured token; works from CLI | Pass |
| Directory listing disabled | Pass |
| Clean URLs: `/`→`/docs`, `/docs`, `/assets/...`, `/api/v1/`, `/api/v1/stock`, `/api/v1/stock.php`, `/admin/api-keys` | Pass |
| SSRF: validator rejects `localhost:80`, `127.0.0.1:80`, path traversal, spaces, control characters, `http://…`, overlong symbols; URL built only from allow-listed exchange + validated symbol, URL-encoded (`M&M` → `M%26M`) | Pass |
| Errors never displayed; written to `storage/logs/php-errors.log` / `app.log`; API error bodies carry no internals | Pass |
| `install.sql` is idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`) and runs on an empty database | Pass |
| Fresh-install flow: no config → friendly 503 page; wrong DB credentials → friendly 503 page + log entry; empty DB → setup | Pass |

## Not verified (honest gaps)

1. **Live extraction from Google Finance.** The sandbox could not open a connection to `www.google.com`, so the fetch + parse pipeline was validated against an HTML fixture and a transport stub, not against the live site. After deployment, run **Admin → System Status → Upstream probe** with a few symbols. If the probe returns `EXTRACTION_FAILED`, Google's markup differs from the fixture and the XPath strategies in `app/Services/StockParser.php` need adjusting (the fixture in `tests/fixtures/` documents the expected structure).
2. **Visual rendering.** No browser was available in the build environment, so the admin UI was validated structurally (HTML passes `tidy`, CSS is hand-reviewed) rather than by screenshot. The layout uses CSS grid/flex with a 860px breakpoint, a collapsible sidebar on mobile and `prefers-color-scheme` + manual dark-mode toggle.
3. **LiteSpeed.** Tested on Apache 2.4 only. LiteSpeed is `.htaccess`-compatible but was not exercised.
4. **PHP-FPM/CGI `Authorization` forwarding** was tested under `mod_php` with the `SetEnvIf`/`RewriteRule` forwarding rules present; on a host that strips the header anyway, use `X-API-Key`.

## Re-running the tests

```bash
# against a TEST database (truncates api_keys, api_usage, stock_cache, rate_limits, login_attempts)
php tests/run-tests.php

# dev server with .htaccess emulation
php -S 127.0.0.1:8080 -t . tests/router.php
```
