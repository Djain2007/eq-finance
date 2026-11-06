<?php
declare(strict_types=1);

namespace App\Services;

defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Str;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Extracts structured quote data from a Google Finance quote page.
 *
 * Google Finance has no public API and its markup changes without notice, so
 * every field is optional and each is read with more than one strategy:
 *
 *   1. data-* attributes on the price element (most stable)
 *   2. Known CSS class names used by the quote header
 *   3. Label/value pairs in the "stats" grid (matched by label text, not class)
 *   4. <title> / <meta> tags as a last resort for the company name
 *
 * The parser never invents values. A field that cannot be read is null, and
 * the result carries an `availability` map saying what was actually found.
 */
final class StockParser
{
    /** Fields exposed in API responses, in output order. */
    public const FIELDS = [
        'symbol', 'name', 'exchange', 'currency', 'price', 'previous_close', 'change', 'change_percent',
        'day_high', 'day_low', 'fifty_two_week_high', 'fifty_two_week_low', 'market_cap', 'pe_ratio',
        'eps', 'volume', 'average_volume', 'dividend_yield', 'description', 'sector', 'industry',
        'website', 'source_timestamp',
    ];

    /** Label text (lower-case, punctuation stripped) => handler */
    private const LABEL_MAP = [
        'previous close'   => 'previous_close',
        'prev close'       => 'previous_close',
        'day range'        => 'day_range',
        'year range'       => 'year_range',
        '52 week range'    => 'year_range',
        '52-week range'    => 'year_range',
        'market cap'       => 'market_cap',
        'avg volume'       => 'average_volume',
        'average volume'   => 'average_volume',
        'volume'           => 'volume',
        'p/e ratio'        => 'pe_ratio',
        'pe ratio'         => 'pe_ratio',
        'dividend yield'   => 'dividend_yield',
        'primary exchange' => 'primary_exchange',
        'website'          => 'website',
        'sector'           => 'sector',
        'industry'         => 'industry',
        'earnings per share' => 'eps',
        'eps'              => 'eps',
    ];

    private const CURRENCY_SYMBOLS = [
        '₹' => 'INR', '$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY', '₩' => 'KRW',
        'CHF' => 'CHF', 'HK$' => 'HKD', 'A$' => 'AUD', 'C$' => 'CAD', 'S$' => 'SGD', 'R$' => 'BRL',
        '₪' => 'ILS', '₺' => 'TRY', 'kr' => 'SEK', 'zł' => 'PLN', 'R' => 'ZAR', '฿' => 'THB', 'NT$' => 'TWD',
    ];

