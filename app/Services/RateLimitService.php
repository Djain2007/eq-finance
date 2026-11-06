<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;

/**
 * Fixed-window rate limiting backed by MySQL.
 * Counters are incremented atomically (INSERT ... ON DUPLICATE KEY UPDATE),
 * which is safe under concurrent requests without any locking in PHP.
 */
final class RateLimitService
{
    /**
     * Consume `$cost` units for the key across all configured windows.
     *
     * @param array $key  api_keys row
     * @return array{ok:bool, limit?:int, window?:string, remaining:array<string,?int>, retry_after?:int}
     */
    public static function hit(array $key, int $cost = 1): array
    {
        $cost = max(1, $cost);
        $now = time();
        $windows = [
            'minute' => ['id' => 'm:' . gmdate('YmdHi', $now),  'limit' => $key['requests_per_minute'], 'reset' => 60 - ($now % 60)],
            'day'    => ['id' => 'd:' . gmdate('Ymd', $now),    'limit' => $key['requests_per_day'],    'reset' => 86400 - ($now % 86400)],
            'month'  => ['id' => 'mo:' . gmdate('Ym', $now),    'limit' => $key['monthly_limit'],       'reset' => (int) gmmktime(0, 0, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now)) - $now],
        ];

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (api_key_id, request_window, request_count, updated_at)
             VALUES (:k, :w, :c, :t)
             ON DUPLICATE KEY UPDATE request_count = request_count + VALUES(request_count), updated_at = VALUES(updated_at)'
        );
        $read = $pdo->prepare('SELECT request_count FROM rate_limits WHERE api_key_id = :k AND request_window = :w');

        $remaining = [];
        $exceeded = null;
        $charged = [];
        foreach ($windows as $name => $w) {
            $limit = $w['limit'] === null ? null : (int) $w['limit'];
            if ($limit === null) {
                $remaining[$name] = null; // unlimited: no counter needed
                continue;
            }
            $stmt->execute([':k' => $key['id'], ':w' => $w['id'], ':c' => $cost, ':t' => Database::now()]);
            $charged[] = $w['id'];
            $read->execute([':k' => $key['id'], ':w' => $w['id']]);
            $count = (int) $read->fetchColumn();
            $remaining[$name] = max(0, $limit - $count);
            if ($count > $limit && $exceeded === null) {
                $exceeded = ['limit' => $limit, 'window' => $name, 'retry_after' => $w['reset']];
            }
        }

        if ($exceeded !== null) {
            // Rejected requests must not consume quota: refund what was just charged.
            $refund = $pdo->prepare(
                'UPDATE rate_limits SET request_count = IF(request_count > :c, request_count - :c2, 0)
                 WHERE api_key_id = :k AND request_window = :w'
            );
            foreach ($charged as $windowId) {
                $refund->execute([':c' => $cost, ':c2' => $cost, ':k' => $key['id'], ':w' => $windowId]);
            }
            foreach ($windows as $name => $w) {
                if ($w['limit'] !== null) {
                    $remaining[$name] = max(0, (int) $w['limit'] - (self::usage((int) $key['id'])[$name] ?? 0));
                }
            }
            return ['ok' => false, 'remaining' => $remaining] + $exceeded;
        }
        return ['ok' => true, 'remaining' => $remaining];
    }

    /** Current usage for display in the admin panel. */
    public static function usage(int $keyId): array
    {
        $now = time();
        $ids = ['minute' => 'm:' . gmdate('YmdHi', $now), 'day' => 'd:' . gmdate('Ymd', $now), 'month' => 'mo:' . gmdate('Ym', $now)];
        $stmt = Database::connection()->prepare('SELECT request_window, request_count FROM rate_limits WHERE api_key_id = :k AND request_window IN (:a, :b, :c)');
        $stmt->execute([':k' => $keyId, ':a' => $ids['minute'], ':b' => $ids['day'], ':c' => $ids['month']]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['request_window']] = (int) $row['request_count'];
        }
        return [
            'minute' => $map[$ids['minute']] ?? 0,
            'day'    => $map[$ids['day']] ?? 0,
            'month'  => $map[$ids['month']] ?? 0,
        ];
    }

    /** Delete counters for windows that can no longer be hit. Returns rows deleted. */
    public static function cleanup(): int
    {
        // Minute windows older than 2 minutes, day windows older than 2 days, month windows older than 2 months.
        $pdo = Database::connection();
        $deleted = 0;
        $deleted += self::deleteOlder($pdo, 'm:', gmdate('YmdHi', time() - 120));
        $deleted += self::deleteOlder($pdo, 'd:', gmdate('Ymd', time() - 2 * 86400));
        $deleted += self::deleteOlder($pdo, 'mo:', gmdate('Ym', strtotime('-2 months')));
        return $deleted;
    }

    private static function deleteOlder(\PDO $pdo, string $prefix, string $threshold): int
    {
        $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE request_window LIKE :p AND request_window < :t');
        $stmt->execute([':p' => $prefix . '%', ':t' => $prefix . $threshold]);
        return $stmt->rowCount();
    }
}
