<?php
/**
 * EQ Finance — configuration template.
 *
 * Copy this file to config/config.php (or, preferably, to a file OUTSIDE your
 * document root, e.g. /home/USER/eq-finance-config.php) and fill in your values.
 *
 * Never commit config.php to version control.
 */

return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'your_database_name',
        'user'     => 'your_database_user',
        'pass'     => 'your_database_password',
        'charset'  => 'utf8mb4',
    ],

    'app' => [
        // Set to false on production hosts. When true, detailed errors are logged
        // (never displayed to clients) and the admin status page shows more detail.
        'debug' => false,

        // Force HTTPS for the admin panel and API when the host supports it.
        // Set to false only for local development without TLS.
        'force_https' => true,

        // Optional absolute base URL (no trailing slash). Leave empty to auto-detect.
        'base_url' => '',

        // Timezone used for display in the admin panel. Data is stored in UTC.
        'display_timezone' => 'UTC',
    ],

    'admin' => [
        'session_name'       => 'eqf_admin',
        'session_lifetime'   => 7200,   // seconds of inactivity before logout
        'login_max_attempts' => 5,      // failed attempts before lockout
        'login_lockout'      => 900,    // lockout duration in seconds
    ],

    'scraper' => [
        // Exchanges accepted by the API. Requests for any other exchange are rejected
        // BEFORE a URL is built — this is the core SSRF protection.
        'allowed_exchanges' => [
            // India
            'NSE', 'BOM',
            // United States
            'NASDAQ', 'NYSE', 'NYSEARCA', 'NYSEAMERICAN', 'OTCMKTS', 'BATS', 'CBOE',
            // United Kingdom / Europe
            'LON', 'FRA', 'ETR', 'EPA', 'AMS', 'BIT', 'BME', 'SWX', 'VIE', 'STO', 'CPH', 'HEL', 'OSL', 'WSE', 'EBR', 'ELI',
            // Asia-Pacific
            'TYO', 'HKG', 'SHA', 'SHE', 'KRX', 'KOSDAQ', 'TPE', 'SGX', 'BKK', 'ASX', 'NZE', 'IDX', 'KLSE',
            // Americas (other)
            'TSE', 'CVE', 'SAO', 'BMV', 'BCBA',
            // Africa / Middle East
            'JSE', 'TADAWUL', 'TLV',
            // Indices and currencies (same page layout on the source)
            'INDEXNSE', 'INDEXBOM', 'INDEXNASDAQ', 'INDEXSP', 'INDEXDJX', 'INDEXCBOE', 'INDEXFTSE', 'INDEXNIKKEI', 'INDEXHANGSENG', 'INDEXDB', 'INDEXEURO',
            'CURRENCY',
        ],

        // Upstream host. Only this host is ever contacted.
        'source_host' => 'www.google.com',

        // Hard cap on the size of a downloaded page (bytes).
        'max_response_bytes' => 3000000,
    ],

    'cron' => [
        // Secret used when running cron/cleanup.php over HTTP (?token=...).
        // Leave empty to allow CLI execution only. Generate with: bin2hex(random_bytes(16))
        'token' => '',
    ],
];
