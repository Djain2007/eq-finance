<?php
/**
 * EQ Finance — admin page initialiser. Included first by every admin page.
 * Sets $eqfPublicPage = true in a page BEFORE including this file to skip the login guard.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/helpers.php';

use App\Auth\AdminAuth;
use App\Auth\Csrf;
use App\Auth\Session;
use App\Config\Config;
use App\Helpers\Url;

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

// Configuration missing: show a friendly installation hint (no secrets).
if (!EQF_CONFIGURED) {
    http_response_code(503);
    require __DIR__ . '/not-configured.php';
    exit;
}

// HTTPS enforcement for the admin panel.
if (Config::get('app.force_https') && !Url::isHttps()) {
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        Url::redirect('https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
    }
}

try {
    \App\Database\Database::connection();
} catch (\Throwable $e) {
    http_response_code(503);
    require __DIR__ . '/db-error.php';
    exit;
}

Session::start();
Csrf::verifyRequest();

$eqfAdmin = null;
if (empty($eqfPublicPage)) {
    $eqfAdmin = AdminAuth::requireLogin();
}
