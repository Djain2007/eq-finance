<?php
/**
 * EQ Finance — API v1 front controller.
 *
 * Routes:  /api/v1/stock   /api/v1/stocks   /api/v1/search   /api/v1/status
 * Requests reach this file via .htaccess rewrites (?_route=...) or through the
 * thin per-endpoint files (stock.php, stocks.php, ...), which set $eqfRoute.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Response;
use App\Helpers\Logger;

$startedAt = microtime(true);
$route = $eqfRoute ?? (isset($_GET['_route']) && is_string($_GET['_route']) ? $_GET['_route'] : '');
$route = strtolower(preg_replace('/[^a-z]/i', '', $route) ?? '');

// Never run without configuration or over plain HTTP when HTTPS is enforced.
if (!EQF_CONFIGURED) {
    Response::error('SERVICE_UNAVAILABLE', 'The service is not configured yet.');
}
if (\App\Config\Config::get('app.force_https') && !\App\Helpers\Url::isHttps()) {
    Response::error('INVALID_REQUEST', 'This API is only available over HTTPS.', 400);
}

// CORS: GET-only read API; allow browser clients.
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::error('METHOD_NOT_ALLOWED', 'Only GET requests are supported.');
}

$handlers = [
    'stock'  => __DIR__ . '/handlers/stock.php',
    'stocks' => __DIR__ . '/handlers/stocks.php',
    'search' => __DIR__ . '/handlers/search.php',
    'status' => __DIR__ . '/handlers/status.php',
];

try {
    if (!isset($handlers[$route])) {
        Response::error('NOT_FOUND', 'Unknown endpoint. See /docs for the list of available endpoints.');
    }
    require $handlers[$route];
} catch (\App\Database\DatabaseException $e) {
    Response::error('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.');
} catch (\Throwable $e) {
    Logger::exception($e, 'api/' . $route);
    Response::error('INTERNAL_ERROR', 'An internal error occurred.');
}
