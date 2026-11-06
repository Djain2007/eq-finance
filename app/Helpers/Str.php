<?php
declare(strict_types=1);

namespace App\Helpers;

defined('EQF_ROOT') or exit('Direct access denied.');

final class Str
{
    /** HTML-escape for output in templates. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Collapse whitespace and trim (handles non-breaking spaces). */
    public static function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    public static function limit(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    public static function formatNumber(mixed $n, int $decimals = 0): string
    {
        return $n === null ? '—' : number_format((float) $n, $decimals);
    }
}
