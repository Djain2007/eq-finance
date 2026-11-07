# ==============================================================================
# EQ Finance — Windows PowerShell One-Command Installer & Runner
# ==============================================================================
$Port = if ($env:PORT) { $env:PORT } else { "8080" }

Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "               EQ Finance — One-Command Setup                   " -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

$dockerCmd = Get-Command docker -ErrorAction SilentlyContinue

if ($dockerCmd) {
    Write-Host "[*] Docker CLI detected. Checking if Docker engine is running..." -ForegroundColor Yellow
    $dockerInfo = docker info 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "[✓] Docker engine is active! Starting stack with Docker Compose..." -ForegroundColor Green
        docker compose up -d --build
        if ($LASTEXITCODE -eq 0) {
            Write-Host ""
            Write-Host "================================================================" -ForegroundColor Green
            Write-Host "  EQ Finance is UP and RUNNING!                                 " -ForegroundColor Green
            Write-Host "================================================================" -ForegroundColor Green
            Write-Host "  - Testing Studio:    http://localhost:$Port/test"
            Write-Host "  - Admin Panel:       http://localhost:$Port/admin/"
            Write-Host "  - API Documentation: http://localhost:$Port/docs"
            Write-Host "  - Health Endpoint:   http://localhost:$Port/api/v1/status"
            Write-Host ""
            Write-Host "  First-time setup: Create admin account at http://localhost:$Port/admin/"
            Write-Host "================================================================" -ForegroundColor Green
            Start-Process "http://localhost:$Port/admin/"
            exit 0
        }
    }
}

Write-Host "[*] Checking for native PHP environment..." -ForegroundColor Yellow
$phpCmd = Get-Command php -ErrorAction SilentlyContinue

if (-not $phpCmd) {
    $wingetPhp = "C:\Users\djain\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
    if (Test-Path $wingetPhp) {
        $phpPath = $wingetPhp
    } else {
        Write-Host "[✗] Neither Docker nor PHP was detected." -ForegroundColor Red
        Write-Host "    Please start Docker Desktop, or install PHP 8.1+." -ForegroundColor Red
        exit 1
    }
} else {
    $phpPath = $phpCmd.Source
}

if (-not (Test-Path "config\config.php")) {
    Write-Host "[*] Creating config\config.php from example template..." -ForegroundColor Yellow
    Copy-Item "config\config.example.php" "config\config.php"
    (Get-Content "config\config.php") -replace "'force_https' => true", "'force_https' => false" | Set-Content "config\config.php"
}

if (-not (Test-Path "storage\logs")) {
    New-Item -ItemType Directory -Path "storage\logs" -Force | Out-Null
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  Starting local PHP development server on http://localhost:$Port" -ForegroundColor Green
Write-Host "  - Admin Panel:       http://localhost:$Port/admin/"
Write-Host "  - API Documentation: http://localhost:$Port/docs"
Write-Host "  Press Ctrl+C to terminate the server."
Write-Host "================================================================" -ForegroundColor Green
Start-Process "http://localhost:$Port/docs"
& $phpPath -S "127.0.0.1:$Port" -t . tests/router.php

