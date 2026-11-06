<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;

/**
 * Writes one api_usage row per API request. Never stores keys or headers.
 */
final class UsageLogger
{
    public static function record(
        ?int $apiKeyId,
        string $endpoint,
        ?string $symbol,
        int $status,
        float $startedAt,
        string $cacheStatus = 'none',
        ?string $errorCode = null
    ): void {
        try {
            $ms = (int) round((microtime(true) - $startedAt) * 1000);
            $stmt = Database::connection()->prepare(
                'INSERT INTO api_usage (api_key_id, endpoint, requested_symbol, response_status, response_time_ms, cache_status, error_code, ip_hash, created_at)
                 VALUES (:k, :e, :s, :st, :ms, :c, :err, :ip, :t)'
            );
            $stmt->execute([
                ':k'   => $apiKeyId,
                ':e'   => mb_substr($endpoint, 0, 40),
                ':s'   => $symbol === null ? null : mb_substr($symbol, 0, 255),
                ':st'  => $status,
                ':ms'  => max(0, $ms),
                ':c'   => in_array($cacheStatus, ['hit', 'miss', 'stale', 'none'], true) ? $cacheStatus : 'none',
                ':err' => $errorCode === null ? null : mb_substr($errorCode, 0, 40),
                ':ip'  => self::ipHash(),
                ':t'   => Database::now(),
            ]);
        } catch (\Throwable $e) {
            error_log('[EQ Finance] usage log failed: ' . $e->getMessage());
        }
    }

    /** IPs are stored only as a salted hash (abuse analysis without storing PII). */
    public static function ipHash(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || $ip === '') {
            return null;
        }
        return hash('sha256', 'eqf-ip|' . $ip);
    }

    public static function cleanup(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $stmt = Database::connection()->prepare('DELETE FROM api_usage WHERE created_at < :t');
        $stmt->execute([':t' => gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400)]);
        return $stmt->rowCount();
    }
}
