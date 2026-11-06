<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;

/**
 * MySQL-backed quote cache with a lightweight fetch lock to limit stampedes.
 *
 * Rules:
 *  - Only successful, validated extractions are stored.
 *  - A failed fetch never overwrites existing data (it only releases the lock).
 *  - Cached responses keep their original retrieval timestamp.
 */
final class CacheService
{
    private const LOCK_SECONDS = 15;

    /**
     * @return array{data:array, source_url:string, fetched_at:string, expires_at:string, fresh:bool, age:int}|null
     */
    public static function get(string $symbol, bool $allowStale = false, int $maxStaleSeconds = 0): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM stock_cache WHERE symbol = :s LIMIT 1');
        $stmt->execute([':s' => $symbol]);
        $row = $stmt->fetch();
        if (!$row || $row['response_json'] === null || $row['fetched_at'] === null) {
            return null;
        }
        $nowTs = time();
        $fetchedTs = strtotime($row['fetched_at'] . ' UTC') ?: 0;
        $expiresTs = strtotime(($row['expires_at'] ?? $row['fetched_at']) . ' UTC') ?: 0;
        $fresh = $expiresTs > $nowTs;
        if (!$fresh) {
            if (!$allowStale || ($nowTs - $fetchedTs) > $maxStaleSeconds) {
                return null;
            }
        }
        $data = json_decode((string) $row['response_json'], true);
        if (!is_array($data)) {
            return null;
        }
        if ($fresh) {
            $upd = Database::connection()->prepare('UPDATE stock_cache SET hit_count = hit_count + 1 WHERE id = :id');
            $upd->execute([':id' => $row['id']]);
        }
        return [
            'data'       => $data,
            'source_url' => (string) $row['source_url'],
            'fetched_at' => gmdate('Y-m-d\TH:i:s\Z', $fetchedTs),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresTs),
            'fresh'      => $fresh,
            'age'        => max(0, $nowTs - $fetchedTs),
        ];
    }

    /**
     * Try to become the process that fetches this symbol. Returns true when the
     * lock was acquired, false when another request is already fetching.
     */
    public static function acquireLock(string $symbol): bool
    {
        $pdo = Database::connection();
        $lockUntil = gmdate('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
        $now = Database::now();
        $stmt = $pdo->prepare(
            'INSERT INTO stock_cache (symbol, lock_until) VALUES (:s, :l)
             ON DUPLICATE KEY UPDATE lock_until = IF(lock_until IS NULL OR lock_until < :now, VALUES(lock_until), lock_until)'
        );
        $stmt->execute([':s' => $symbol, ':l' => $lockUntil, ':now' => $now]);
        // affected rows: 1 = inserted, 2 = updated (lock taken), 0 = unchanged (someone else holds it)
        return $stmt->rowCount() > 0;
    }

    public static function releaseLock(string $symbol): void
    {
        $stmt = Database::connection()->prepare('UPDATE stock_cache SET lock_until = NULL WHERE symbol = :s');
        $stmt->execute([':s' => $symbol]);
    }

    /** Store a successful extraction. */
    public static function put(string $symbol, array $data, string $sourceUrl, string $retrievedAtIso, int $ttlSeconds): void
    {
        $fetchedTs = strtotime($retrievedAtIso) ?: time();
        $stmt = Database::connection()->prepare(
            'INSERT INTO stock_cache (symbol, response_json, source_url, fetched_at, expires_at, lock_until)
             VALUES (:s, :j, :u, :f, :e, NULL)
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), source_url = VALUES(source_url),
               fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at), lock_until = NULL'
        );
        $stmt->execute([
            ':s' => $symbol,
            ':j' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':u' => $sourceUrl,
            ':f' => gmdate('Y-m-d H:i:s', $fetchedTs),
            ':e' => gmdate('Y-m-d H:i:s', $fetchedTs + max(0, $ttlSeconds)),
        ]);
    }

    /** Wait briefly for another process to populate the cache. */
    public static function waitFor(string $symbol, float $maxSeconds = 3.0): ?array
    {
        $deadline = microtime(true) + $maxSeconds;
        while (microtime(true) < $deadline) {
            usleep(250000);
            $hit = self::get($symbol);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    public static function purge(?string $symbol = null): int
    {
        $pdo = Database::connection();
        if ($symbol !== null) {
            $stmt = $pdo->prepare('DELETE FROM stock_cache WHERE symbol = :s');
            $stmt->execute([':s' => $symbol]);
            return $stmt->rowCount();
        }
        return $pdo->exec('DELETE FROM stock_cache') ?: 0;
    }

    /** Delete rows whose data expired more than $retentionSeconds ago, plus orphaned lock rows. */
    public static function cleanup(int $retentionSeconds): int
    {
        $pdo = Database::connection();
        $threshold = gmdate('Y-m-d H:i:s', time() - max(0, $retentionSeconds));
        $stmt = $pdo->prepare(
            'DELETE FROM stock_cache WHERE (expires_at IS NOT NULL AND expires_at < :t)
               OR (response_json IS NULL AND (lock_until IS NULL OR lock_until < :now))'
        );
        $stmt->execute([':t' => $threshold, ':now' => Database::now()]);
        return $stmt->rowCount();
    }

    public static function stats(): array
    {
        $pdo = Database::connection();
        $now = Database::now();
        $row = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN response_json IS NOT NULL AND expires_at > :now THEN 1 ELSE 0 END) AS fresh,
                    SUM(CASE WHEN response_json IS NOT NULL AND (expires_at IS NULL OR expires_at <= :now2) THEN 1 ELSE 0 END) AS expired,
                    COALESCE(SUM(hit_count),0) AS hits
             FROM stock_cache'
        );
        $row->execute([':now' => $now, ':now2' => $now]);
        $r = $row->fetch() ?: [];
        return [
            'total'   => (int) ($r['total'] ?? 0),
            'fresh'   => (int) ($r['fresh'] ?? 0),
            'expired' => (int) ($r['expired'] ?? 0),
            'hits'    => (int) ($r['hits'] ?? 0),
        ];
    }
}
