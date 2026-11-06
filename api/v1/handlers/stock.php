<?php
/** GET /api/v1/stock?symbol=NSE:RELIANCE */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Response;
use App\Middleware\ApiAuthMiddleware;
use App\Services\StockService;
use App\Services\UsageLogger;

$symbolInput = isset($_GET['symbol']) && is_string($_GET['symbol']) ? strtoupper(trim($_GET['symbol'])) : '';
$symbolForLog = $symbolInput === '' ? null : mb_substr($symbolInput, 0, 64);

$key = ApiAuthMiddleware::handle('stock', $startedAt, 1, $symbolForLog);
$rateHeaders = ApiAuthMiddleware::rateHeaders($key, $key['rate']);

if ($symbolInput === '') {
    UsageLogger::record((int) $key['id'], 'stock', null, 400, $startedAt, 'none', 'INVALID_REQUEST');
    Response::error('INVALID_REQUEST', 'The "symbol" query parameter is required (for example NSE:RELIANCE).', 400, [], $rateHeaders);
}

$result = StockService::quote($symbolInput);
UsageLogger::record(
    (int) $key['id'],
    'stock',
    $symbolForLog,
    $result['status'],
    $startedAt,
    $result['cache_status'],
    $result['success'] ? null : $result['error']['code']
);

if ($result['success']) {
    Response::success($result['data'], $result['meta'], 200, $rateHeaders);
}
$body = ['success' => false, 'error' => $result['error']];
if (isset($result['meta'])) {
    $body['meta'] = $result['meta'];
}
Response::send($body, $result['status'], $rateHeaders);
