#!/bin/sh
set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-eq_finance}"
DB_USER="${DB_USER:-eq_user}"
DB_PASS="${DB_PASS:-eq_password}"
APP_FORCE_HTTPS="${APP_FORCE_HTTPS:-false}"

echo "[EQ Finance] Initializing container..."

# Create config/config.php if not present
if [ ! -f /var/www/html/config/config.php ]; then
    echo "[EQ Finance] Creating config/config.php from environment..."
    CRON_TOKEN=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1 2>/dev/null || echo "eqf_cron_secret_1234567890abcdef")
    cat <<EOF > /var/www/html/config/config.php
<?php
declare(strict_types=1);

return [
    'db' => [
        'host'    => '${DB_HOST}',
        'port'    => (int) '${DB_PORT}',
        'name'    => '${DB_NAME}',
        'user'    => '${DB_USER}',
        'pass'    => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'debug'            => false,
        'force_https'      => ${APP_FORCE_HTTPS},
        'base_url'         => null,
        'display_timezone' => 'UTC',
    ],
    'admin' => [
        'session_lifetime'   => 7200,
        'login_max_attempts' => 5,
        'login_lockout'      => 900,
    ],
    'scraper' => [
        'allowed_exchanges' => [
            'NSE', 'BOM', 'NASDAQ', 'NYSE', 'LON', 'TYO', 'HKG', 'SHA', 'SHE',
            'FRA', 'ETR', 'TSX', 'ASX', 'SGX', 'INDEXBOM', 'INDEXNSE',
            'INDEXSP', 'INDEXDJX', 'INDEXFTSE',
        ],
        'source_host'         => 'www.google.com',
        'max_response_bytes'  => 2097152,
    ],
    'cron' => [
        'token' => '${CRON_TOKEN}',
    ],
];
EOF
    chown www-data:www-data /var/www/html/config/config.php
    chmod 640 /var/www/html/config/config.php
fi

# Ensure storage directory exists and is writable
mkdir -p /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Wait for MySQL / MariaDB connection
if [ -n "$DB_HOST" ]; then
    echo "[EQ Finance] Waiting for database connection at ${DB_HOST}:${DB_PORT}..."
    max_retries=30
    counter=0
    until mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" --silent > /dev/null 2>&1; do
        counter=$((counter + 1))
        if [ $counter -ge $max_retries ]; then
            echo "[EQ Finance] Warning: Database connection timed out. Starting web server anyway."
            break
        fi
        sleep 1
    done

    if [ $counter -lt $max_retries ]; then
        echo "[EQ Finance] Database connection established."
        # Auto-import install.sql if tables do not exist
        table_count=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -N -e "SHOW TABLES LIKE 'settings';" 2>/dev/null | wc -l)
        if [ "$table_count" -eq 0 ]; then
            echo "[EQ Finance] Importing database schema from database/install.sql..."
            mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" < /var/www/html/database/install.sql
            echo "[EQ Finance] Database schema successfully imported."
        else
            echo "[EQ Finance] Database tables already present, skipping import."
        fi
    fi
fi

echo "[EQ Finance] Startup complete. Starting web server..."
exec "$@"
