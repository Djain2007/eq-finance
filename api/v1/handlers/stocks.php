<?php
/** GET /api/v1/stocks?symbols=NSE:RELIANCE,NSE:TCS */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Response;
use App\Middleware\ApiAuthMiddleware;
use App\Services\SettingsService;
use App\Services\StockService;
use App\Services\UsageLogger;

$raw = isset($_GET['symbols']) && is_string($_GET['symbols']) ? $_GET['symbols'] : '';
$maxBatch = max(1, SettingsService::int('max_batch_size'));

// Parse first (without touching the DB) so an oversized batch is rejected cheaply.
$symbols = [];
foreach (explode(',', strtoupper($raw)) as $s) {
    $s = trim($s);
    if ($s !== '' && !in_array($s, $symbols, true)) {
        $symbols[] = $s;
    }
}
$symbolForLog = $symbols === [] ? null : mb_substr(implode(',', $symbols), 0, 255);

if ($symbols === []) {
    $key = ApiAuthMiddleware::handle('stocks', $startedAt, 1, null);
    UsageLogger::record((int) $key['id'], 'stocks', null, 400, $startedAt, 'none', 'INVALID_REQUEST');
    Response::error('INVALID_REQUEST', 'The "symbols" query parameter is required (comma-separated, e.g. NSE:RELIANCE,NSE:TCS).', 400, [], ApiAuthMiddleware::rateHeaders($key, $key['rate']));
}
if (count($symbols) > $maxBatch) {
    $key = ApiAuthMiddleware::handle('stocks', $startedAt, 1, $symbolForLog);
    UsageLogger::record((int) $key['id'], 'stocks', $symbolForLog, 400, $startedAt, 'none', 'BATCH_LIMIT_EXCEEDED');
    Response::error('BATCH_LIMIT_EXCEEDED', 'A maximum of ' . $maxBatch . ' symbols can be requested per call.', 400, ['max_batch_size' => $maxBatch], ApiAuthMiddleware::rateHeaders($key, $key['rate']));
}

// Each symbol in a batch counts as one request against the key's limits.
$key = ApiAuthMiddleware::handle('stocks', $startedAt, count($symbols), $symbolForLog);
$rateHeaders = ApiAuthMiddleware::rateHeaders($key, $key['rate']);

// Stay inside the PHP execution-time limit on shared hosting.
$maxExec = (int) ini_get('max_execution_time');
$budget = $maxExec > 0 ? max(5.0, $maxExec - 5.0) : 60.0;
$perRequest = SettingsService::int('request_timeout_seconds') + 2;

$results = [];
$succeeded = 0;
$failed = 0;
$cacheStatuses = [];
foreach ($symbols as $i => $symbol) {
    $elapsed = microtime(true) - $startedAt;
    if ($elapsed + $perRequest > $budget && $i > 0) {
        $results[$symbol] = ['success' => false, 'error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Skipped: request time budget exhausted. Retry this symbol separately.']];
        $failed++;
        continue;
    }
    $r = StockService::quote($symbol);
    $cacheStatuses[] = $r['cache_status'];
    if ($r['success']) {
        $results[$symbol] = ['success' => true, 'data' => $r['data'], 'meta' => $r['meta']];
        $succeeded++;
    } else {
        $results[$symbol] = ['success' => false, 'error' => $r['error']] + (isset($r['meta']) ? ['meta' => $r['meta']] : []);
        $failed++;
    }
}

$aggregateCache = in_array('miss', $cacheStatuses, true) ? 'miss' : (in_array('stale', $cacheStatuses, true) ? 'stale' : (in_array('hit', $cacheStatuses, true) ? 'hit' : 'none'));
UsageLogger::record((int) $key['id'], 'stocks', $symbolForLog, 200, $startedAt, $aggregateCache, $failed > 0 && $succeeded === 0 ? 'ALL_FAILED' : null);

Response::success($results, [
    'source'       => 'Google Finance',
    'requested'    => count($symbols),
    'succeeded'    => $succeeded,
    'failed'       => $failed,
    'retrieved_at' => gmdate('Y-m-d\TH:i:s\Z'),
], 200, $rateHeaders);
