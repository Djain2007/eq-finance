<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;

/**
 * Aggregations for the admin dashboard. All queries use indexed columns.
 */
final class StatsService
{
    public static function dashboard(): array
    {
        $pdo = Database::connection();
        $today = gmdate('Y-m-d 00:00:00');
        $monthStart = gmdate('Y-m-01 00:00:00');

        $keys = $pdo->query(
            "SELECT 
                COUNT(*) AS total,
                COALESCE(SUM(status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())), 0) AS active,
                COALESCE(SUM(status = 'active' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()), 0) AS expired,
                COALESCE(SUM(status = 'disabled'), 0) AS disabled,
                COALESCE(SUM(status = 'revoked'), 0) AS revoked
             FROM api_keys"
        )->fetch() ?: [];

        $usage = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(created_at >= :today) AS today,
                    SUM(created_at >= :month) AS month,
                    SUM(response_status < 400) AS ok,
                    SUM(response_status >= 400) AS failed,
                    SUM(cache_status = "hit") AS cache_hits,
                    SUM(cache_status = "miss") AS cache_misses,
                    AVG(CASE WHEN response_status < 400 THEN response_time_ms END) AS avg_ms
             FROM api_usage'
        );
        $usage->execute([':today' => $today, ':month' => $monthStart]);
        $u = $usage->fetch() ?: [];

        return [
            'keys_total'    => (int) ($keys['total'] ?? 0),
            'keys_active'   => (int) ($keys['active'] ?? 0),
            'keys_expired'  => (int) ($keys['expired'] ?? 0),
            'keys_disabled' => (int) ($keys['disabled'] ?? 0),
            'keys_revoked'  => (int) ($keys['revoked'] ?? 0),
            'requests_total'=> (int) ($u['total'] ?? 0),
            'requests_today'=> (int) ($u['today'] ?? 0),
            'requests_month'=> (int) ($u['month'] ?? 0),
            'requests_ok'   => (int) ($u['ok'] ?? 0),
            'requests_failed' => (int) ($u['failed'] ?? 0),
            'cache_hits'    => (int) ($u['cache_hits'] ?? 0),
            'cache_misses'  => (int) ($u['cache_misses'] ?? 0),
            'avg_ms'        => $u['avg_ms'] !== null ? (int) round((float) $u['avg_ms']) : null,
        ];
    }

    /** Requests per day for the last N days (filled with zeros). */
    public static function dailySeries(int $days = 14): array
    {
        $start = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        $stmt = Database::connection()->prepare(
            'SELECT DATE(created_at) AS d, COUNT(*) AS total, SUM(response_status >= 400) AS failed
             FROM api_usage WHERE created_at >= :s GROUP BY DATE(created_at)'
        );
        $stmt->execute([':s' => $start]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['d']] = ['total' => (int) $row['total'], 'failed' => (int) $row['failed']];
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $series[] = ['date' => $d, 'total' => $map[$d]['total'] ?? 0, 'failed' => $map[$d]['failed'] ?? 0];
        }
        return $series;
    }

    public static function topSymbols(int $limit = 8, int $days = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT requested_symbol AS symbol, COUNT(*) AS total
             FROM api_usage
             WHERE requested_symbol IS NOT NULL AND endpoint = "stock" AND created_at >= :s
             GROUP BY requested_symbol ORDER BY total DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([':s' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        return $stmt->fetchAll();
    }

    public static function recentActivity(int $limit = 10): array
    {
        return Database::connection()->query(
            'SELECT u.*, k.name AS key_name, k.key_prefix
             FROM api_usage u LEFT JOIN api_keys k ON k.id = u.api_key_id
             ORDER BY u.id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    public static function endpointBreakdown(int $days = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT endpoint, COUNT(*) AS total, SUM(response_status >= 400) AS failed, AVG(response_time_ms) AS avg_ms
             FROM api_usage WHERE created_at >= :s GROUP BY endpoint ORDER BY total DESC'
        );
        $stmt->execute([':s' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        return $stmt->fetchAll();
    }

    public static function errorBreakdown(int $days = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT error_code, COUNT(*) AS total FROM api_usage
             WHERE error_code IS NOT NULL AND created_at >= :s GROUP BY error_code ORDER BY total DESC LIMIT 10'
        );
        $stmt->execute([':s' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        return $stmt->fetchAll();
    }

    public static function perKeyUsage(int $days = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT k.id, k.name, k.key_prefix, k.status, k.last_used_at, k.created_at,
                    COUNT(u.id) AS total, COALESCE(SUM(u.response_status >= 400), 0) AS failed,
                    COALESCE(SUM(u.created_at >= :today), 0) AS today
             FROM api_keys k LEFT JOIN api_usage u ON u.api_key_id = k.id AND u.created_at >= :s
             GROUP BY k.id, k.name, k.key_prefix, k.status, k.last_used_at, k.created_at 
             ORDER BY total DESC, k.created_at DESC'
        );
        $stmt->execute([':s' => gmdate('Y-m-d H:i:s', time() - $days * 86400), ':today' => gmdate('Y-m-d 00:00:00')]);
        return $stmt->fetchAll();
    }

    /**
     * Paginated, filterable request log.
     * @param array{key_id?:int, endpoint?:string, status?:string, from?:string, to?:string, symbol?:string} $filters
     */
    public static function logs(array $filters, int $page, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['key_id'])) {
            $where[] = 'u.api_key_id = :k';
            $params[':k'] = (int) $filters['key_id'];
        }
        if (!empty($filters['endpoint'])) {
            $where[] = 'u.endpoint = :e';
            $params[':e'] = $filters['endpoint'];
        }
        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'success': $where[] = 'u.response_status < 400'; break;
                case 'client':  $where[] = 'u.response_status BETWEEN 400 AND 499'; break;
                case 'server':  $where[] = 'u.response_status >= 500'; break;
                default:
                    if (preg_match('/^\d{3}$/', (string) $filters['status'])) {
                        $where[] = 'u.response_status = :st';
                        $params[':st'] = (int) $filters['status'];
                    }
            }
        }
        if (!empty($filters['from'])) {
            $where[] = 'u.created_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'u.created_at <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['symbol'])) {
            $where[] = 'u.requested_symbol LIKE :sym';
            $params[':sym'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['symbol']) . '%';
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM api_usage u' . $sql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            'SELECT u.*, k.name AS key_name, k.key_prefix FROM api_usage u LEFT JOIN api_keys k ON k.id = u.api_key_id'
            . $sql . ' ORDER BY u.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }
}
