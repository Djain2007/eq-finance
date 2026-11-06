<?php
declare(strict_types=1);

namespace App\Database;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;
use PDO;
use PDOException;

/**
 * Lazy PDO connection (single connection per request).
 * All timestamps are stored in UTC; the session time zone is pinned to +00:00.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $lastError = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) Config::get('db.host', 'localhost');
        $port = (int) Config::get('db.port', 3306);
        $name = (string) Config::get('db.name', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        // Unix socket paths are accepted in the host field (common on cPanel).
        if (str_starts_with($host, '/')) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $host, $name, $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        }

        try {
            $pdo = new PDO($dsn, (string) Config::get('db.user', ''), (string) Config::get('db.pass', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET NAMES utf8mb4");
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            error_log('[EQ Finance] Database connection failed: ' . $e->getMessage());
            throw new DatabaseException('Database connection failed.');
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    /** Non-throwing health check used by status endpoints. */
    public static function ping(): bool
    {
        try {
            self::connection()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /** Current UTC timestamp in MySQL DATETIME format. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
