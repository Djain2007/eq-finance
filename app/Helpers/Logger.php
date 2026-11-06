<?php
declare(strict_types=1);

namespace App\Helpers;

defined('EQF_ROOT') or exit('Direct access denied.');

/**
 * Minimal file logger (storage/logs/app.log). Never logs secrets.
 */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = EQF_STORAGE . '/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log('[EQ Finance][' . $level . '] ' . $message);
            return;
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function exception(\Throwable $e, string $where = ''): void
    {
        self::log('error', ($where !== '' ? $where . ': ' : '') . get_class($e) . ': ' . $e->getMessage(), [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);
    }
}
