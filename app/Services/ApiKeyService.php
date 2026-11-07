<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;

/**
 * API key lifecycle. Keys look like eqf_live_<48 hex chars>. Only the SHA-256
 * hash is stored; the plaintext key is returned exactly once at creation.
 */
final class ApiKeyService
{
    public const PREFIX = 'eqf_live_';
    public const KEY_PATTERN = '/^eqf_live_[a-f0-9]{48}$/';

    /**
     * @param array{name:string, requests_per_minute?:?int, requests_per_day?:?int, monthly_limit?:?int, expires_at?:?string} $attrs
     * @return array{id:int, key:string, prefix:string}
     */
    public static function create(array $attrs): array
    {
        $plain = self::PREFIX . bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, strlen(self::PREFIX) + 6);
        $now = Database::now();

        $stmt = Database::connection()->prepare(
            'INSERT INTO api_keys (name, key_prefix, key_hash, status, requests_per_minute, requests_per_day, monthly_limit, expires_at, created_at, updated_at)
             VALUES (:name, :prefix, :hash, :status, :rpm, :rpd, :rpmo, :exp, :now, :now2)'
        );
        $stmt->execute([
            ':name'   => mb_substr(trim($attrs['name']), 0, 100),
            ':prefix' => $prefix,
            ':hash'   => $hash,
            ':status' => 'active',
            ':rpm'    => self::limitValue($attrs['requests_per_minute'] ?? null),
            ':rpd'    => self::limitValue($attrs['requests_per_day'] ?? null),
            ':rpmo'   => self::limitValue($attrs['monthly_limit'] ?? null),
            ':exp'    => $attrs['expires_at'] ?? null,
            ':now'    => $now,
            ':now2'   => $now,
        ]);
        $id = (int) Database::connection()->lastInsertId();
        return ['id' => $id, 'key' => $plain, 'prefix' => $prefix];
    }

    /** NULL/0/'' => unlimited (stored as NULL). */
    public static function limitValue(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }
        return min((int) $value, 1000000000);
    }

    /**
     * Resolve a bearer token to a key row and check its status.
     * @return array{ok:bool, key?:array, code?:string, message?:string}
     */
    public static function authenticate(?string $token): array
    {
        if ($token === null || $token === '') {
            return ['ok' => false, 'code' => 'MISSING_API_KEY', 'message' => 'Provide your API key in the Authorization header: Bearer eqf_live_...'];
        }
        $token = trim($token);
        if (!preg_match(self::KEY_PATTERN, $token)) {
            return ['ok' => false, 'code' => 'INVALID_API_KEY', 'message' => 'The API key is not valid.'];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM api_keys WHERE key_hash = :h LIMIT 1');
        $stmt->execute([':h' => hash('sha256', $token)]);
        $key = $stmt->fetch();
        if (!$key) {
            return ['ok' => false, 'code' => 'INVALID_API_KEY', 'message' => 'The API key is not valid.'];
        }
        if ($key['status'] === 'revoked') {
            return ['ok' => false, 'code' => 'KEY_REVOKED', 'message' => 'This API key has been revoked.'];
        }
        if ($key['status'] !== 'active') {
            return ['ok' => false, 'code' => 'KEY_DISABLED', 'message' => 'This API key is disabled.'];
        }
        if ($key['expires_at'] !== null && $key['expires_at'] <= Database::now()) {
            return ['ok' => false, 'code' => 'KEY_EXPIRED', 'message' => 'This API key has expired.'];
        }
        return ['ok' => true, 'key' => $key];
    }

    public static function touch(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE api_keys SET last_used_at = :t WHERE id = :id');
        $stmt->execute([':t' => Database::now(), ':id' => $id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM api_keys WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get exact key counts across all statuses.
     * @return array{total:int, active:int, expired:int, disabled:int, revoked:int}
     */
    public static function counts(): array
    {
        $pdo = Database::connection();
        $row = $pdo->query(
            "SELECT 
                COUNT(*) AS total,
                COALESCE(SUM(status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())), 0) AS active,
                COALESCE(SUM(status = 'active' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()), 0) AS expired,
                COALESCE(SUM(status = 'disabled'), 0) AS disabled,
                COALESCE(SUM(status = 'revoked'), 0) AS revoked
             FROM api_keys"
        )->fetch() ?: [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'expired'  => (int) ($row['expired'] ?? 0),
            'disabled' => (int) ($row['disabled'] ?? 0),
            'revoked'  => (int) ($row['revoked'] ?? 0),
        ];
    }

    /**
     * @param ?string $filter 'all' | 'active' | 'disabled' | 'revoked' | 'expired' | null
     * @return array[]
     */
    public static function all(?string $filter = null): array
    {
        $where = '';
        if ($filter === 'active') {
            $where = " WHERE k.status = 'active' AND (k.expires_at IS NULL OR k.expires_at > UTC_TIMESTAMP())";
        } elseif ($filter === 'expired') {
            $where = " WHERE k.status = 'active' AND k.expires_at IS NOT NULL AND k.expires_at <= UTC_TIMESTAMP()";
        } elseif ($filter === 'disabled') {
            $where = " WHERE k.status = 'disabled'";
        } elseif ($filter === 'revoked') {
            $where = " WHERE k.status = 'revoked'";
        }

        return Database::connection()->query(
            "SELECT k.*, (SELECT COUNT(*) FROM api_usage u WHERE u.api_key_id = k.id) AS total_requests
             FROM api_keys k{$where} ORDER BY k.created_at DESC, k.id DESC"
        )->fetchAll();
    }

    public static function update(int $id, array $attrs): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE api_keys SET name = :name, requests_per_minute = :rpm, requests_per_day = :rpd, monthly_limit = :rpmo,
             expires_at = :exp, updated_at = :now WHERE id = :id AND status <> "revoked"'
        );
        $stmt->execute([
            ':name' => mb_substr(trim((string) $attrs['name']), 0, 100),
            ':rpm'  => self::limitValue($attrs['requests_per_minute'] ?? null),
            ':rpd'  => self::limitValue($attrs['requests_per_day'] ?? null),
            ':rpmo' => self::limitValue($attrs['monthly_limit'] ?? null),
            ':exp'  => $attrs['expires_at'] ?? null,
            ':now'  => Database::now(),
            ':id'   => $id,
        ]);
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE api_keys SET status = :s, updated_at = :now WHERE id = :id AND status <> "revoked"'
        );
        $stmt->execute([':s' => $status, ':now' => Database::now(), ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** Revocation is permanent and takes effect on the next request. */
    public static function revoke(int $id): void
    {
        $now = Database::now();
        $stmt = Database::connection()->prepare(
            'UPDATE api_keys SET status = "revoked", revoked_at = :r, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':r' => $now, ':now' => $now, ':id' => $id]);
        // Drop rate-limit counters; they are no longer needed.
        $del = Database::connection()->prepare('DELETE FROM rate_limits WHERE api_key_id = :id');
        $del->execute([':id' => $id]);
    }

    /**
     * Rotation: create a replacement with identical settings, revoke the old key.
     * @return array{id:int, key:string, prefix:string}|null
     */
    public static function rotate(int $id): ?array
    {
        $old = self::find($id);
        if (!$old || $old['status'] === 'revoked') {
            return null;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $new = self::create([
                'name'                => $old['name'],
                'requests_per_minute' => $old['requests_per_minute'],
                'requests_per_day'    => $old['requests_per_day'],
                'monthly_limit'       => $old['monthly_limit'],
                'expires_at'          => $old['expires_at'],
            ]);
            self::revoke($id);
            $stmt = $pdo->prepare('UPDATE api_keys SET replaced_by_id = :n WHERE id = :id');
            $stmt->execute([':n' => $new['id'], ':id' => $id]);
            $pdo->commit();
            return $new;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Extract a bearer token from the request (header or, as a fallback, api_key query parameter). */
    public static function tokenFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!$header && function_exists('apache_request_headers')) {
            $all = apache_request_headers();
            foreach ($all as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (is_string($header) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return $m[1];
        }
        if (isset($_SERVER['HTTP_X_API_KEY']) && is_string($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        return null;
    }
}
