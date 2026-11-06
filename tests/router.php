<?php
/**
 * Development router for PHP's built-in web server. Emulates the .htaccess rules.
 *   php -S 127.0.0.1:8080 -t /path/to/eq-finance /path/to/eq-finance/tests/router.php
 * NOT used in production.
 */
$root = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (preg_match('#^/(app|config|database|storage|tests|cron)(/|$)#', $uri) && !preg_match('#^/cron/cleanup\.php$#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (preg_match('#\.(sql|log|md|example\.php)$#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if ($uri === '/' ) {
    require $root . '/public/index.php';
    return true;
}
if (preg_match('#^/docs/?$#', $uri)) {
    require $root . '/public/docs.php';
    return true;
}
if (preg_match('#^/assets/(.+)$#', $uri, $m)) {
    $file = $root . '/public/assets/' . $m[1];
    if (is_file($file)) {
        return false;
    }
}
if (preg_match('#^/api/v1/?$#', $uri)) {
    $_GET['_route'] = 'status';
    require $root . '/api/v1/index.php';
    return true;
}
if (preg_match('#^/api/v1/([a-z]+)/?$#', $uri, $m)) {
    $_GET['_route'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/api/v1/index.php';
    require $root . '/api/v1/index.php';
    return true;
}
if (preg_match('#^/admin/([a-z\-]+)/?$#', $uri, $m) && is_file($root . '/admin/' . $m[1] . '.php')) {
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $m[1] . '.php';
    require $root . '/admin/' . $m[1] . '.php';
    return true;
}
if (preg_match('#^/admin/?$#', $uri)) {
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require $root . '/admin/index.php';
    return true;
}
return false; // serve the file as-is
