<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;

/**
 * Validates "EXCHANGE:SYMBOL" input and builds the ONLY kind of upstream URL
 * the application will ever request. Clients never supply URLs.
 *
 * This is the SSRF boundary: the exchange must be on the allow-list, the
 * symbol must match a strict pattern, and the host is fixed in configuration.
 */
final class SymbolValidator
{
    public const EXCHANGE_PATTERN = '/^[A-Z]{2,15}$/';
    // Letters, digits, and a few characters real tickers use (e.g. M&M, BAJAJ-AUTO, BRK.B, .INX, .DJI, 0700).
    public const SYMBOL_PATTERN = '/^[A-Z0-9.][A-Z0-9.\-_&]{0,23}$/';

    /**
     * @return array{ok:bool, exchange?:string, symbol?:string, canonical?:string, code?:string, message?:string}
     */
    public static function validate(mixed $input): array
    {
        if (!is_string($input)) {
            return self::fail('INVALID_SYMBOL', 'Symbol must be a string in the form EXCHANGE:SYMBOL.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $input)) {
            return self::fail('INVALID_SYMBOL', 'Symbol contains control characters.');
        }
        $input = strtoupper(trim($input));
        if ($input === '' || strlen($input) > 48) {
            return self::fail('INVALID_SYMBOL', 'Symbol must be in the form EXCHANGE:SYMBOL (for example NSE:RELIANCE).');
        }
        if (substr_count($input, ':') !== 1) {
            return self::fail('INVALID_SYMBOL', 'Symbol must be in the form EXCHANGE:SYMBOL (for example NSE:RELIANCE).');
        }
        [$exchange, $symbol] = explode(':', $input, 2);

        if (!preg_match(self::EXCHANGE_PATTERN, $exchange)) {
            return self::fail('INVALID_SYMBOL', 'Exchange identifier contains invalid characters.');
        }
        if (!preg_match(self::SYMBOL_PATTERN, $symbol) || $symbol === '.' || str_contains($symbol, '..')) {
            return self::fail('INVALID_SYMBOL', 'Ticker symbol contains invalid characters.');
        }
        if (!in_array($exchange, self::allowedExchanges(), true)) {
            return self::fail('UNSUPPORTED_EXCHANGE', 'Exchange "' . $exchange . '" is not supported.');
        }
        return [
            'ok'        => true,
            'exchange'  => $exchange,
            'symbol'    => $symbol,
            'canonical' => $exchange . ':' . $symbol,
        ];
    }

    /** @return string[] */
    public static function allowedExchanges(): array
    {
        $list = Config::get('scraper.allowed_exchanges', []);
        $out = [];
        foreach (is_array($list) ? $list : [] as $ex) {
            $ex = strtoupper(trim((string) $ex));
            if (preg_match(self::EXCHANGE_PATTERN, $ex)) {
                $out[] = $ex;
            }
        }
        return array_values(array_unique($out));
    }

    /** Builds https://www.google.com/finance/quote/EXCHANGE:SYMBOL from validated parts only. */
    public static function sourceUrl(string $exchange, string $symbol): string
    {
        $host = (string) Config::get('scraper.source_host', 'www.google.com');
        if (!preg_match('/^[a-z0-9.\-]+$/i', $host)) {
            $host = 'www.google.com';
        }
        // Google Finance uses the form /finance/quote/SYMBOL:EXCHANGE
        return 'https://' . $host . '/finance/quote/' . rawurlencode($symbol) . ':' . rawurlencode($exchange);
    }

    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
