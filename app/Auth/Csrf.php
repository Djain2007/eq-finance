<?php
declare(strict_types=1);

namespace App\Auth;

defined('EQF_ROOT') or exit('Direct access denied.');

/**
 * Synchroniser-token CSRF protection for admin forms.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): bool
    {
        if (!is_string($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string) $_SESSION['csrf_token'], $token);
    }

    /** Validate POST request or stop with 403. Also enforces same-origin via Origin/Referer when present. */
    public static function verifyRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $ok = self::validate($_POST['_csrf'] ?? null);
        if ($ok) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $srcHost = (string) (parse_url((string) $_SERVER[$h], PHP_URL_HOST) ?: '');
                    $srcPort = parse_url((string) $_SERVER[$h], PHP_URL_PORT);
                    $src = $srcHost . ($srcPort ? ':' . $srcPort : '');
                    if ($src !== '' && strcasecmp($src, $host) !== 0 && strcasecmp($srcHost, (string) explode(':', $host)[0]) !== 0) {
                        $ok = false;
                    }
                    break;
                }
            }
        }
        if (!$ok) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid or missing security token. Please go back, reload the page and try again.';
            exit;
        }
    }
}
