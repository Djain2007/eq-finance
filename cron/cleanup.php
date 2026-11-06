<?php
/**
 * EQ Finance — housekeeping job.
 *
 * Removes expired cache rows, stale rate-limit counters, old usage logs and
 * old login-attempt rows. Safe to run as often as you like; hourly is plenty.
 *
 * cPanel cron examples:
 *   CLI:  0 * * * * /usr/local/bin/php /home/USER/public_html/eq-finance/cron/cleanup.php
 *   HTTP: 0 * * * * curl -s "https://YOURDOMAIN.com/eq-finance/cron/cleanup.php?token=YOUR_CRON_TOKEN" > /dev/null
 *
 * HTTP execution requires cron.token to be set in the config file. Without a
 * token the script can only be run from the command line.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\LoginRateLimiter;
use App\Config\Config;
use App\Helpers\Logger;
use App\Services\CacheService;
use App\Services\RateLimitService;
use App\Services\SettingsService;
use App\Services\UsageLogger;

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $token = (string) Config::get('cron.token', '');
    $given = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if ($token === '' || $given === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

if (!EQF_CONFIGURED) {
    echo "EQ Finance is not configured.\n";
    exit($isCli ? 1 : 0);
}

try {
    $cache = CacheService::cleanup(SettingsService::int('cache_retention_seconds'));
    $rate = RateLimitService::cleanup();
    $logs = UsageLogger::cleanup(SettingsService::int('log_retention_days'));
    $logins = LoginRateLimiter::cleanup();
    $summary = sprintf('cache=%d rate_limits=%d usage_logs=%d login_attempts=%d', $cache, $rate, $logs, $logins);
    Logger::info('Cleanup run: ' . $summary);
    echo 'EQ Finance cleanup OK: ' . $summary . "\n";
} catch (\Throwable $e) {
    Logger::exception($e, 'cron/cleanup');
    if (!$isCli) {
        http_response_code(500);
    }
    echo "Cleanup failed. See storage/logs/app.log.\n";
    exit($isCli ? 1 : 0);
}
