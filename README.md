[![Powered by DartNode](https://dartnode.com/branding/DN-Open-Source-sm.png)](https://dartnode.com "Powered by DartNode - Free VPS for Open Source")
<!-- Verified on DartNode: DNOS-1B1C8240 -->
# EQ Finance

[![CI](https://github.com/Djain2007/eq-finance/actions/workflows/ci.yml/badge.svg)](https://github.com/Djain2007/eq-finance/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ed.svg)](docker-compose.yml)

**Lightweight, self-hosted Google Finance scraping API with an admin dashboard — built for ordinary PHP/MySQL shared hosting and Docker.**

EQ Finance reads publicly visible quote pages from Google Finance, extracts the structured data it can find (price, change, ranges, market cap, P/E, description, …) and serves it as a clean, versioned JSON REST API protected by API keys, rate limits and a MySQL-backed cache. A premium, minimal admin panel manages keys, limits, logs and settings.

- Core PHP 8.1+ · MySQL/MariaDB · HTML/CSS/vanilla JS — no frameworks, no Composer, no Node, no paid services
- **One-Command Setup**: Zero-configuration launch on Linux, macOS, and Windows via Docker Compose or native scripts
- Runs on cPanel-style shared hosting with Apache/LiteSpeed and `.htaccess`
- Secure by default: hashed API keys, hashed passwords, CSRF, prepared statements, strict symbol validation (no SSRF), HTTPS enforcement
- Honest by design: never fabricates data, never caches failures, never bypasses CAPTCHAs or anti-bot protections

> Google Finance has no official public API. EQ Finance treats it as an external, unstable data source. When the source changes or blocks requests, the API reports it clearly (`EXTRACTION_FAILED`, `SOURCE_BLOCKED`) instead of guessing. See [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md).

---

## ⚡ Quick Start: One-Command Installation

You can install and run EQ Finance on **any machine (Linux, macOS, or Windows)** with a single command.

### Option A: Docker (Universal — Recommended)

Clone and run with Docker Compose:

```bash
# Clone the repository
git clone https://github.com/Djain2007/eq-finance.git
cd eq-finance

# Start everything with one command (Linux, macOS, or Windows)
docker compose up -d
```

Or run the platform-specific one-command launcher:

| Platform | Command |
|---|---|
| **Linux / macOS** | `./install.sh` |
| **Windows (CMD)** | `install.bat` |
| **Windows (PowerShell)** | `powershell -ExecutionPolicy Bypass -File install.ps1` |
| **Any (Makefile)** | `make install` |

Once started, open:
- **Testing Studio**: [http://localhost:8080/test](http://localhost:8080/test) (or open `test-client.html` in your browser)
- **Admin Setup**: [http://localhost:8080/admin/](http://localhost:8080/admin/) (create the admin account on first visit)
- **API Documentation**: [http://localhost:8080/docs](http://localhost:8080/docs)
- **System Health**: [http://localhost:8080/api/v1/status](http://localhost:8080/api/v1/status)

### 🌐 Accessing over Local Network (LAN) / Remote Server

If you have hosted EQ Finance on a dedicated machine or server (e.g., `192.168.29.68:8080`) and want to access it from your laptop or external client:

1. **Web Access**:
   - **Testing Studio**: `http://<SERVER_IP>:8080/test`
   - **Admin Panel**: `http://<SERVER_IP>:8080/admin/`
   - **API Documentation**: `http://<SERVER_IP>:8080/docs`
2. **Standalone Client (`test-client.html`)**:
   - Double-click `test-client.html` on any laptop or client machine.
   - Enter your Server Base URL: `http://<SERVER_IP>:8080/api/v1` (or `http://<SERVER_IP>:8080`).
   - Enter your API Key and click **Verify Connection**.
   - Full CORS support is enabled out of the box, allowing instant cross-origin queries!

To stop the containers:
```bash
docker compose down
```

---

## Contents

1. [Quick Start (One-Command Install)](#-quick-start-one-command-installation)
2. [Requirements](#1-requirements)
3. [Project structure](#2-project-structure)
4. [Installation on cPanel shared hosting](#3-installation-on-cpanel-shared-hosting)
5. [Configuration reference](#4-configuration-reference)
6. [Using the API](#5-using-the-api)
7. [Admin panel](#6-admin-panel)
8. [Caching and rate limiting](#7-caching-and-rate-limiting)
9. [Cron / housekeeping](#8-cron--housekeeping)
10. [Security notes](#9-security-notes)
11. [Local development](#10-local-development)
12. [Testing](#11-testing)
13. [Troubleshooting](#12-troubleshooting)
14. [Recovering admin access](#13-recovering-admin-access)

---

## 1. Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.1 or newer, with `curl`, `dom`, `pdo_mysql`, `mbstring`, `json`, `openssl` (all standard on cPanel) |
| Database | MySQL 5.7+ or MariaDB 10.3+ (InnoDB, utf8mb4) |
| Web server | Apache or LiteSpeed with `mod_rewrite` and `.htaccess` enabled (default on cPanel) |
| Outbound HTTPS | The server must be able to reach `https://www.google.com` |
| Cron (optional) | For housekeeping; the same job can also be run from the admin panel |

No shell access, Composer, Node.js, Redis, Docker or VPS is required.

## 2. Project structure

```
eq-finance/
├── .github/workflows/ci.yml  CI matrix tests across Linux, macOS, Windows & Docker
├── .htaccess                 Routing + protection of private folders (root)
├── admin/                    Admin panel (login, dashboard, keys, logs, settings…)
│   └── includes/             Shared admin layout and helpers
├── api/v1/                   REST API (index.php router + thin endpoint files)
│   └── handlers/             Endpoint implementations (not web-accessible)
├── app/                      Application code (not web-accessible)
│   ├── Auth/                 Sessions, CSRF, login rate limiting, admin auth
│   ├── Config/               Config loader
│   ├── Database/             PDO connection
│   ├── Helpers/              JSON responses, logging, URL/base-path helpers
│   ├── Middleware/           API authentication + rate-limit guard
│   └── Services/             Scraper, parser, cache, API keys, rate limits, stats, settings
├── config/
│   └── config.example.php    Copy to config.php (or outside docroot) and edit
├── cron/cleanup.php          Housekeeping job (CLI or token-protected HTTP)
├── database/install.sql      MySQL schema + default settings
├── docker-compose.yml        Multi-container stack (Apache + PHP 8.2 + MariaDB 10.11)
├── docker-entrypoint.sh      Automated container initialization & DB schema importer
├── Dockerfile                Production-ready PHP 8.2 Apache container
├── install.sh                Universal one-command installer for Linux & macOS
├── install.bat               One-command launcher for Windows CMD
├── install.ps1               One-command launcher for Windows PowerShell
├── Makefile                  Developer shortcuts (make install, up, down, test)
├── public/
│   ├── docs.php              Public API documentation (served at /docs)
│   ├── index.php             Landing page (redirects to /docs)
│   └── assets/               CSS / JS
├── storage/logs/             Application logs (must be writable)
├── tests/                    Automated test-suite + HTML fixtures + dev router
├── KNOWN_LIMITATIONS.md
├── TESTING.md
└── README.md
```

`app/`, `config/`, `database/`, `storage/`, `tests/` and `api/v1/handlers/` each contain their own `.htaccess` that denies web access, and every file inside them refuses to run unless loaded through the application bootstrap.

## 3. Installation on cPanel shared hosting

The steps below assume the typical cPanel layout where `public_html` is your document root. The whole project can live either **directly in `public_html`** (API at `https://yourdomain.com/api/v1/…`) or **in a sub-folder** such as `public_html/eq-finance` (API at `https://yourdomain.com/eq-finance/api/v1/…`). Both work without changes; the application detects its own base path.

### Step 1 — Upload the files

1. In cPanel open **File Manager** (or use FTP/SFTP).
2. Upload the contents of the `eq-finance` folder into `public_html` (or a sub-folder of it).
3. Make sure hidden files were uploaded too — in File Manager, click **Settings → Show Hidden Files (dotfiles)** and confirm you can see `.htaccess` in the project root, in `app/`, `config/`, `database/`, `storage/`, `tests/`, `cron/` and `api/v1/handlers/`.
4. Make `storage/logs` writable (usually already is; otherwise set permissions to `755` and the owner to your cPanel user — `777` is **not** needed on cPanel).

### Step 2 — Create the MySQL database

1. cPanel → **MySQL® Databases**.
2. Under *Create New Database*, enter a name (e.g. `eqfinance`) → **Create Database**. cPanel prefixes it with your account name, e.g. `cpuser_eqfinance`.

### Step 3 — Create the MySQL user

1. On the same page, under *MySQL Users → Add New User*, enter a username and a strong password (use the generator) → **Create User**. Note the full name, e.g. `cpuser_eqf`.

### Step 4 — Assign privileges

1. Under *Add User To Database*, select the user and the database → **Add**.
2. On the privileges screen tick **ALL PRIVILEGES** → **Make Changes**.

### Step 5 — Configure database credentials

EQ Finance looks for its configuration file in this order and uses the first one it finds:

1. The path in the `EQF_CONFIG_PATH` environment variable (if set)
2. `eq-finance-config.php` in the **parent folder of the project**
3. `config/config.php` **inside the project**

Recommended placements:

| Where the project is | Put the config at | Why |
|----------------------|-------------------|-----|
| Directly in `public_html` | `/home/cpuser/eq-finance-config.php` (option 2) | Outside the web root entirely |
| In a sub-folder, e.g. `public_html/eq-finance` | `/home/cpuser/eq-finance-config.php` **and** add `SetEnv EQF_CONFIG_PATH /home/cpuser/eq-finance-config.php` as the first line of the project's root `.htaccess` (option 1) | Outside the web root; most cPanel hosts allow `SetEnv` |
| Either | `config/config.php` (option 3) | Simplest. The `config/` folder is denied by `.htaccess`, and even if PHP executed the file directly it only `return`s an array and prints nothing |

Then:

1. Copy `config/config.example.php` to the chosen location/name.
2. Edit the `db` section:
   ```php
   'db' => [
       'host'    => 'localhost',
       'port'    => 3306,
       'name'    => 'cpuser_eqfinance',
       'user'    => 'cpuser_eqf',
       'pass'    => 'the-password-you-generated',
       'charset' => 'utf8mb4',
   ],
   ```
3. Leave `'force_https' => true` if your site has an SSL certificate (cPanel provides free AutoSSL). Set it to `false` only for an HTTP-only development server.
4. Optionally set `'display_timezone'` (e.g. `'Asia/Kolkata'`) so admin pages show local times.
5. Optionally set `cron.token` to a long random string if you will run the cleanup job over HTTP.

Optionally, you can also keep logs outside the web root: create a folder `eq-finance-storage/logs` next to `eq-finance-config.php`; it is used automatically when present.

### Step 6 — Import the database schema

1. cPanel → **phpMyAdmin** → select your database in the left column.
2. Click the **Import** tab → **Choose File** → select `database/install.sql` from your computer → **Go**.
3. You should see 7 tables: `admins`, `api_keys`, `api_usage`, `login_attempts`, `rate_limits`, `settings`, `stock_cache`.

### Step 7 — Create the initial admin account

1. Open `https://yourdomain.com/admin/` (or `https://yourdomain.com/eq-finance/admin/`).
2. You are redirected to the one-time **setup page**. Choose a username, email and a password of at least 12 characters.
3. After the first admin exists the setup page is permanently disabled (it returns 403).

### Step 8 — Log in

Sign in at `/admin/login.php` with the account you just created.

### Step 9 — Create your first API key

1. Sidebar → **API Keys**.
2. Enter a name (e.g. *Mobile app*), optional limits and expiry → **Create key**.
3. The full key (`eqf_live_…`) is displayed **once**. Copy it now; only its SHA-256 hash is stored.

### Step 10 — Test the API

```bash
# Health check (no key required)
curl https://yourdomain.com/api/v1/status

# A quote
curl -H "Authorization: Bearer eqf_live_YOUR_KEY" \
     "https://yourdomain.com/api/v1/stock?symbol=NSE:RELIANCE"
```

Also run the **Upstream probe** on *Admin → System Status*: it performs one live request to Google Finance from your server and shows exactly what was extracted, which is the fastest way to confirm the host can reach the source.

The public documentation page is at `https://yourdomain.com/docs`.

### Verify the protections

Open these URLs in a browser — every one must return **403 Forbidden**:

- `/config/config.php` and `/config/config.example.php`
- `/database/install.sql`
- `/app/bootstrap.php`
- `/storage/logs/app.log`
- `/tests/run-tests.php`

If any of them is served, `.htaccess` files are not being honoured (see Troubleshooting).

## 4. Configuration reference

File-based settings (`config.php`) are for things that must exist before the database is reachable or that should not be editable from the web UI:

| Key | Purpose |
|-----|---------|
| `db.*` | Database credentials. `host` may be a socket path. |
| `app.debug` | Keep `false` in production. Errors are never displayed to clients either way. |
| `app.force_https` | Redirects admin pages to HTTPS and rejects plain-HTTP API calls. |
| `app.base_url` | Override auto-detected base URL (rarely needed, e.g. behind a proxy). |
| `app.display_timezone` | Timezone for admin pages (data is stored in UTC). |
| `admin.session_lifetime` | Idle timeout in seconds (default 7200). |
| `admin.login_max_attempts` / `admin.login_lockout` | Brute-force protection (default 5 attempts, 15-minute lockout). |
| `scraper.allowed_exchanges` | Allow-list of exchange prefixes. Anything else is rejected before a URL is built. |
| `scraper.source_host` | Fixed upstream host. |
| `scraper.max_response_bytes` | Cap on downloaded page size. |
| `cron.token` | Secret for running `cron/cleanup.php` over HTTP. |

Everything else — API name/description/version, global enable switch, maintenance mode, cache duration, stale-serving, timeouts, batch size, default limits, User-Agent, log retention — is edited in **Admin → API Settings** and **Admin → Cache Settings** and stored in the `settings` table.

## 5. Using the API

Full reference with examples: `https://yourdomain.com/docs`.

| Endpoint | Auth | Description |
|----------|------|-------------|
| `GET /api/v1/stock?symbol=NSE:RELIANCE` | key | One quote |
| `GET /api/v1/stocks?symbols=NSE:RELIANCE,NSE:TCS` | key | Up to *max batch size* quotes (default 10) |
| `GET /api/v1/search?q=…` | key | Returns `501 UNSUPPORTED_OPERATION` (no permitted search source) |
| `GET /api/v1/status` | none | Health, version, database status |
| `GET /docs` | none | Documentation |

Authentication: `Authorization: Bearer eqf_live_…` (or `X-API-Key: eqf_live_…`). Symbols use `EXCHANGE:SYMBOL` as the API's input format; internally the source URL is built as `https://www.google.com/finance/quote/SYMBOL:EXCHANGE`.

Every response is JSON with `success`, then either `data` + `meta` or `error.code` + `error.message`. Fields the source did not expose are `null` — never `0`. `meta.cached`, `meta.stale`, `meta.retrieved_at` and `meta.cache_age_seconds` describe freshness.

## 6. Admin panel

| Page | What it does |
|------|--------------|
| **Dashboard** | Key counts, requests today / this month / total, success rate, cache hit rate, 14-day CSS bar chart, most requested symbols, recent activity |
| **API Keys** | Create (name, per-minute/day/month limits, expiry), edit, enable/disable, rotate (new key + old revoked), revoke, live counters, link to logs |
| **API Usage** | Per-key totals, daily volume, endpoint breakdown, error-code breakdown for 7/30/90 days |
| **Request Logs** | Paginated log with filters: key, endpoint, status, date range, symbol |
| **API Settings** | All runtime settings + administrator password change |
| **Cache Settings** | Cache TTL, stale fallback, retention; purge one symbol or everything; run cleanup |
| **System Status** | Environment checks (PHP, extensions, DB, writable logs, HTTPS) and a live upstream probe |

The admin panel and the API are independent: disabling the API (or maintenance mode) never locks you out of the admin panel.

## 7. Caching and rate limiting

**Cache** — one row per symbol in `stock_cache`. A request checks for a fresh row (default TTL 60 s); on a miss it takes a short DB lock (`lock_until`) so concurrent requests for the same symbol wait up to 3 s for the first fetch instead of all hitting the source. Only successful extractions are written; a failed fetch releases the lock and leaves previous data untouched. Cached responses report the **original** `retrieved_at`. Optional stale fallback (off by default) can serve the last good snapshot, labelled `meta.stale = true` with `meta.upstream_error`, when the source is down.

**Rate limits** — fixed windows per key (minute / day / month, any may be unlimited) stored in `rate_limits` and incremented atomically with `INSERT … ON DUPLICATE KEY UPDATE`. Each symbol in a batch costs one unit. Rejected requests are refunded so they do not consume quota. Responses carry `X-RateLimit-*` headers; `429` includes `Retry-After`.

## 8. Cron / housekeeping

`cron/cleanup.php` deletes expired cache rows, stale rate-limit counters, old usage logs (per *log retention days*) and old login-attempt rows. Hourly is plenty.

cPanel → **Cron Jobs**:

```
# CLI (preferred). Adjust the PHP binary and path for your host.
0 * * * * /usr/local/bin/php /home/cpuser/public_html/eq-finance/cron/cleanup.php >/dev/null 2>&1

# or over HTTP, with cron.token set in config.php
0 * * * * curl -s "https://yourdomain.com/eq-finance/cron/cleanup.php?token=YOUR_TOKEN" >/dev/null 2>&1
```

Without a cron job the same cleanup can be triggered from **Admin → Cache Settings → Run cleanup now**; the application works fine without it, tables just grow until you run it.

## 9. Security notes

- **SSRF**: clients send `EXCHANGE:SYMBOL`, never URLs. The exchange must be on the allow-list and the symbol must match `^[A-Z0-9][A-Z0-9.\-_&]{0,23}$`. The only URL ever requested is `https://www.google.com/finance/quote/…` built from those validated parts. Redirects are not followed automatically; at most two same-host `/finance/quote/` redirects are honoured.
- **API keys**: `eqf_live_` + 48 hex characters from `random_bytes()`. Only the SHA-256 hash is stored; a 15-character prefix is kept for display. Keys never appear in logs.
- **Passwords**: `password_hash()` / `password_verify()` (bcrypt, auto-rehash). Minimum 12 characters.
- **Sessions**: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, ID regenerated at login and every 30 minutes, idle timeout.
- **CSRF**: per-session synchroniser token on every admin POST, plus Origin/Referer host check.
- **Login rate limiting**: 5 failures → 15-minute lockout per (hashed) IP.
- **Database**: PDO with native prepared statements everywhere; no string interpolation of user input.
- **Output**: every dynamic value in admin/docs templates is HTML-escaped.
- **Errors**: never displayed; written to `storage/logs/`. API clients get generic messages and stable error codes.
- **Headers**: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Cache-Control: no-store`.
- **Files**: directory listing off; `app/`, `config/`, `database/`, `storage/`, `tests/`, `cron/` (except `cleanup.php`) and `api/v1/handlers/` denied via `.htaccess`; `*.sql`, `*.log`, `*.md`, `*.example.php` denied globally.

## 10. Local development

```bash
cp config/config.example.php config/config.php     # edit db.*, set force_https => false
mysql -u root -p -e "CREATE DATABASE eq_finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p eq_finance < database/install.sql
php -S 127.0.0.1:8080 -t . tests/router.php          # dev router emulates .htaccess
open http://127.0.0.1:8080/admin/
```

## 11. Testing

```bash
php tests/run-tests.php
```

The suite (91 assertions) covers symbol validation/SSRF boundaries, the parser (against an HTML fixture), key lifecycle, rate limiting, upstream error mapping, cache hit/miss/stale/lock semantics and admin credential rules. It replaces the network transport with fixtures, so it runs without internet access — **but it truncates `api_keys`, `api_usage`, `stock_cache`, `rate_limits` and `login_attempts`; use a test database.** See [TESTING.md](TESTING.md) for the full test report, including the HTTP-level tests performed with curl.

## 12. Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| `/admin/` shows "EQ Finance is not configured yet" | No config file found. Check its location/name (see Step 5). |
| "Database connection failed" | Wrong credentials, user not added to the database, or wrong host (some hosts use `127.0.0.1` or a socket path). Details are in `storage/logs/php-errors.log`. |
| API returns `MISSING_API_KEY` even though you send the header | The host strips `Authorization` for PHP-CGI/FastCGI. The root `.htaccess` already forwards it (`SetEnvIf`/`RewriteRule … E=HTTP_AUTHORIZATION`); if the host disables that, send the key in the `X-API-Key` header instead. |
| `/api/v1/stock` is 404 | `mod_rewrite` is off or `.htaccess` was not uploaded. The endpoints also work as `/api/v1/stock.php`. |
| Private files are downloadable | `AllowOverride` is disabled. Ask the host to enable `.htaccess`, or move the project folder outside `public_html` and point a subdomain's document root at it (then only `admin/`, `api/`, `public/` and `cron/` need to be reachable). |
| `SOURCE_BLOCKED` on every request | Google is rate limiting or showing a consent/verification page to your server's IP. Increase the cache TTL, lower key limits, wait, or change the User-Agent in settings. EQ Finance will not bypass these protections. |
| `EXTRACTION_FAILED` on every request | Google changed the page layout. Run the upstream probe, save the HTML, and update the XPath strategies in `app/Services/StockParser.php`. |
| `SOURCE_UNAVAILABLE (DNS/connection)` | The host blocks outbound HTTPS. Ask the provider to allow connections to `www.google.com:443`. |
| Batch calls time out | Lower *Maximum batch size* or *Request timeout*; shared hosts often cap `max_execution_time` at 30 s. The API stops early and marks unprocessed symbols. |

## 13. Recovering admin access

There is deliberately no email-based password reset (shared-hosting mail is unreliable and a weak reset flow is worse than none). If you lose the password:

1. Create a temporary file `reset.php` **outside** `public_html` or run via cPanel's *Terminal* if available:
   ```php
   <?php echo password_hash('your-new-password-at-least-12-chars', PASSWORD_DEFAULT), PHP_EOL;
   ```
   Run it with `php reset.php` (or upload it temporarily to `public_html`, open it once in the browser, copy the hash, **then delete it**).
2. In phpMyAdmin, open the `admins` table and paste the hash into `password_hash` for your user.
3. Sign in and change the password from *Admin → API Settings*.

---

## License

MIT — see [LICENSE](LICENSE).
