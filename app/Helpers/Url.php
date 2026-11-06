<?php
declare(strict_types=1);

namespace App\Helpers;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;

/**
 * Base-URL detection that works whether EQ Finance is installed at the
 * domain root or in a sub-folder of public_html.
 */
final class Url
{
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        // Honoured for sites behind a TLS-terminating proxy/CDN (e.g. Cloudflare). A direct
        // client could spoof this header, so force_https is defence-in-depth: the host or CDN
        // should still redirect HTTP to HTTPS itself.
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    /** e.g. https://example.com/eq-finance  (no trailing slash) */
    public static function base(): string
    {
        $configured = (string) Config::get('app.base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host) ?? 'localhost';
        return $scheme . '://' . $host . self::rootPath();
    }

    /** Project root path relative to the web host, e.g. "" or "/eq-finance". */
    public static function rootPath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $script = str_replace('\\', '/', $script);
        // Entry points live in admin/, api/v1/, public/, cron/ — strip that tail.
        $dir = str_replace('\\', '/', dirname($script));
        foreach (['/api/v1', '/admin', '/public', '/cron'] as $suffix) {
            if ($dir === $suffix || str_ends_with($dir, $suffix)) {
                $dir = substr($dir, 0, -strlen($suffix));
                break;
            }
        }
        $dir = rtrim($dir, '/');
        return $dir === '' || $dir === '.' ? '' : $dir;
    }

    public static function admin(string $path = ''): string
    {
        return self::rootPath() . '/admin/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        return self::rootPath() . '/public/assets/' . ltrim($path, '/');
    }

    public static function api(string $path = ''): string
    {
        return self::base() . '/api/v1/' . ltrim($path, '/');
    }

    public static function docs(): string
    {
        return self::rootPath() . '/docs';
    }

    public static function redirect(string $location): never
    {
        header('Location: ' . $location, true, 302);
        exit;
    }
}
