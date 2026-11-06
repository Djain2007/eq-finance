<?php
declare(strict_types=1);

namespace App\Auth;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;
use App\Helpers\Url;

/**
 * Admin account operations and the authentication guard.
 */
final class AdminAuth
{
    public static function adminCount(): int
    {
        try {
            return (int) Database::connection()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        } catch (\Throwable $e) {
            return -1; // table missing / DB down
        }
    }

    public static function setupRequired(): bool
    {
        return self::adminCount() === 0;
    }

    /** @return string[] validation errors */
    public static function validateCredentials(string $username, string $email, string $password, string $confirm): array
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3–50 characters (letters, numbers, dot, dash, underscore).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Enter a valid email address.';
        }
        if (mb_strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        return $errors;
    }

    public static function create(string $username, string $email, string $password): int
    {
        $now = Database::now();
        $stmt = Database::connection()->prepare(
            'INSERT INTO admins (username, email, password_hash, created_at, updated_at) VALUES (:u, :e, :p, :c, :m)'
        );
        $stmt->execute([
            ':u' => $username, ':e' => mb_strtolower($email),
            ':p' => password_hash($password, PASSWORD_DEFAULT), ':c' => $now, ':m' => $now,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @return array|null admin row on success */
    public static function attempt(string $username, string $password): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admins WHERE username = :u OR email = :e LIMIT 1');
        $stmt->execute([':u' => $username, ':e' => mb_strtolower($username)]);
        $admin = $stmt->fetch();
        // Constant-time-ish: always run password_verify, even without a match.
        $hash = $admin['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalid';
        if (!password_verify($password, $hash) || !$admin) {
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $up = Database::connection()->prepare('UPDATE admins SET password_hash = :p WHERE id = :id');
            $up->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $admin['id']]);
        }
        $touch = Database::connection()->prepare('UPDATE admins SET last_login = :t WHERE id = :id');
        $touch->execute([':t' => Database::now(), ':id' => $admin['id']]);
        return $admin;
    }

    public static function login(array $admin): void
    {
        Session::regenerate();
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = (string) $admin['username'];
        $_SESSION['login_at'] = time();
        unset($_SESSION['csrf_token']); // fresh token per login
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT id, username, email, created_at, last_login FROM admins WHERE id = :id');
        $stmt->execute([':id' => (int) $_SESSION['admin_id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Middleware: redirect to login (or setup) when not authenticated. */
    public static function requireLogin(): array
    {
        if (self::setupRequired()) {
            Url::redirect(Url::admin('setup.php'));
        }
        $user = self::user();
        if ($user === null) {
            $flash = $_SESSION['flash'] ?? null; // keep e.g. "session expired" from Session::start()
            Session::destroy();
            session_start();
            Session::flash($flash['type'] ?? 'warning', $flash['text'] ?? 'Please sign in to continue.');
            Url::redirect(Url::admin('login.php'));
        }
        return $user;
    }

    public static function changePassword(int $adminId, string $current, string $new, string $confirm): array
    {
        $errors = [];
        $stmt = Database::connection()->prepare('SELECT password_hash FROM admins WHERE id = :id');
        $stmt->execute([':id' => $adminId]);
        $hash = (string) $stmt->fetchColumn();
        if ($hash === '' || !password_verify($current, $hash)) {
            $errors[] = 'Current password is incorrect.';
        }
        if (mb_strlen($new) < 12) {
            $errors[] = 'New password must be at least 12 characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }
        if ($errors === []) {
            $up = Database::connection()->prepare('UPDATE admins SET password_hash = :p, updated_at = :t WHERE id = :id');
            $up->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':t' => Database::now(), ':id' => $adminId]);
        }
        return $errors;
    }
}
