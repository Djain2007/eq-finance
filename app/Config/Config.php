<?php
declare(strict_types=1);

namespace App\Config;

defined('EQF_ROOT') or exit('Direct access denied.');

/**
 * Static configuration holder (file-based config only; runtime settings live in the DB).
 */
final class Config
{
    private static array $data = [];

    public static function load(array $data): void
    {
        self::$data = array_replace_recursive(self::defaults(), $data);
    }

    /** Dot-notation getter: Config::get('db.host') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $node = self::$data;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data;
    }

    private static function defaults(): array
    {
        return [
            'db' => [
                'host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'charset' => 'utf8mb4',
            ],
            'app' => [
                'debug' => false, 'force_https' => true, 'base_url' => '', 'display_timezone' => 'UTC',
            ],
            'admin' => [
                'session_name' => 'eqf_admin', 'session_lifetime' => 7200,
                'login_max_attempts' => 5, 'login_lockout' => 900,
            ],
            'scraper' => [
                'allowed_exchanges' => ['NSE', 'BOM', 'NASDAQ', 'NYSE'],
                'source_host' => 'www.google.com',
                'max_response_bytes' => 3000000,
            ],
            'cron' => ['token' => ''],
        ];
    }
}
