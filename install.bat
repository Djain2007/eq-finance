@echo off
setlocal
set PORT=8080

echo ================================================================
echo                EQ Finance - One-Command Setup
echo ================================================================
echo.

where docker >nul 2>&1
if %errorlevel% equ 0 (
    echo [*] Docker detected. Checking Docker engine...
    docker info >nul 2>&1
    if %errorlevel% equ 0 (
        echo [✓] Starting containers with Docker Compose...
        docker compose up -d --build
        if %errorlevel% equ 0 (
            echo.
            echo ================================================================
            echo   EQ Finance is UP and RUNNING!
            echo.
            echo   - Admin Panel:       http://localhost:%PORT%/admin/
            echo   - API Documentation: http://localhost:%PORT%/docs
            echo   - Health Endpoint:   http://localhost:%PORT%/api/v1/status
            echo.
            echo   First-time setup: Open http://localhost:%PORT%/admin/ to create
            echo   your administrator account.
            echo ================================================================
            start http://localhost:%PORT%/admin/
            exit /b 0
        )
    )
)

echo [!] Docker engine is not running or not installed.
echo [*] Falling back to native Windows PowerShell installer...
powershell -ExecutionPolicy Bypass -File "%~dp0install.ps1"
exit /b %errorlevel%
