<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Config\Config;
use App\Helpers\Logger;

/**
 * Fetches a Google Finance quote page with cURL and hands the HTML to StockParser.
 *
 * Security properties:
 *  - Only HTTPS, only the configured source host, only paths under /finance/quote/.
 *  - Redirects are NOT followed automatically; at most 2 same-host /finance/ redirects
 *    are followed manually. Anything else is reported as SOURCE_UNAVAILABLE.
 *  - Response size is capped.
 *  - CAPTCHA / consent / block pages are detected and reported, never bypassed.
 */
final class GoogleFinanceScraper
{
    private const MAX_REDIRECTS = 2;

    /**
     * Optional transport override used ONLY by the automated test-suite
     * (tests/run-tests.php) so cache, lock and error flows can be exercised
     * without network access. It can only be set from PHP code.
     * Signature: fn(string $url): array{ok:bool, body?:string, status?:int, final_url?:string, code?:string, message?:string}
     */
    private static $transport = null;

    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @return array{ok:bool, data?:array, code?:string, message?:string, http_status?:int, source_url:string, retrieved_at:string}
     */
    public function fetchQuote(string $exchange, string $symbol): array
    {
        $url = SymbolValidator::sourceUrl($exchange, $symbol);
        $retrievedAt = gmdate('Y-m-d\TH:i:s\Z');

        $fetch = $this->request($url);
        if (!$fetch['ok']) {
            return $fetch + ['source_url' => $url, 'retrieved_at' => $retrievedAt];
        }

        $html = $fetch['body'];
        $blocked = $this->detectBlock($fetch['final_url'], $html);
        if ($blocked !== null) {
            Logger::warning('Upstream block detected', ['symbol' => $exchange . ':' . $symbol, 'reason' => $blocked]);
            return [
                'ok' => false, 'code' => 'SOURCE_BLOCKED',
                'message' => 'The data source is currently refusing automated requests (' . $blocked . '). Try again later.',
                'http_status' => $fetch['status'], 'source_url' => $url, 'retrieved_at' => $retrievedAt,
            ];
        }

        $parser = new StockParser();
        $parsed = $parser->parse($html, $exchange, $symbol);
        if (!$parsed['ok']) {
            return $parsed + ['http_status' => $fetch['status'], 'source_url' => $url, 'retrieved_at' => $retrievedAt];
        }

        return [
            'ok' => true,
            'data' => $parsed['data'],
            'http_status' => $fetch['status'],
            'source_url' => $url,
            'retrieved_at' => $retrievedAt,
        ];
    }

