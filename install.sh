#!/usr/bin/env bash
# ==============================================================================
# EQ Finance — Universal One-Command Installer (Linux & macOS)
# ==============================================================================
set -e

PORT="${PORT:-8080}"

echo "================================================================"
echo "               EQ Finance — One-Command Setup                   "
echo "================================================================"
echo ""

# 1. Check if Docker and Docker Compose are available
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    echo "[✓] Docker and Docker Compose detected."
    echo "[*] Launching EQ Finance via Docker Compose..."
    echo ""
    docker compose up -d --build

    echo ""
    echo "[*] Waiting for services to become healthy..."
    MAX_ATTEMPTS=30
    ATTEMPT=0
    HEALTHY=0

    while [ $ATTEMPT -lt $MAX_ATTEMPTS ]; do
        STATUS=$(curl -s "http://localhost:${PORT}/api/v1/status" 2>/dev/null || true)
        if echo "$STATUS" | grep -q '"success":true'; then
            HEALTHY=1
            break
        fi
        sleep 2
        ATTEMPT=$((ATTEMPT + 1))
        printf "."
    done
    echo ""

    echo ""
    echo "================================================================"
    echo "  🎉 EQ Finance is UP and RUNNING!                             "
    echo "================================================================"
    echo ""
    echo "  - Testing Studio:    http://localhost:${PORT}/test"
    echo "  - Admin Panel:       http://localhost:${PORT}/admin/"
    echo "  - API Documentation: http://localhost:${PORT}/docs"
    echo "  - Health Endpoint:   http://localhost:${PORT}/api/v1/status"
    echo ""
    echo "  First-time setup: Open http://localhost:${PORT}/admin/ to create"
    echo "  your administrator account."
    echo ""
    echo "  To stop:             docker compose down"
    echo "  To view logs:        docker compose logs -f"
    echo "================================================================"

    # Attempt to open in browser
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "http://localhost:${PORT}/admin/" 2>/dev/null || true
    elif command -v open >/dev/null 2>&1; then
        open "http://localhost:${PORT}/admin/" 2>/dev/null || true
    fi
    exit 0
fi

# 2. Native Fallback if Docker is not installed
echo "[!] Docker not detected. Checking for native PHP & MySQL..."

if ! command -v php >/dev/null 2>&1; then
    echo "[✗] Neither Docker nor PHP is installed."
    echo "    Please install Docker (https://docs.docker.com/get-docker/)"
    echo "    or PHP 8.1+ with pdo_mysql, curl, and dom extensions."
    exit 1
fi

PHP_VER=$(php -r "echo PHP_VERSION;")
echo "[✓] Found PHP $PHP_VER"

if [ ! -f "config/config.php" ]; then
    echo "[*] Copying default configuration to config/config.php..."
    cp config/config.example.php config/config.php
    sed -i.bak "s/'force_https' => true/'force_https' => false/g" config/config.php && rm -f config/config.php.bak
fi

mkdir -p storage/logs
chmod 775 storage/logs

echo ""
echo "================================================================"
echo "  Starting local PHP development server on http://localhost:${PORT}"
echo "  Note: Configure config/config.php with your local MySQL credentials"
echo "  Press Ctrl+C to stop."
echo "================================================================"
php -S "0.0.0.0:${PORT}" -t . tests/router.php

