<?php
/**
 * GET /api/v1/search?q=Reliance
 *
 * Google Finance does not offer a permitted, stable, public search endpoint.
 * Its autocomplete is an internal, undocumented RPC that is not intended for
 * third-party use and changes without notice. Rather than inventing results
 * or scraping an internal endpoint, EQ Finance reports the operation as
 * unsupported. The response still goes through authentication so usage is
 * accounted for.
 */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Response;
use App\Middleware\ApiAuthMiddleware;
use App\Services\SymbolValidator;
use App\Services\UsageLogger;

$q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 64) : null;
$key = ApiAuthMiddleware::handle('search', $startedAt, 1, $q);
UsageLogger::record((int) $key['id'], 'search', $q, 501, $startedAt, 'none', 'UNSUPPORTED_OPERATION');

Response::error(
    'UNSUPPORTED_OPERATION',
    'Symbol search is not supported: the data source provides no permitted public search method. Request quotes directly using EXCHANGE:SYMBOL (for example NSE:RELIANCE).',
    501,
    ['supported_exchanges' => SymbolValidator::allowedExchanges()],
    ApiAuthMiddleware::rateHeaders($key, $key['rate'])
);
