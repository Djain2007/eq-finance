<?php
declare(strict_types=1);

namespace App\Auth;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;
use App\Helpers\Url;

/**
 * Hardened PHP session handling for the admin panel.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = Url::isHttps();
        $name = (string) Config::get('admin.session_name', 'eqf_admin');
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $name)) {
            $name = 'eqf_admin';
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Url::rootPath() . '/admin/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max(600, (int) Config::get('admin.session_lifetime', 7200)));
        session_start();

        // Idle timeout.
        $lifetime = (int) Config::get('admin.session_lifetime', 7200);
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $lifetime) {
            self::destroy();
            session_start();
            $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Your session expired. Please sign in again.'];
        }
        $_SESSION['last_activity'] = $now;

        // Periodic ID rotation reduces the window for fixation/hijacking.
        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = $now;
        } elseif ($now - (int) $_SESSION['created_at'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = $now;
        }
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function flash(string $type, string $text): void
    {
        $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    }

    public static function pullFlash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_array($f) ? $f : null;
    }
}
