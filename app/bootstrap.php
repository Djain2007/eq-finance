<?php
/**
 * EQ Finance — application bootstrap.
 *
 * Included by every entry point (admin/, api/, public/, cron/).
 * Loads configuration, registers the autoloader and sets safe PHP defaults.
 */

declare(strict_types=1);

define('EQF_ROOT', dirname(__DIR__));
define('EQF_VERSION', '1.0.0');
define('EQF_NAME', 'EQ Finance');

// Never display errors to clients. Errors are logged to storage/logs.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

/* ---------------------------------------------------------------------------
 * Autoloader (PSR-4 style, namespace App\ => app/)
 * ------------------------------------------------------------------------- */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = EQF_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/* ---------------------------------------------------------------------------
 * Configuration lookup. First match wins:
 *   1. EQF_CONFIG_PATH environment variable (set via .htaccess SetEnv or hosting panel)
 *   2. ../eq-finance-config.php  (one level ABOVE the project — outside public_html)
 *   3. config/config.php         (inside the project, protected by .htaccess)
 * ------------------------------------------------------------------------- */
$eqfConfigCandidates = [];
foreach ([getenv('EQF_CONFIG_PATH'), $_SERVER['EQF_CONFIG_PATH'] ?? null, $_SERVER['REDIRECT_EQF_CONFIG_PATH'] ?? null] as $envPath) {
    if (is_string($envPath) && $envPath !== '' && !str_contains($envPath, "\0")) {
        $eqfConfigCandidates[] = $envPath;
    }
}
$eqfConfigCandidates[] = dirname(EQF_ROOT) . '/eq-finance-config.php';
$eqfConfigCandidates[] = EQF_ROOT . '/config/config.php';

$eqfConfigFile = null;
foreach ($eqfConfigCandidates as $candidate) {
    if (is_file($candidate)) {
        $eqfConfigFile = $candidate;
        break;
    }
}

/* ---------------------------------------------------------------------------
 * Storage directory. Prefer ../eq-finance-storage (outside docroot) if it exists.
 * ------------------------------------------------------------------------- */
$eqfStorage = dirname(EQF_ROOT) . '/eq-finance-storage';
if (!is_dir($eqfStorage)) {
    $eqfStorage = EQF_ROOT . '/storage';
}
define('EQF_STORAGE', $eqfStorage);
if (is_dir(EQF_STORAGE . '/logs') && is_writable(EQF_STORAGE . '/logs')) {
    ini_set('error_log', EQF_STORAGE . '/logs/php-errors.log');
}

if ($eqfConfigFile === null) {
    define('EQF_CONFIGURED', false);
    \App\Config\Config::load([]);
} else {
    define('EQF_CONFIGURED', true);
    $loaded = require $eqfConfigFile;
    \App\Config\Config::load(is_array($loaded) ? $loaded : []);
}
unset($eqfConfigCandidates, $eqfConfigFile, $envPath, $candidate, $loaded, $eqfStorage);

/* ---------------------------------------------------------------------------
 * Global exception / error handling: log details, show nothing sensitive.
 * ------------------------------------------------------------------------- */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