    /**
     * Perform the HTTP request with manual, restricted redirect handling.
     * @return array{ok:bool, body?:string, status?:int, final_url?:string, code?:string, message?:string, http_status?:int}
     */
    private function request(string $url): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url);
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'message' => 'PHP cURL extension is not available on this server.'];
        }

        $host = (string) Config::get('scraper.source_host', 'www.google.com');
        $maxBytes = max(200000, (int) Config::get('scraper.max_response_bytes', 3000000));
        $connectTimeout = max(1, SettingsService::int('connect_timeout_seconds'));
        $timeout = max($connectTimeout, SettingsService::int('request_timeout_seconds'));

        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->isAllowedUrl($current, $host)) {
                return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'message' => 'The data source redirected to an unexpected location.'];
            }

            $ch = curl_init();
            $body = '';
            $received = 0;
            curl_setopt_array($ch, [
                CURLOPT_URL            => $current,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => '', // accept gzip/deflate, decoded by cURL
                CURLOPT_HTTPHEADER     => $this->headers(),
                CURLOPT_USERAGENT      => SettingsService::get('user_agent'),
                CURLOPT_HEADER         => false,
                CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$received, $maxBytes): int {
                    $received += strlen($chunk);
                    if ($received > $maxBytes) {
                        return 0; // abort: response too large
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);

            curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($errno === CURLE_WRITE_ERROR && $received > $maxBytes) {
                return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'message' => 'The data source returned an unexpectedly large response.'];
            }
            if ($errno !== 0) {
                Logger::warning('Upstream cURL error', ['errno' => $errno, 'error' => $error]);
                $code = in_array($errno, [CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true)
                    ? 'SOURCE_UNAVAILABLE' : 'SOURCE_UNAVAILABLE';
                return ['ok' => false, 'code' => $code, 'message' => 'Unable to reach the data source (' . $this->curlErrorLabel($errno) . ').'];
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirect === '') {
                    return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'message' => 'The data source returned a redirect without a location.'];
                }
                // Resolve relative redirects against the current URL.
                if (str_starts_with($redirect, '/')) {
                    $redirect = 'https://' . $host . $redirect;
                }
                // Consent / sorry pages redirect off the quote path: report as blocked.
                if (!$this->isAllowedUrl($redirect, $host)) {
                    $target = (string) (parse_url($redirect, PHP_URL_HOST) ?: '');
                    if (str_contains($target, 'consent.') || str_contains($redirect, '/sorry/')) {
                        return ['ok' => false, 'code' => 'SOURCE_BLOCKED', 'http_status' => $status,
                            'message' => 'The data source requires consent or verification that this service does not bypass.'];
                    }
                    return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'http_status' => $status, 'message' => 'The data source redirected to an unexpected location.'];
                }
                $current = $redirect;
                continue;
            }

            if ($status === 404) {
                return ['ok' => false, 'code' => 'SYMBOL_NOT_FOUND', 'http_status' => 404, 'message' => 'The requested symbol was not found on the data source.'];
            }
            if ($status === 429 || $status === 403 || $status === 503) {
                return ['ok' => false, 'code' => 'SOURCE_BLOCKED', 'http_status' => $status,
                    'message' => 'The data source is rate limiting or blocking requests (HTTP ' . $status . '). Try again later.'];
            }
            if ($status !== 200) {
                return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'http_status' => $status, 'message' => 'The data source returned HTTP ' . $status . '.'];
            }
            if ($body === '' ) {
                return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'http_status' => $status, 'message' => 'The data source returned an empty response.'];
            }
            return ['ok' => true, 'body' => $body, 'status' => $status, 'final_url' => $current];
        }

        return ['ok' => false, 'code' => 'SOURCE_UNAVAILABLE', 'message' => 'Too many redirects from the data source.'];
    }

    /** Only https://<source_host>/finance/quote/... is ever contacted. */
    private function isAllowedUrl(string $url, string $host): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (strcasecmp((string) ($parts['host'] ?? ''), $host) !== 0) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '');
        return str_starts_with($path, '/finance/quote/');
    }

    /** @return string[] */
    private function headers(): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ' . SettingsService::get('accept_language'),
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
        ];
    }

    /** Detect pages that indicate the source refused the request. Returns a short reason or null. */
    private function detectBlock(string $finalUrl, string $html): ?string
    {
        if (str_contains($finalUrl, '/sorry/')) {
            return 'verification page';
        }
        $head = substr($html, 0, 20000);
        if (stripos($head, 'consent.google.com') !== false && stripos($head, 'data-last-price') === false) {
            return 'consent page';
        }
        if (stripos($head, 'unusual traffic') !== false || stripos($head, 'recaptcha') !== false && stripos($html, 'data-last-price') === false) {
            return 'automated-traffic check';
        }
        return null;
    }

    private function curlErrorLabel(int $errno): string
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEOUTED   => 'timeout',
            CURLE_COULDNT_CONNECT       => 'connection failed',
            CURLE_COULDNT_RESOLVE_HOST  => 'DNS resolution failed',
            CURLE_SSL_CONNECT_ERROR     => 'TLS handshake error',
            60                          => 'TLS certificate error',
            56                          => 'connection reset while receiving',
            default                     => 'network error ' . $errno,
        };
    }
}
