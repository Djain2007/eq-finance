<?php
/** EQ Finance — small view helpers for admin templates. */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;
use App\Helpers\Str;

function e(mixed $v): string
{
    return Str::e($v);
}

/** Format a UTC DATETIME string for display in the configured timezone. */
function fmt_dt(?string $utc, string $format = 'M j, Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $tz = (string) Config::get('app.display_timezone', 'UTC');
        $dt->setTimezone(new DateTimeZone($tz ?: 'UTC'));
        return $dt->format($format);
    } catch (\Throwable $e) {
        return $utc;
    }
}

function fmt_ago(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return 'never';
    }
    $ts = strtotime($utc . ' UTC');
    if (!$ts) {
        return $utc;
    }
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return intdiv($diff, 60) . ' min ago';
    if ($diff < 86400) return intdiv($diff, 3600) . ' h ago';
    if ($diff < 86400 * 30) return intdiv($diff, 86400) . ' d ago';
    return fmt_dt($utc, 'M j, Y');
}

function fmt_num(mixed $n): string
{
    return number_format((float) ($n ?? 0));
}

function fmt_limit(mixed $n): string
{
    return $n === null || (int) $n === 0 ? 'Unlimited' : number_format((int) $n);
}

function status_badge(int $status): string
{
    $cls = $status < 300 ? 'ok' : ($status < 500 ? 'warn' : 'err');
    return '<span class="badge badge-' . $cls . '">' . $status . '</span>';
}

function key_badge(string $status): string
{
    $map = ['active' => 'ok', 'disabled' => 'warn', 'revoked' => 'err'];
    return '<span class="badge badge-' . ($map[$status] ?? 'muted') . '">' . e(ucfirst($status)) . '</span>';
}

function post_str(string $key, int $max = 500): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function get_str(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

/** Build a query string for pagination links, preserving current filters. */
function qs(array $overrides = []): string
{
    $params = [];
    foreach ($_GET as $k => $v) {
        if (is_string($v) && $v !== '' && is_string($k)) {
            $params[$k] = $v;
        }
    }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = (string) $v;
        }
    }
    return $params ? '?' . http_build_query($params) : '';
}
