<?php
/**
 * EQ Finance — automated test-suite (CLI).
 *
 *   php tests/run-tests.php
 *
 * Requires a configured database (config/config.php) with install.sql applied.
 * Uses a TEST DATABASE — it truncates api_keys, api_usage, stock_cache, rate_limits,
 * login_attempts. Never run it against production data.
 *
 * Network access is not required: the upstream transport is replaced with
 * fixtures so cache, lock, error and parser behaviour can be verified offline.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\AdminAuth;
use App\Database\Database;
use App\Services\ApiKeyService;
use App\Services\CacheService;
use App\Services\GoogleFinanceScraper;
use App\Services\RateLimitService;
use App\Services\SettingsService;
use App\Services\StockParser;
use App\Services\StockService;
use App\Services\SymbolValidator;

$pass = 0; $fail = 0; $failures = [];
function ok(bool $cond, string $name): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; $failures[] = $name; echo "  ✗ $name\n"; }
}
function section(string $s): void { echo "\n[$s]\n"; }

if (!EQF_CONFIGURED) { exit("No configuration found.\n"); }
$pdo = Database::connection();
foreach (['api_usage', 'rate_limits', 'stock_cache', 'login_attempts'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec('DELETE FROM api_keys');
$fixture = file_get_contents(__DIR__ . '/fixtures/quote-reliance.html');

/* ------------------------------------------------------------------ */
section('Symbol validation / SSRF boundary');
$cases = [
    'NSE:RELIANCE' => true, 'nse:reliance' => true, 'NSE:M&M' => true, 'NASDAQ:BRK.B' => true, 'BOM:500325' => true,
    'FAKE:ABC' => false, 'NSE:../../x' => false, 'NSE:A B' => false, 'localhost:80' => false, '127.0.0.1:80' => false,
    'NSE' => false, 'NSE:A:B' => false, 'http://evil' => false, '' => false, 'NSE:' => false, ':RELIANCE' => false,
    'NSE:' . str_repeat('A', 30) => false, "NSE:REL\x00" => false,
];
foreach ($cases as $input => $expected) {
    $r = SymbolValidator::validate($input);
    ok($r['ok'] === $expected, "validate(" . json_encode($input) . ") => " . ($expected ? 'accepted' : 'rejected'));
}
ok(SymbolValidator::validate(['x'])['ok'] === false, 'non-string symbol rejected');
ok(SymbolValidator::sourceUrl('NSE', 'M&M') === 'https://www.google.com/finance/quote/M%26M:NSE', 'source URL is built from validated parts only, encoded');

/* ------------------------------------------------------------------ */
section('Parser');
$p = new StockParser();
$r = $p->parse($fixture, 'NSE', 'RELIANCE');
ok($r['ok'] === true, 'fixture parses');
$d = $r['data'] ?? [];
ok(($d['price'] ?? null) === 1250.5, 'price');
ok(($d['currency'] ?? null) === 'INR', 'currency from data attribute');
ok(($d['name'] ?? null) === 'Reliance Industries Ltd', 'name');
ok(($d['previous_close'] ?? null) === 1240.0, 'previous close');
ok(($d['change'] ?? null) === 10.5 && ($d['change_percent'] ?? null) === 0.85, 'change and percent');
ok(($d['day_low'] ?? null) === 1235.0 && ($d['day_high'] ?? null) === 1260.0, 'day range');
ok(($d['fifty_two_week_low'] ?? null) === 1115.55 && ($d['fifty_two_week_high'] ?? null) === 1608.95, '52-week range');
ok(($d['market_cap'] ?? null) === 16920000000000.0, 'market cap abbreviation expanded');
ok(($d['average_volume'] ?? null) === 12340000.0, 'average volume');
ok(($d['pe_ratio'] ?? null) === 24.56 && ($d['eps'] ?? null) === 50.12, 'P/E and EPS');
ok(($d['website'] ?? null) === 'https://www.ril.com/', 'website');
ok(array_key_exists('volume', $d) && $d['volume'] === null && ($d['availability']['volume'] ?? true) === false, 'missing field is null, not zero');
ok(($d['source_timestamp'] ?? null) === '2025-11-04T09:40:00Z', 'source timestamp');
$down = str_replace(['Up by 0.85%', '+10.50', 'data-last-price="1250.5"', '₹1,250.50'], ['Down by 0.85%', '-10.50', 'data-last-price="1229.5"', '₹1,229.50'], $fixture);
$rd = $p->parse($down, 'NSE', 'RELIANCE')['data'];
ok($rd['change'] === -10.5 && $rd['change_percent'] === -0.85, 'negative move keeps sign');
$noAttr = preg_replace('/<div class="kaZsje"[^>]*><\/div>/', '', $fixture);
$ra = $p->parse($noAttr, 'NSE', 'RELIANCE')['data'];
ok($ra['price'] === 1250.5 && $ra['currency'] === 'INR', 'fallback: price from header text, currency from symbol');
$noName = str_replace('<div class="zzDege">Reliance Industries Ltd</div>', '', $fixture);
ok($p->parse($noName, 'NSE', 'RELIANCE')['data']['name'] === 'Reliance Industries Ltd', 'fallback: name from <title>');
ok($p->parse('<html><body>We couldn\'t find any match for your search.</body></html>', 'NSE', 'XYZ')['code'] === 'SYMBOL_NOT_FOUND', 'not-found page => SYMBOL_NOT_FOUND');
ok($p->parse('<html><body><p>Hello</p></body></html>', 'NSE', 'XYZ')['code'] === 'EXTRACTION_FAILED', 'unrelated page => EXTRACTION_FAILED');
ok($p->parse('', 'NSE', 'XYZ')['ok'] === false, 'empty page fails');
ok(StockParser::parseAbbreviated('1.5B') === 1500000000.0 && StockParser::parseAbbreviated('12,345') === 12345.0, 'abbreviation parsing');
ok(StockParser::toFloat('—') === null && StockParser::toFloat('-') === null, 'dash => null');
ok(StockParser::parseRange('$10.00 - $12.50') === [10.0, 12.5], 'range parsing');