    /**
     * @return array{ok:bool, data?:array, code?:string, message?:string}
     */
    public function parse(string $html, string $exchange, string $symbol): array
    {
        if (trim($html) === '') {
            return ['ok' => false, 'code' => 'EXTRACTION_FAILED', 'message' => 'The data source returned an empty page.'];
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $data = array_fill_keys(self::FIELDS, null);
        $data['symbol'] = $exchange . ':' . $symbol;
        $data['exchange'] = $exchange;

        // --- Strategy 1: data attributes on the price container -------------------
        $priceNode = $xp->query('//*[@data-last-price]')->item(0);
        if ($priceNode instanceof DOMElement) {
            $data['price'] = self::toFloat($priceNode->getAttribute('data-last-price'));
            $cur = strtoupper(trim($priceNode->getAttribute('data-currency-code')));
            if (preg_match('/^[A-Z]{3}$/', $cur)) {
                $data['currency'] = $cur;
            }
            $ts = $priceNode->getAttribute('data-last-normal-market-timestamp');
            if (preg_match('/^\d{9,11}$/', $ts)) {
                $data['source_timestamp'] = gmdate('Y-m-d\TH:i:s\Z', (int) $ts);
            }
        }

        // --- Strategy 2: known class names in the quote header --------------------
        $priceText = $this->firstText($xp, [
            '//div[contains(concat(" ", normalize-space(@class), " "), " YMlKec ") and contains(concat(" ", normalize-space(@class), " "), " fxKbKc ")]',
            '//*[@data-last-price]//div[contains(@class,"YMlKec")]',
        ]);
        if ($data['price'] === null && $priceText !== null) {
            $data['price'] = self::toFloat($priceText);
        }
        if ($data['currency'] === null && $priceText !== null) {
            $data['currency'] = self::currencyFromText($priceText);
        }

        $name = $this->firstText($xp, ['//div[contains(concat(" ", normalize-space(@class), " "), " zzDege ")]']);
        if ($name !== null && $name !== '') {
            $data['name'] = $name;
        }

        // Absolute change (e.g. "+10.50" / "-10.50") and percent change (e.g. "0.85%").
        $changeText = $this->firstText($xp, [
            '//span[contains(concat(" ", normalize-space(@class), " "), " P2Luy ")]',
        ]);
        $pctText = $this->firstText($xp, [
            '//div[contains(concat(" ", normalize-space(@class), " "), " JwB6zf ")]',
            '//span[contains(concat(" ", normalize-space(@class), " "), " NydbP ")]//div[contains(@class,"JwB6zf")]',
        ]);
        $pctSignNode = $xp->query('//span[contains(concat(" ", normalize-space(@class), " "), " NydbP ")]')->item(0);
        $sign = null;
        if ($pctSignNode instanceof DOMElement) {
            $cls = ' ' . $pctSignNode->getAttribute('class') . ' ';
            $aria = strtolower($pctSignNode->getAttribute('aria-label'));
            if (str_contains($cls, ' VOXKNe ') || str_contains($aria, 'down')) {
                $sign = -1;
            } elseif (str_contains($cls, ' tHZXSc ') || str_contains($aria, 'up')) {
                $sign = 1;
            }
        }
        if ($changeText !== null) {
            $data['change'] = self::toFloat($changeText, true);
        }
        if ($pctText !== null) {
            $pct = self::toFloat($pctText, true);
            if ($pct !== null && $sign !== null && !str_contains($pctText, '-') && !str_contains($pctText, '+')) {
                $pct = abs($pct) * $sign;
            }
            $data['change_percent'] = $pct;
        }

        // --- Strategy 3: label/value grid, matched on label text -------------------
        $labelNodes = $xp->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " gyFHrc ")]'
        );
        $pairs = [];
        foreach ($labelNodes as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }
            $label = $this->firstText($xp, ['.//div[contains(concat(" ", normalize-space(@class), " "), " mfs7Fc ")]'], $row);
            $value = $this->firstText($xp, ['.//div[contains(concat(" ", normalize-space(@class), " "), " P6K39c ")]'], $row);
            if ($label === null || $value === null) {
                continue;
            }
            $pairs[self::normalizeLabel($label)] = ['value' => $value, 'node' => $row];
        }
        // Generic fallback: any two-cell rows with a known label as first cell.
        if ($pairs === []) {
            foreach ($xp->query('//div[count(div)=2]') as $row) {
                $cells = $xp->query('./div', $row);
                $label = self::normalizeLabel(Str::clean($cells->item(0)->textContent ?? ''));
                if (isset(self::LABEL_MAP[$label])) {
                    $pairs[$label] = ['value' => Str::clean($cells->item(1)->textContent ?? ''), 'node' => $row];
                }
            }
        }

        foreach ($pairs as $label => $pair) {
            $key = self::LABEL_MAP[$label] ?? null;
            if ($key === null) {
                continue;
            }
            $value = $pair['value'];
            switch ($key) {
                case 'previous_close':
                    $data['previous_close'] = self::toFloat($value);
                    if ($data['currency'] === null) {
                        $data['currency'] = self::currencyFromText($value);
                    }
                    break;
                case 'day_range':
                    [$lo, $hi] = self::parseRange($value);
                    $data['day_low'] = $lo;
                    $data['day_high'] = $hi;
                    break;
                case 'year_range':
                    [$lo, $hi] = self::parseRange($value);
                    $data['fifty_two_week_low'] = $lo;
                    $data['fifty_two_week_high'] = $hi;
                    break;
                case 'market_cap':
                    $data['market_cap'] = self::parseAbbreviated($value);
                    if ($data['currency'] === null && preg_match('/\b([A-Z]{3})\b/', $value, $m)) {
                        $data['currency'] = $m[1];
                    }
                    break;
                case 'average_volume':
                    $data['average_volume'] = self::parseAbbreviated($value);
                    break;
                case 'volume':
                    $data['volume'] = self::parseAbbreviated($value);
                    break;
                case 'pe_ratio':
                    $data['pe_ratio'] = self::toFloat($value);
                    break;
                case 'eps':
                    $data['eps'] = self::toFloat($value, true);
                    break;
                case 'dividend_yield':
                    $data['dividend_yield'] = self::toFloat($value);
                    break;
                case 'website':
                    $href = $this->firstAttr($xp, './/a/@href', $pair['node']);
                    $candidate = $href ?? $value;
                    if (!preg_match('~^https?://~i', $candidate)) {
                        $candidate = 'https://' . ltrim($candidate, '/');
                    }
                    if (filter_var($candidate, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $candidate)) {
                        $data['website'] = $candidate;
                    }
                    break;
                case 'sector':
                    $data['sector'] = $value;
                    break;
                case 'industry':
                    $data['industry'] = $value;
                    break;
                case 'primary_exchange':
                    // informational only
                    break;
            }
        }

        // EPS from the financials table (label "Earnings per share") if not found above.
        if ($data['eps'] === null) {
            $epsNode = $xp->query('//tr[.//*[contains(translate(normalize-space(.),"EARNINGSPSH","earningspsh"),"earnings per share")]]')->item(0);
            if ($epsNode instanceof DOMElement) {
                $cells = $xp->query('.//td', $epsNode);
                // First cell is the label; the next cell holding a number is the value.
                for ($i = 1; $i < $cells->length; $i++) {
                    $cellText = Str::clean($cells->item($i)->textContent);
                    if (str_contains($cellText, '%')) {
                        continue; // year-over-year change column
                    }
                    $val = self::toFloat($cellText, true);
                    if ($val !== null) {
                        $data['eps'] = $val;
                        break;
                    }
                }
            }
        }

        // Company description.
        $desc = $this->firstText($xp, ['//div[contains(concat(" ", normalize-space(@class), " "), " bLLb2d ")]']);
        if ($desc !== null && mb_strlen($desc) > 20) {
            $data['description'] = Str::limit($desc, 2000);
        }

        // --- Strategy 4: <title> fallback for the company name --------------------
        if ($data['name'] === null) {
            $title = $this->firstText($xp, ['//title']);
            if ($title !== null && preg_match('/^(.+?)\s*\(' . preg_quote($symbol, '/') . '\)/u', $title, $m)) {
                $data['name'] = trim($m[1]);
            }
        }

        // Derived values (only from values actually read from the page).
        if ($data['change'] === null && $data['price'] !== null && $data['previous_close'] !== null) {
            $data['change'] = round($data['price'] - $data['previous_close'], 4);
        }
        if ($data['change_percent'] === null && $data['change'] !== null && $data['previous_close'] > 0) {
            $data['change_percent'] = round($data['change'] / $data['previous_close'] * 100, 4);
        }
        if ($data['change'] !== null && $data['change_percent'] !== null) {
            // Align sign of change with percent when the page only exposed magnitude.
            if (($data['change'] < 0) !== ($data['change_percent'] < 0) && $data['previous_close'] !== null && $data['price'] !== null) {
                $data['change'] = round($data['price'] - $data['previous_close'], 4);
                $data['change_percent'] = $data['previous_close'] > 0 ? round($data['change'] / $data['previous_close'] * 100, 4) : $data['change_percent'];
            }
        }

        // --- Outcome ------------------------------------------------------------
        if ($data['price'] === null) {
            $bodyText = Str::clean($doc->textContent ?? '');
            if (stripos($bodyText, "couldn't find") !== false || stripos($bodyText, 'no results') !== false || stripos($bodyText, 'could not find') !== false) {
                return ['ok' => false, 'code' => 'SYMBOL_NOT_FOUND', 'message' => 'The data source has no quote for this symbol.'];
            }
            return ['ok' => false, 'code' => 'EXTRACTION_FAILED', 'message' => 'The data source responded, but the quote could not be extracted. The page layout may have changed.'];
        }

        $availability = [];
        foreach (self::FIELDS as $field) {
            $availability[$field] = $data[$field] !== null;
        }
        $data['availability'] = $availability;

        return ['ok' => true, 'data' => $data];
    }

    /* ----------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

    private function firstText(DOMXPath $xp, array $queries, ?\DOMNode $context = null): ?string
    {
        foreach ($queries as $q) {
            $node = $context ? $xp->query($q, $context)->item(0) : $xp->query($q)->item(0);
            if ($node !== null) {
                $text = Str::clean($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return null;
    }

    private function firstAttr(DOMXPath $xp, string $query, \DOMNode $context): ?string
    {
        $node = $xp->query($query, $context)->item(0);
        return $node !== null ? trim($node->nodeValue ?? '') : null;
    }

    public static function normalizeLabel(string $label): string
    {
        $label = strtolower(Str::clean($label));
        $label = preg_replace('/[^a-z0-9\/\- ]/', '', $label) ?? $label;
        return trim(preg_replace('/\s+/', ' ', $label) ?? $label);
    }

    /** "₹1,250.50" → 1250.5 ; "-10.50" → -10.5 ; "0.85%" → 0.85 ; "—" → null */
    public static function toFloat(?string $text, bool $signed = false): ?float
    {
        if ($text === null) {
            return null;
        }
        $text = Str::clean($text);
        if ($text === '' || in_array($text, ['-', '—', '–', 'N/A', 'n/a'], true)) {
            return null;
        }
        // Unicode minus → ASCII minus
        $text = str_replace(["\xE2\x88\x92", "\xE2\x80\x93"], '-', $text);
        if (!preg_match('/(-?)\s*([0-9][0-9,]*(?:\.[0-9]+)?|\.[0-9]+)/', $text, $m)) {
            return null;
        }
        $num = (float) str_replace(',', '', $m[2]);
        if ($m[1] === '-' || ($signed && preg_match('/^\s*-/', $text))) {
            $num = -$num;
        }
        return $num;
    }

    /** "16.92T INR" → 16920000000000.0 ; "1.25M" → 1250000.0 ; "12,345" → 12345.0 */
    public static function parseAbbreviated(?string $text): ?float
    {
        if ($text === null) {
            return null;
        }
        $text = Str::clean($text);
        if (!preg_match('/([0-9][0-9,]*(?:\.[0-9]+)?)\s*([KMBT])?\b/i', $text, $m)) {
            return null;
        }
        $num = (float) str_replace(',', '', $m[1]);
        $mult = match (strtoupper($m[2] ?? '')) {
            'K' => 1e3, 'M' => 1e6, 'B' => 1e9, 'T' => 1e12, default => 1,
        };
        // sprintf avoids binary-float artifacts such as 16920000000000.002
        return (float) sprintf('%.2F', $num * $mult);
    }

    /** "1,235.00 - 1,260.00" → [1235.0, 1260.0] */
    public static function parseRange(string $text): array
    {
        $text = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', Str::clean($text));
        $parts = preg_split('/\s+-\s+|\s+to\s+/i', $text);
        if (!is_array($parts) || count($parts) !== 2) {
            return [null, null];
        }
        $lo = self::toFloat($parts[0]);
        $hi = self::toFloat($parts[1]);
        if ($lo !== null && $hi !== null && $lo > $hi) {
            [$lo, $hi] = [$hi, $lo];
        }
        return [$lo, $hi];
    }

    public static function currencyFromText(string $text): ?string
    {
        $text = Str::clean($text);
        if (preg_match('/\b([A-Z]{3})\b/', $text, $m) && !in_array($m[1], ['N/A'], true)) {
            return $m[1];
        }
        // Check multi-character symbols first (HK$ before $).
        $symbols = self::CURRENCY_SYMBOLS;
        uksort($symbols, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($symbols as $sym => $code) {
            if ($sym === 'R' || $sym === 'kr') {
                continue; // too ambiguous to infer from a bare prefix
            }
            if (str_contains($text, $sym)) {
                return $code;
            }
        }
        return null;
    }
}
