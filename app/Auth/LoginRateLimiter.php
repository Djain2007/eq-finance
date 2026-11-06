<?php
declare(strict_types=1);

namespace App\Auth;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;
use App\Database\Database;

/**
 * Brute-force protection for the admin login, keyed by hashed client IP.
 */
final class LoginRateLimiter
{
    private static function ipHash(): string
    {
        return hash('sha256', 'eqf-login|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    /** Seconds until login is allowed again, or 0 if allowed now. */
    public static function lockedFor(): int
    {
        $stmt = Database::connection()->prepare('SELECT locked_until FROM login_attempts WHERE ip_hash = :h');
        $stmt->execute([':h' => self::ipHash()]);
        $until = $stmt->fetchColumn();
        if (!$until) {
            return 0;
        }
        $ts = strtotime($until . ' UTC') ?: 0;
        return max(0, $ts - time());
    }

    public static function recordFailure(): void
    {
        $max = max(1, (int) Config::get('admin.login_max_attempts', 5));
        $lockout = max(60, (int) Config::get('admin.login_lockout', 900));
        $pdo = Database::connection();
        $now = Database::now();
        $windowStart = gmdate('Y-m-d H:i:s', time() - $lockout);

        // Reset the counter if the last attempt is older than the lockout window.
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (ip_hash, attempts, last_attempt, locked_until) VALUES (:h, 1, :now, NULL)
             ON DUPLICATE KEY UPDATE
               attempts = IF(last_attempt < :ws, 1, attempts + 1),
               last_attempt = VALUES(last_attempt)'
        );
        $stmt->execute([':h' => self::ipHash(), ':now' => $now, ':ws' => $windowStart]);

        $lock = $pdo->prepare(
            'UPDATE login_attempts SET locked_until = :lu WHERE ip_hash = :h AND attempts >= :max'
        );
        $lock->execute([':lu' => gmdate('Y-m-d H:i:s', time() + $lockout), ':h' => self::ipHash(), ':max' => $max]);
    }

    public static function clear(): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM login_attempts WHERE ip_hash = :h');
        $stmt->execute([':h' => self::ipHash()]);
    }

    public static function cleanup(): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM login_attempts WHERE last_attempt < :t');
        $stmt->execute([':t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        return $stmt->rowCount();
    }
}