/* ------------------------------------------------------------------ */
section('API keys');
$k = ApiKeyService::create(['name' => 'Test', 'requests_per_minute' => 3, 'requests_per_day' => 0]);
ok(preg_match(ApiKeyService::KEY_PATTERN, $k['key']) === 1, 'key format eqf_live_ + 48 hex');
$row = ApiKeyService::find($k['id']);
ok($row['key_hash'] === hash('sha256', $k['key']) && !str_contains(json_encode($row), substr($k['key'], 10, 20)), 'only SHA-256 hash stored');
ok($row['requests_per_day'] === null, '0 limit stored as unlimited (NULL)');
ok(ApiKeyService::authenticate($k['key'])['ok'] === true, 'authenticate valid key');
ok(ApiKeyService::authenticate(null)['code'] === 'MISSING_API_KEY', 'missing key');
ok(ApiKeyService::authenticate('eqf_live_' . str_repeat('0', 48))['code'] === 'INVALID_API_KEY', 'unknown key');
ok(ApiKeyService::authenticate('not-a-key')['code'] === 'INVALID_API_KEY', 'malformed key');
ApiKeyService::setStatus($k['id'], 'disabled');
ok(ApiKeyService::authenticate($k['key'])['code'] === 'KEY_DISABLED', 'disabled key rejected');
ApiKeyService::setStatus($k['id'], 'active');
$exp = ApiKeyService::create(['name' => 'Expired', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
ok(ApiKeyService::authenticate($exp['key'])['code'] === 'KEY_EXPIRED', 'expired key rejected');
$rot = ApiKeyService::rotate($k['id']);
ok($rot !== null && ApiKeyService::authenticate($k['key'])['code'] === 'KEY_REVOKED', 'rotation revokes old key');
ok(ApiKeyService::authenticate($rot['key'])['ok'] === true && ApiKeyService::find($rot['id'])['requests_per_minute'] === 3, 'rotation keeps limits on new key');
ok(ApiKeyService::find($k['id'])['replaced_by_id'] === $rot['id'], 'old key links to replacement');
ApiKeyService::revoke($rot['id']);
ok(ApiKeyService::authenticate($rot['key'])['code'] === 'KEY_REVOKED', 'revoke is immediate');
ok(ApiKeyService::setStatus($rot['id'], 'active') === false, 'revoked key cannot be re-enabled');

/* ------------------------------------------------------------------ */
section('Rate limiting');
$rk = ApiKeyService::create(['name' => 'RL', 'requests_per_minute' => 2, 'requests_per_day' => 100, 'monthly_limit' => 0]);
$rkRow = ApiKeyService::find($rk['id']);
$a = RateLimitService::hit($rkRow); $b = RateLimitService::hit($rkRow); $c = RateLimitService::hit($rkRow);
ok($a['ok'] && $b['ok'] && !$c['ok'], 'third request within a minute is rejected');
ok($c['window'] === 'minute' && $c['retry_after'] >= 1 && $c['retry_after'] <= 60, '429 metadata');
ok(RateLimitService::usage($rk['id'])['minute'] === 2, 'rejected request refunded (counter stays at limit)');
ok($b['remaining']['month'] === null, 'unlimited window reports null');
$bk = ApiKeyService::create(['name' => 'Batch', 'requests_per_minute' => 5]);
$bres = RateLimitService::hit(ApiKeyService::find($bk['id']), 5);
ok($bres['ok'] && $bres['remaining']['minute'] === 0, 'batch cost consumes multiple units');
ok(!RateLimitService::hit(ApiKeyService::find($bk['id']), 1)['ok'], 'then the next request is rejected');

/* ------------------------------------------------------------------ */
section('Scraper transport & error mapping');
$calls = 0;
GoogleFinanceScraper::setTransport(function (string $url) use (&$calls, &$fixture) {
    $calls++;
    if (str_contains($url, 'TIMEOUT:')) return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'message' => 'Unable to reach the data source (timeout).'];
    if (str_contains($url, 'BLOCKED:')) return ['ok' => true, 'body' => '<html><head><title>Sorry</title></head><body>Our systems have detected unusual traffic</body></html>', 'status' => 200, 'final_url' => $url];
    if (str_contains($url, 'SORRY:')) return ['ok' => true, 'body' => '<html></html>', 'status' => 200, 'final_url' => 'https://www.google.com/sorry/index?continue=x'];
    if (str_contains($url, 'NOPE:')) return ['ok' => true, 'body' => "<html><body>We couldn't find any match for your search.</body></html>", 'status' => 200, 'final_url' => $url];
    if (str_contains($url, 'CHANGED:')) return ['ok' => true, 'body' => '<html><body><div class="new-layout">1,250</div></body></html>', 'status' => 200, 'final_url' => $url];
    return ['ok' => true, 'body' => $fixture, 'status' => 200, 'final_url' => $url];
});
$s = new GoogleFinanceScraper();
ok($s->fetchQuote('NSE', 'RELIANCE')['ok'] === true, 'successful fetch');
ok($s->fetchQuote('NSE', 'TIMEOUT')['code'] === 'SOURCE_UNAVAILABLE', 'timeout => SOURCE_UNAVAILABLE');
ok($s->fetchQuote('NSE', 'BLOCKED')['code'] === 'SOURCE_BLOCKED', 'unusual-traffic page => SOURCE_BLOCKED');
ok($s->fetchQuote('NSE', 'SORRY')['code'] === 'SOURCE_BLOCKED', '/sorry/ redirect => SOURCE_BLOCKED');
ok($s->fetchQuote('NSE', 'NOPE')['code'] === 'SYMBOL_NOT_FOUND', 'not found => SYMBOL_NOT_FOUND');
ok($s->fetchQuote('NSE', 'CHANGED')['code'] === 'EXTRACTION_FAILED', 'changed layout => EXTRACTION_FAILED (no fabricated data)');

/* ------------------------------------------------------------------ */
section('Cache + StockService');
SettingsService::update(['cache_ttl_seconds' => '60', 'cache_serve_stale' => '0', 'cache_max_stale_seconds' => '900']);
$calls = 0;
$q1 = StockService::quote('NSE:RELIANCE');
ok($q1['success'] && $q1['meta']['cached'] === false && $q1['cache_status'] === 'miss', 'first request is a cache miss');
$q2 = StockService::quote('nse:reliance');
ok($q2['success'] && $q2['meta']['cached'] === true && $q2['cache_status'] === 'hit' && $calls === 1, 'second request served from cache (one upstream call)');
ok($q2['meta']['retrieved_at'] === $q1['meta']['retrieved_at'], 'cached response keeps original retrieval timestamp');
ok($q2['meta']['cache_age_seconds'] >= 0 && isset($q2['meta']['cache_expires_at']), 'cache metadata present');

// Failure must not overwrite cache; make the entry expired, then fail upstream.
$pdo->exec("UPDATE stock_cache SET expires_at = '2000-01-01 00:00:00' WHERE symbol = 'NSE:RELIANCE'");
$hold = $fixture; $fixture = ''; // make transport return empty body => extraction failure
$q3 = StockService::quote('NSE:RELIANCE');
ok(!$q3['success'] && in_array($q3['error']['code'], ['EXTRACTION_FAILED', 'SOURCE_UNAVAILABLE'], true), 'upstream failure returns error (stale serving off)');
$cached = $pdo->query("SELECT response_json, lock_until FROM stock_cache WHERE symbol = 'NSE:RELIANCE'")->fetch();
ok($cached['response_json'] !== null && str_contains($cached['response_json'], '1250.5'), 'failure did not overwrite previously valid data');
ok($cached['lock_until'] === null, 'lock released after failure');

SettingsService::update(['cache_serve_stale' => '1', 'cache_max_stale_seconds' => '999999999']);
$pdo->exec("UPDATE stock_cache SET fetched_at = UTC_TIMESTAMP() - INTERVAL 5 MINUTE, expires_at = '2000-01-01 00:00:00' WHERE symbol = 'NSE:RELIANCE'");
$q4 = StockService::quote('NSE:RELIANCE');
ok($q4['success'] && $q4['meta']['stale'] === true && $q4['meta']['cached'] === true && isset($q4['meta']['upstream_error']), 'stale fallback clearly labelled with upstream error');
ok($q4['meta']['cache_age_seconds'] >= 290, 'stale age reported from original fetch time');
SettingsService::update(['cache_max_stale_seconds' => '60']);
$q5 = StockService::quote('NSE:RELIANCE');
ok(!$q5['success'], 'stale data older than max age is not served');
$fixture = $hold;
SettingsService::update(['cache_serve_stale' => '0']);

$q6 = StockService::quote('NSE:NOPE');
ok(!$q6['success'] && $q6['status'] === 404 && $q6['error']['code'] === 'SYMBOL_NOT_FOUND', 'unknown symbol => 404');
ok((int) $pdo->query("SELECT COUNT(*) FROM stock_cache WHERE symbol = 'NSE:NOPE' AND response_json IS NOT NULL")->fetchColumn() === 0, 'failures are never cached as data');

// Lock behaviour
CacheService::purge();
ok(CacheService::acquireLock('NSE:TCS') === true, 'lock acquired');
ok(CacheService::acquireLock('NSE:TCS') === false, 'second acquire fails while locked');
CacheService::releaseLock('NSE:TCS');
ok(CacheService::acquireLock('NSE:TCS') === true, 'lock re-acquired after release');
$pdo->exec("UPDATE stock_cache SET lock_until = '2000-01-01 00:00:00' WHERE symbol = 'NSE:TCS'");
ok(CacheService::acquireLock('NSE:TCS') === true, 'expired lock can be taken over');

// TTL 0 disables caching
SettingsService::update(['cache_ttl_seconds' => '0']);
CacheService::purge(); $calls = 0;
StockService::quote('NSE:RELIANCE'); StockService::quote('NSE:RELIANCE');
ok($calls === 2, 'TTL 0 => every request hits upstream');
SettingsService::update(['cache_ttl_seconds' => '60']);
ok(CacheService::cleanup(0) >= 0, 'cleanup runs');
GoogleFinanceScraper::setTransport(null);

/* ------------------------------------------------------------------ */
section('Admin auth');
ok(AdminAuth::validateCredentials('ab', 'bad', 'short', 'short') !== [], 'weak credentials rejected');
ok(AdminAuth::validateCredentials('admin', 'a@b.co', 'longenoughpassword', 'longenoughpassword') === [], 'valid credentials accepted');
$existing = $pdo->query('SELECT username FROM admins LIMIT 1')->fetchColumn();
if ($existing) {
    ok(AdminAuth::attempt((string) $existing, 'definitely-wrong-password') === null, 'wrong password rejected');
    $hash = $pdo->query('SELECT password_hash FROM admins LIMIT 1')->fetchColumn();
    ok(str_starts_with((string) $hash, '$2y$') || str_starts_with((string) $hash, '$argon'), 'password stored as hash');
}

/* ------------------------------------------------------------------ */
echo "\n=================================\n";
echo "Passed: $pass   Failed: $fail\n";
if ($failures) { echo "Failures:\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail === 0 ? 0 : 1);
