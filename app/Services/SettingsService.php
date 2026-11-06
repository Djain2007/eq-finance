<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;

/**
 * Runtime settings stored in the `settings` table (editable from the admin panel).
 * Loaded once per request.
 */
final class SettingsService
{
    private static ?array $cache = null;

    /** Definitions: key => [default, type, label, help] */
    public const DEFINITIONS = [
        'api_name'                    => ['EQ Finance', 'string', 'API name', 'Shown in documentation and status responses.'],
        'api_description'             => ['Lightweight Google Finance scraping API.', 'string', 'API description', 'Shown on the documentation page.'],
        'api_version'                 => ['1.0.0', 'string', 'API version', 'Reported by /api/v1/status.'],
        'api_enabled'                 => ['1', 'bool', 'API enabled', 'When off, every API request returns 503 API_DISABLED.'],
        'maintenance_mode'            => ['0', 'bool', 'Maintenance mode', 'When on, API requests return 503 MAINTENANCE_MODE. Admin panel stays available.'],
        'cache_ttl_seconds'           => ['60', 'int', 'Default cache duration (seconds)', 'How long a scraped quote is served from the database cache.'],
        'cache_serve_stale'           => ['0', 'bool', 'Serve stale cache on upstream failure', 'If the source is unavailable, return the last valid cached quote with meta.stale = true instead of an error.'],
        'cache_max_stale_seconds'     => ['900', 'int', 'Maximum stale age (seconds)', 'Stale data older than this is never served, even when stale serving is enabled.'],
        'cache_retention_seconds'     => ['86400', 'int', 'Cache row retention (seconds)', 'Expired cache rows older than this are deleted by the cleanup cron.'],
        'request_timeout_seconds'     => ['12', 'int', 'Request timeout (seconds)', 'Total time allowed for one upstream request.'],
        'connect_timeout_seconds'     => ['5', 'int', 'Connection timeout (seconds)', 'Time allowed to establish the upstream connection.'],
        'max_batch_size'              => ['10', 'int', 'Maximum batch size', 'Maximum symbols accepted by /api/v1/stocks in one call.'],
        'default_requests_per_minute' => ['30', 'int', 'Default requests per minute', 'Applied to new API keys. 0 = unlimited.'],
        'default_requests_per_day'    => ['1000', 'int', 'Default requests per day', 'Applied to new API keys. 0 = unlimited.'],
        'default_monthly_limit'       => ['0', 'int', 'Default monthly limit', 'Applied to new API keys. 0 = unlimited.'],
        'user_agent'                  => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'string', 'Upstream User-Agent', 'Sent with every request to the data source.'],
        'accept_language'             => ['en-US,en;q=0.9', 'string', 'Upstream Accept-Language', 'Affects number formatting on the source page. Keep an English locale.'],
        'log_retention_days'          => ['90', 'int', 'Usage log retention (days)', 'Usage log rows older than this are deleted by the cleanup cron. 0 = keep forever.'],
    ];

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $values = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $values[$key] = $def[0];
        }
        try {
            $rows = Database::connection()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                if (array_key_exists($row['setting_key'], $values)) {
                    $values[$row['setting_key']] = (string) $row['setting_value'];
                }
            }
        } catch (\Throwable $e) {
            // Fall back to defaults if the table is not available yet.
        }
        self::$cache = $values;
        return $values;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (self::DEFINITIONS[$key][0] ?? '');
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::get($key)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Validate + persist a set of values. Returns list of human-readable errors. */
    public static function update(array $input): array
    {
        $errors = [];
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :t)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        foreach ($input as $key => $raw) {
            if (!isset(self::DEFINITIONS[$key])) {
                continue;
            }
            [$default, $type, $label] = self::DEFINITIONS[$key];
            $value = is_string($raw) ? trim($raw) : (string) $raw;
            switch ($type) {
                case 'bool':
                    $value = in_array(strtolower($value), ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
                    break;
                case 'int':
                    if ($value === '' || !preg_match('/^\d{1,9}$/', $value)) {
                        $errors[] = $label . ' must be a whole number (0 or more).';
                        continue 2;
                    }
                    $value = (string) (int) $value;
                    if (in_array($key, ['request_timeout_seconds', 'connect_timeout_seconds'], true) && ((int) $value < 1 || (int) $value > 60)) {
                        $errors[] = $label . ' must be between 1 and 60.';
                        continue 2;
                    }
                    if ($key === 'max_batch_size' && ((int) $value < 1 || (int) $value > 50)) {
                        $errors[] = $label . ' must be between 1 and 50.';
                        continue 2;
                    }
                    if ($key === 'cache_ttl_seconds' && (int) $value < 0) {
                        $errors[] = $label . ' must be 0 or more.';
                        continue 2;
                    }
                    break;
                default:
                    if (mb_strlen($value) > 500) {
                        $errors[] = $label . ' is too long.';
                        continue 2;
                    }
                    if (in_array($key, ['api_name', 'api_version', 'user_agent', 'accept_language'], true) && $value === '') {
                        $errors[] = $label . ' cannot be empty.';
                        continue 2;
                    }
            }
            $stmt->execute([':k' => $key, ':v' => $value, ':t' => Database::now()]);
        }
        self::$cache = null;
        return $errors;
    }
}
