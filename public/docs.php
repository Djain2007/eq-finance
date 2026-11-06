<?php
/** EQ Finance — public API documentation (served at /docs). */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Str;
use App\Helpers\Url;
use App\Services\SettingsService;
use App\Services\SymbolValidator;

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$configured = EQF_CONFIGURED;
$apiName = $configured ? SettingsService::get('api_name') : EQF_NAME;
$apiDesc = $configured ? SettingsService::get('api_description') : 'Lightweight Google Finance scraping API.';
$apiVersion = $configured ? SettingsService::get('api_version') : EQF_VERSION;
$ttl = $configured ? SettingsService::int('cache_ttl_seconds') : 60;
$batch = $configured ? SettingsService::int('max_batch_size') : 10;
$rpm = $configured ? SettingsService::int('default_requests_per_minute') : 30;
$rpd = $configured ? SettingsService::int('default_requests_per_day') : 1000;
$serveStale = $configured && SettingsService::bool('cache_serve_stale');
$exchanges = SymbolValidator::allowedExchanges();
$base = Url::base() . '/api/v1';
$e = static fn($v) => Str::e($v);

$errorCodes = [
    ['INVALID_REQUEST', 400, 'A required parameter is missing or malformed.'],
    ['INVALID_SYMBOL', 400, 'The symbol is not in EXCHANGE:SYMBOL form or contains invalid characters.'],
    ['UNSUPPORTED_EXCHANGE', 400, 'The exchange is not on the supported list.'],
    ['BATCH_LIMIT_EXCEEDED', 400, 'Too many symbols in one /stocks call.'],
    ['MISSING_API_KEY', 401, 'No Authorization header was sent.'],
    ['INVALID_API_KEY', 401, 'The key is unknown or malformed.'],
    ['KEY_DISABLED', 403, 'The key was disabled by the administrator.'],
    ['KEY_REVOKED', 403, 'The key was revoked (or rotated) and can no longer be used.'],
    ['KEY_EXPIRED', 403, 'The key passed its expiry date.'],
    ['SYMBOL_NOT_FOUND', 404, 'The data source has no quote for this symbol.'],
    ['NOT_FOUND', 404, 'Unknown endpoint.'],
    ['METHOD_NOT_ALLOWED', 405, 'Only GET is supported.'],
    ['RATE_LIMIT_EXCEEDED', 429, 'A per-key limit was hit. See Retry-After.'],
    ['INTERNAL_ERROR', 500, 'Unexpected server error (details are logged server-side).'],
    ['UNSUPPORTED_OPERATION', 501, 'The operation is not available (used by /search).'],
    ['SOURCE_UNAVAILABLE', 502, 'The data source could not be reached or returned an unexpected status.'],
    ['SOURCE_BLOCKED', 502, 'The data source refused automated access (consent, verification or rate limiting). Retry later.'],
    ['EXTRACTION_FAILED', 502, 'The page loaded but no quote could be extracted — the layout may have changed.'],
    ['API_DISABLED', 503, 'The administrator disabled the API.'],
    ['MAINTENANCE_MODE', 503, 'The API is in maintenance mode.'],
    ['SERVICE_UNAVAILABLE', 503, 'Database or configuration problem.'],
];
?><!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($apiName) ?> — API Documentation</title>
<meta name="description" content="<?= $e($apiDesc) ?>">
<link rel="stylesheet" href="<?= $e(Url::asset('css/admin.css')) ?>?v=<?= $e(EQF_VERSION) ?>">
<script>(function(){try{var t=localStorage.getItem('eqf-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
</head>
<body>
<div class="docs-top">
  <a class="brand-link" href="<?= $e(Url::rootPath() ?: '/') ?>"><span class="brand-mark" aria-hidden="true"></span><?= $e($apiName) ?> <span class="muted small">API v<?= $e($apiVersion) ?></span></a>
  <div class="topbar-actions">
    <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle colour theme" title="Toggle theme">
      <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
      <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    </button>
    <a class="btn btn-secondary btn-sm" href="<?= $e(Url::admin('')) ?>">Admin</a>
  </div>
</div>

<div class="docs-layout">
  <nav class="docs-nav">
    <div class="nav-group-title">Guide</div>
    <a href="#overview">Overview</a>
    <a href="#authentication">Authentication</a>
    <a href="#rate-limits">Rate limits</a>
    <a href="#caching">Caching &amp; freshness</a>
    <a href="#exchanges">Supported exchanges</a>
    <div class="nav-group-title">Endpoints</div>
    <a href="#stock">GET /stock</a>
    <a href="#stocks">GET /stocks</a>
    <a href="#search">GET /search</a>
    <a href="#status">GET /status</a>
    <div class="nav-group-title">Reference</div>
    <a href="#fields">Data fields</a>
    <a href="#errors">Error codes</a>
    <a href="#examples">Code examples</a>
    <a href="#limitations">Limitations</a>
  </nav>

  <main class="docs">
    <h1 id="overview"><?= $e($apiName) ?> API</h1>
    <p class="lead"><?= $e($apiDesc) ?></p>
    <p><?= $e($apiName) ?> is a self-hosted REST API that reads publicly visible quote pages from Google Finance, extracts the structured data it can find, and returns it as JSON. It is read-only, GET-only, and authenticated with API keys issued from the admin panel.</p>
    <table>
      <tr><th>Base URL</th><td><code><?= $e($base) ?></code></td></tr>
      <tr><th>Format</th><td>JSON (UTF-8). Every response has a top-level <code>success</code> boolean.</td></tr>
      <tr><th>Transport</th><td>HTTPS, GET only. CORS enabled for browser clients.</td></tr>
      <tr><th>Timestamps</th><td>ISO 8601, UTC (<code>2026-11-04T12:00:00Z</code>).</td></tr>
    </table>

    <h2 id="authentication">Authentication</h2>
    <p>Send your key as a Bearer token. Keys are created in the admin panel and look like <code>eqf_live_…</code>. They are shown only once at creation.</p>
<pre>Authorization: Bearer eqf_live_YOUR_API_KEY</pre>
    <p>If your HTTP client cannot set an <code>Authorization</code> header, the <code>X-API-Key</code> header is accepted as an alternative. Keys are never accepted in the URL. <code>/status</code> is the only endpoint that does not require a key.</p>

    <h2 id="rate-limits">Rate limits</h2>
    <p>Each key has its own limits per minute, per day and per month (any of which may be unlimited). New keys default to <strong><?= $e(number_format($rpm)) ?> / minute</strong> and <strong><?= $e(number_format($rpd)) ?> / day</strong>. Each symbol in a <code>/stocks</code> batch counts as one request. Rejected requests do not consume quota.</p>
    <p>Successful responses carry <code>X-RateLimit-Limit-Minute</code>, <code>X-RateLimit-Remaining-Minute</code> (and the same for <code>-Day</code> / <code>-Month</code> where a limit is set). A <code>429</code> response includes a <code>Retry-After</code> header in seconds.</p>

    <h2 id="caching">Caching &amp; data freshness</h2>
    <p>Quotes are cached server-side for <strong><?= $e($ttl) ?> seconds</strong>. Within that window every client receives the same snapshot; <code>meta.cached</code> is <code>true</code> and <code>meta.retrieved_at</code> is the time the snapshot was actually taken from the source, not the time of your request. <code>meta.cache_age_seconds</code> and <code>meta.cache_expires_at</code> tell you how old the snapshot is.</p>
    <?php if ($serveStale): ?>
      <p>If the source becomes unavailable, the API may return the last valid snapshot with <code>meta.stale = true</code> and the original timestamp, together with <code>meta.upstream_error</code> describing why a fresh fetch failed.</p>
    <?php else: ?>
      <p>If the source is unavailable and no fresh snapshot exists, the API returns a <code>502</code> error instead of stale data.</p>
    <?php endif; ?>
    <p>Data is delayed by whatever delay the source applies and is never guaranteed to be real-time. Do not use it for trading decisions.</p>

    <h2 id="exchanges">Supported exchanges</h2>
    <p>Symbols are written as <code>EXCHANGE:SYMBOL</code>, upper-case. Only the exchanges below are accepted; anything else returns <code>UNSUPPORTED_EXCHANGE</code>. The list is maintained by the administrator in the server configuration.</p>
    <p><?php foreach ($exchanges as $ex): ?><span class="pill"><?= $e($ex) ?></span><?php endforeach; ?></p>
    <p>Examples: <code>NSE:RELIANCE</code>, <code>BOM:500325</code>, <code>NASDAQ:AAPL</code>, <code>NYSE:IBM</code>, <code>LON:VOD</code>, <code>INDEXNSE:NIFTY_50</code>, <code>CURRENCY:USDINR</code>.</p>

    <h2 id="stock"><span class="method">GET</span>/stock</h2>
    <p>Returns the available data for one symbol.</p>
    <table>
      <tr><th>Parameter</th><th>Required</th><th>Description</th></tr>
      <tr><td><code>symbol</code></td><td>yes</td><td><code>EXCHANGE:SYMBOL</code>, e.g. <code>NSE:RELIANCE</code></td></tr>
    </table>
<pre>GET <?= $e($base) ?>/stock?symbol=NSE:RELIANCE
Authorization: Bearer eqf_live_YOUR_API_KEY</pre>
    <p>Example response (illustrative values, not market data):</p>
<pre>{
  "success": true,
  "data": {
    "symbol": "NSE:RELIANCE",
    "name": "Reliance Industries Ltd",
    "exchange": "NSE",
    "currency": "INR",
    "price": 1250.5,
    "previous_close": 1240.0,
    "change": 10.5,
    "change_percent": 0.85,
    "day_high": 1260.0,
    "day_low": 1235.0,
    "fifty_two_week_high": 1608.95,
    "fifty_two_week_low": 1115.55,
    "market_cap": 16920000000000.0,
    "pe_ratio": 24.56,
    "eps": 50.12,
    "volume": null,
    "average_volume": 12340000.0,
    "dividend_yield": 0.4,
    "description": "Reliance Industries Limited is an Indian multinational conglomerate…",
    "sector": null,
    "industry": null,
    "website": "https://www.ril.com/",
    "source_timestamp": "2026-11-04T09:40:00Z",
    "availability": { "price": true, "volume": false, "...": true }
  },
  "meta": {
    "source": "Google Finance",
    "source_url": "https://www.google.com/finance/quote/RELIANCE:NSE",
    "retrieved_at": "2026-11-04T12:00:00Z",
    "cached": false,
    "stale": false,
    "cache_age_seconds": 0,
    "cache_expires_at": "2026-11-04T12:01:00Z"
  }
}</pre>
    <p>A field is <code>null</code> when the source did not expose it. <code>null</code> never means zero. <code>data.availability</code> lists which fields were actually extracted.</p>

    <h2 id="stocks"><span class="method">GET</span>/stocks</h2>
    <p>Returns data for several symbols in one call. Maximum <strong><?= $e($batch) ?></strong> symbols per request. Each symbol is reported independently, so one failure does not fail the whole call; the HTTP status is <code>200</code> whenever the request itself was valid.</p>
    <table>
      <tr><th>Parameter</th><th>Required</th><th>Description</th></tr>
      <tr><td><code>symbols</code></td><td>yes</td><td>Comma-separated list, e.g. <code>NSE:RELIANCE,NSE:TCS</code></td></tr>
    </table>
<pre>GET <?= $e($base) ?>/stocks?symbols=NSE:RELIANCE,NASDAQ:AAPL</pre>
<pre>{
  "success": true,
  "data": {
    "NSE:RELIANCE": { "success": true, "data": { ... }, "meta": { ... } },
    "NASDAQ:AAPL":  { "success": false, "error": { "code": "SOURCE_BLOCKED", "message": "..." } }
  },
  "meta": { "source": "Google Finance", "requested": 2, "succeeded": 1, "failed": 1, "retrieved_at": "2026-11-04T12:00:03Z" }
}</pre>
    <p>Symbols are fetched sequentially (shared hosting has no background workers). If the server's execution-time budget runs out, remaining symbols are returned with <code>SERVICE_UNAVAILABLE</code> and should be retried separately.</p>

    <h2 id="search"><span class="method">GET</span>/search</h2>
    <p>Google Finance provides no permitted public search method, so this endpoint deliberately returns <code>501 UNSUPPORTED_OPERATION</code> rather than fabricated results. The response's <code>error.details.supported_exchanges</code> lists valid exchange prefixes. Request quotes directly with <code>EXCHANGE:SYMBOL</code>.</p>

    <h2 id="status"><span class="method">GET</span>/status</h2>
    <p>Public health check. No API key required.</p>
<pre>{
  "success": true,
  "data": {
    "status": "ok",
    "name": "<?= $e($apiName) ?>",
    "api_version": "<?= $e($apiVersion) ?>",
    "service_available": true,
    "database": "connected",
    "timestamp": "2026-11-04T12:00:00Z"
  },
  "meta": { "source": "EQ Finance" }
}</pre>
    <p><code>status</code> is one of <code>ok</code>, <code>maintenance</code>, <code>disabled</code> or <code>degraded</code> (database unreachable, HTTP 503).</p>

    <h2 id="fields">Data fields</h2>
    <table>
      <tr><th>Field</th><th>Type</th><th>Notes</th></tr>
      <tr><td><code>symbol</code>, <code>exchange</code></td><td>string</td><td>As requested, normalised to upper-case.</td></tr>
      <tr><td><code>name</code></td><td>string|null</td><td>Company or instrument name.</td></tr>
      <tr><td><code>currency</code></td><td>string|null</td><td>ISO 4217 code when exposed by the source.</td></tr>
      <tr><td><code>price</code></td><td>number</td><td>Last price. Always present on success.</td></tr>
      <tr><td><code>previous_close</code>, <code>change</code>, <code>change_percent</code></td><td>number|null</td><td>Change values are derived from price and previous close when the page does not state them.</td></tr>
      <tr><td><code>day_high</code>, <code>day_low</code></td><td>number|null</td><td>From the "Day range" statistic.</td></tr>
      <tr><td><code>fifty_two_week_high</code>, <code>fifty_two_week_low</code></td><td>number|null</td><td>From the "Year range" statistic.</td></tr>
      <tr><td><code>market_cap</code>, <code>volume</code>, <code>average_volume</code></td><td>number|null</td><td>Abbreviations (K, M, B, T) are expanded to full numbers.</td></tr>
      <tr><td><code>pe_ratio</code>, <code>eps</code>, <code>dividend_yield</code></td><td>number|null</td><td><code>dividend_yield</code> is a percentage.</td></tr>
      <tr><td><code>description</code>, <code>sector</code>, <code>industry</code>, <code>website</code></td><td>string|null</td><td>Sector and industry are rarely exposed by the source.</td></tr>
      <tr><td><code>source_timestamp</code></td><td>string|null</td><td>Time of the last trade as reported by the source, when available.</td></tr>
      <tr><td><code>availability</code></td><td>object</td><td>Map of field name → boolean for every field above.</td></tr>
    </table>

    <h2 id="errors">Error codes</h2>
    <p>Errors always have this shape:</p>
<pre>{ "success": false, "error": { "code": "RATE_LIMIT_EXCEEDED", "message": "Rate limit of 30 requests per minute exceeded.", "details": { ... } } }</pre>
    <table>
      <tr><th>Code</th><th>HTTP</th><th>Meaning</th></tr>
      <?php foreach ($errorCodes as [$code, $http, $meaning]): ?>
        <tr><td><code><?= $e($code) ?></code></td><td><?= (int) $http ?></td><td><?= $e($meaning) ?></td></tr>
      <?php endforeach; ?>
    </table>

    <h2 id="examples">Code examples</h2>
    <h3>cURL</h3>
<pre>curl -H "Authorization: Bearer eqf_live_YOUR_API_KEY" \
  "<?= $e($base) ?>/stock?symbol=NSE:RELIANCE"</pre>
    <h3>PHP (cURL)</h3>
<pre>&lt;?php
$apiKey = 'eqf_live_YOUR_API_KEY';
$url = '<?= $e($base) ?>/stock?symbol=' . rawurlencode('NSE:RELIANCE');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER     =&gt; ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
    CURLOPT_TIMEOUT        =&gt; 20,
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$json = json_decode($body, true);
if ($status === 200 &amp;&amp; $json['success']) {
    printf("%s: %s %s (%s%%)\n", $json['data']['name'], $json['data']['currency'], $json['data']['price'], $json['data']['change_percent']);
} else {
    printf("Error %s: %s\n", $json['error']['code'] ?? $status, $json['error']['message'] ?? 'unknown');
}</pre>
    <h3>JavaScript (fetch)</h3>
<pre>const API_KEY = 'eqf_live_YOUR_API_KEY';

async function getQuote(symbol) {
  const res = await fetch(`<?= $e($base) ?>/stock?symbol=${encodeURIComponent(symbol)}`, {
    headers: { Authorization: `Bearer ${API_KEY}`, Accept: 'application/json' },
  });
  const json = await res.json();
  if (!res.ok || !json.success) {
    throw new Error(`${json.error?.code ?? res.status}: ${json.error?.message ?? 'Request failed'}`);
  }
  return json.data;
}

getQuote('NASDAQ:AAPL').then(q =&gt; console.log(q.name, q.price, q.currency)).catch(console.error);</pre>
    <h3>Batch</h3>
<pre>curl -H "Authorization: Bearer eqf_live_YOUR_API_KEY" \
  "<?= $e($base) ?>/stocks?symbols=NSE:RELIANCE,NSE:TCS,NSE:INFY"</pre>

    <h2 id="limitations">Limitations</h2>
    <ul>
      <li>Google Finance is an unofficial source without a public API. Its HTML can change at any time; when that happens the API returns <code>EXTRACTION_FAILED</code> rather than guessing, and the parser must be updated.</li>
      <li>The source may block or throttle automated requests. The API reports <code>SOURCE_BLOCKED</code> and does not attempt to bypass CAPTCHAs, consent pages or any other protection.</li>
      <li>Prices are delayed according to the source and may lag the live market by many minutes.</li>
      <li>Not every field is available for every instrument (indices and currencies expose only a few).</li>
      <li>There is no symbol search; you must know the exchange and ticker.</li>
      <li>Requests are processed sequentially; large batches take proportionally longer.</li>
    </ul>
    <p class="muted small">Powered by EQ Finance v<?= $e(EQF_VERSION) ?>. Data is provided as-is for informational purposes only.</p>
  </main>
</div>
<script src="<?= $e(Url::asset('js/admin.js')) ?>?v=<?= $e(EQF_VERSION) ?>"></script>
</body>
</html>
