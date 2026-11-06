<?php
declare(strict_types=1);

namespace App\Middleware;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Response;
use App\Services\ApiKeyService;
use App\Services\RateLimitService;
use App\Services\SettingsService;
use App\Services\UsageLogger;

/**
 * Guards API endpoints: global switches → API key → rate limit.
 * On failure it logs the request and sends the error response itself.
 */
final class ApiAuthMiddleware
{
    /**
     * @param int $cost rate-limit units consumed by this request (batch = number of symbols)
     * @return array the authenticated api_keys row, with 'rate' => remaining counters
     */
    public static function handle(string $endpoint, float $startedAt, int $cost = 1, ?string $symbolForLog = null): array
    {
        if (!SettingsService::bool('api_enabled')) {
            UsageLogger::record(null, $endpoint, $symbolForLog, 503, $startedAt, 'none', 'API_DISABLED');
            Response::error('API_DISABLED', 'The API is currently disabled by the administrator.');
        }
        if (SettingsService::bool('maintenance_mode')) {
            UsageLogger::record(null, $endpoint, $symbolForLog, 503, $startedAt, 'none', 'MAINTENANCE_MODE');
            Response::error('MAINTENANCE_MODE', 'The API is temporarily unavailable for maintenance.', null, [], ['Retry-After' => '300']);
        }

        $auth = ApiKeyService::authenticate(ApiKeyService::tokenFromRequest());
        if (!$auth['ok']) {
            $status = Response::statusFor($auth['code']);
            UsageLogger::record(null, $endpoint, $symbolForLog, $status, $startedAt, 'none', $auth['code']);
            $headers = $status === 401 ? ['WWW-Authenticate' => 'Bearer realm="EQ Finance API"'] : [];
            Response::error($auth['code'], $auth['message'], $status, [], $headers);
        }
        $key = $auth['key'];

        $rate = RateLimitService::hit($key, $cost);
        if (!$rate['ok']) {
            UsageLogger::record((int) $key['id'], $endpoint, $symbolForLog, 429, $startedAt, 'none', 'RATE_LIMIT_EXCEEDED');
            Response::error(
                'RATE_LIMIT_EXCEEDED',
                sprintf('Rate limit of %d requests per %s exceeded.', $rate['limit'], $rate['window']),
                429,
                ['limit' => $rate['limit'], 'window' => $rate['window'], 'retry_after_seconds' => $rate['retry_after']],
                ['Retry-After' => (string) $rate['retry_after']] + self::rateHeaders($key, $rate['remaining'])
            );
        }

        ApiKeyService::touch((int) $key['id']);
        $key['rate'] = $rate['remaining'];
        return $key;
    }

    /** X-RateLimit-* headers for successful responses. */
    public static function rateHeaders(array $key, array $remaining): array
    {
        $headers = [];
        $map = ['minute' => 'requests_per_minute', 'day' => 'requests_per_day', 'month' => 'monthly_limit'];
        foreach ($map as $window => $col) {
            if ($key[$col] !== null) {
                $headers['X-RateLimit-Limit-' . ucfirst($window)] = (string) (int) $key[$col];
                $headers['X-RateLimit-Remaining-' . ucfirst($window)] = (string) (int) ($remaining[$window] ?? 0);
            }
        }
        return $headers;
    }
}
