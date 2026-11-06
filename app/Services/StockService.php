<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Logger;

/**
 * Orchestrates: validate → cache → (lock) → scrape → store → respond.
 * Returns plain arrays so the batch endpoint can reuse it.
 */
final class StockService
{
    /**
     * @return array{success:bool, data?:array, meta?:array, error?:array, status:int, cache_status:string}
     */
    public static function quote(string $input): array
    {
        $v = SymbolValidator::validate($input);
        if (!$v['ok']) {
            return self::fail($v['code'], $v['message']);
        }
        $symbol = $v['canonical'];
        $ttl = SettingsService::int('cache_ttl_seconds');
        $serveStale = SettingsService::bool('cache_serve_stale');
        $maxStale = SettingsService::int('cache_max_stale_seconds');

        // 1. Fresh cache?
        if ($ttl > 0) {
            $hit = CacheService::get($symbol);
            if ($hit !== null) {
                return self::ok($hit['data'], $hit['source_url'], $hit['fetched_at'], true, false, $hit['expires_at'], $hit['age'], 'hit');
            }
        }

        // 2. Become the fetcher, or wait for whoever already is.
        $locked = CacheService::acquireLock($symbol);
        if (!$locked) {
            $hit = CacheService::waitFor($symbol, 3.0);
            if ($hit !== null) {
                return self::ok($hit['data'], $hit['source_url'], $hit['fetched_at'], true, false, $hit['expires_at'], $hit['age'], 'hit');
            }
            // The other request may have failed or timed out; proceed ourselves.
        }

        // 3. Scrape.
        try {
            $scraper = new GoogleFinanceScraper();
            $result = $scraper->fetchQuote($v['exchange'], $v['symbol']);
        } catch (\Throwable $e) {
            Logger::exception($e, 'scrape ' . $symbol);
            CacheService::releaseLock($symbol);
            return self::fail('INTERNAL_ERROR', 'An unexpected error occurred while retrieving data.');
        }

        if ($result['ok']) {
            $data = $result['data'];
            if ($ttl > 0) {
                CacheService::put($symbol, $data, $result['source_url'], $result['retrieved_at'], $ttl);
            } else {
                CacheService::releaseLock($symbol);
            }
            $expires = gmdate('Y-m-d\TH:i:s\Z', (strtotime($result['retrieved_at']) ?: time()) + $ttl);
            return self::ok($data, $result['source_url'], $result['retrieved_at'], false, false, $expires, 0, 'miss');
        }

        // 4. Failure: never overwrite good data; optionally serve stale.
        CacheService::releaseLock($symbol);
        if ($serveStale && $result['code'] !== 'SYMBOL_NOT_FOUND') {
            $stale = CacheService::get($symbol, true, $maxStale);
            if ($stale !== null) {
                return self::ok($stale['data'], $stale['source_url'], $stale['fetched_at'], true, true, $stale['expires_at'], $stale['age'], 'stale', [
                    'upstream_error' => ['code' => $result['code'], 'message' => $result['message']],
                ]);
            }
        }
        return self::fail($result['code'], $result['message'], 'miss', [
            'source_url' => $result['source_url'] ?? null,
        ]);
    }

    private static function ok(array $data, string $sourceUrl, string $retrievedAt, bool $cached, bool $stale, string $expiresAt, int $age, string $cacheStatus, array $extraMeta = []): array
    {
        $meta = [
            'source'       => 'Google Finance',
            'source_url'   => $sourceUrl,
            'retrieved_at' => $retrievedAt,
            'cached'       => $cached,
            'stale'        => $stale,
            'cache_age_seconds' => $age,
            'cache_expires_at'  => $expiresAt,
        ] + $extraMeta;
        return ['success' => true, 'data' => $data, 'meta' => $meta, 'status' => 200, 'cache_status' => $cacheStatus];
    }

    private static function fail(string $code, string $message, string $cacheStatus = 'none', array $meta = []): array
    {
        $out = [
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message],
            'status'  => \App\Helpers\Response::statusFor($code),
            'cache_status' => $cacheStatus,
        ];
        $meta = array_filter($meta, static fn($v) => $v !== null);
        if ($meta !== []) {
            $out['meta'] = $meta;
        }
        return $out;
    }
}
