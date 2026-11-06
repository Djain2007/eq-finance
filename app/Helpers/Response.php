<?php
declare(strict_types=1);

namespace App\Helpers;

defined('EQF_ROOT') or exit('Direct access denied.');

/**
 * Consistent JSON response envelope for the public API.
 */
final class Response
{
    /** HTTP status used for each application error code. */
    public const STATUS_MAP = [
        'INVALID_REQUEST'        => 400,
        'INVALID_SYMBOL'         => 400,
        'UNSUPPORTED_EXCHANGE'   => 400,
        'BATCH_LIMIT_EXCEEDED'   => 400,
        'MISSING_API_KEY'        => 401,
        'INVALID_API_KEY'        => 401,
        'KEY_DISABLED'           => 403,
        'KEY_REVOKED'            => 403,
        'KEY_EXPIRED'            => 403,
        'NOT_FOUND'              => 404,
        'SYMBOL_NOT_FOUND'       => 404,
        'METHOD_NOT_ALLOWED'     => 405,
        'RATE_LIMIT_EXCEEDED'    => 429,
        'INTERNAL_ERROR'         => 500,
        'UNSUPPORTED_OPERATION'  => 501,
        'SOURCE_UNAVAILABLE'     => 502,
        'SOURCE_BLOCKED'         => 502,
        'EXTRACTION_FAILED'      => 502,
        'API_DISABLED'           => 503,
        'MAINTENANCE_MODE'       => 503,
        'SERVICE_UNAVAILABLE'    => 503,
    ];

    public static function statusFor(string $code): int
    {
        return self::STATUS_MAP[$code] ?? 500;
    }

    public static function success(array $data, array $meta = [], int $status = 200, array $headers = []): never
    {
        self::send(['success' => true, 'data' => $data, 'meta' => $meta], $status, $headers);
    }

    public static function error(string $code, string $message, ?int $status = null, array $details = [], array $headers = []): never
    {
        $body = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
        if ($details !== []) {
            $body['error']['details'] = $details;
        }
        self::send($body, $status ?? self::statusFor($code), $headers);
    }

    public static function send(array $body, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}
