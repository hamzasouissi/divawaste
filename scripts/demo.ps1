<#
  Local demonstration environment of DivaWaste (docs/DEVELOPER_RUNBOOK.md).

    .\scripts\demo.ps1 reset       rebuild the demo database (divawaste_demo) with the demo dataset
    .\scripts\demo.ps1 api         start the API on http://127.0.0.1:8010   (keep the window open)
    .\scripts\demo.ps1 scheduler   run the daily tasks once (accreditation alerts, invoicing)
    .\scripts\demo.ps1 mail        show the links of the last emails (verification, invitation, password reset)
    .\scripts\demo.ps1 stop        stop the API (8010) and the Angular server (4300)

  It never touches .env or the database it names: everything goes through .env.demo.
#>
param([Parameter(Position = 0)][ValidateSet('reset', 'api', 'scheduler', 'mail', 'stop')][string]$Action = 'api')

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Initialize-DemoEnv {
    if (Test-Path "$root\.env.demo") { return }
    if (-not (Test-Path "$root\.env")) { throw "No .env found in $root. Create it first (docs/SETUP.md): the demo copies its database credentials and APP_KEY." }

    $source = @{}
    foreach ($line in Get-Content "$root\.env") {
        if ($line -match '^\s*(APP_KEY|DB_HOST|DB_PORT|DB_USERNAME|DB_PASSWORD)\s*=\s*(.*?)\s*$') { $source[$Matches[1]] = $Matches[2] }
    }
    $lines = foreach ($line in Get-Content "$root\.env.demo.example") {
        if ($line -match '^(APP_KEY|DB_HOST|DB_PORT|DB_USERNAME|DB_PASSWORD)=' -and $source.ContainsKey($Matches[1])) { "$($Matches[1])=$($source[$Matches[1]])" } else { $line }
    }
    [System.IO.File]::WriteAllLines("$root\.env.demo", $lines)
    Write-Host 'Created .env.demo (database divawaste_demo, credentials and APP_KEY copied from .env).'
}

function Stop-Port([int]$port) {
    $owners = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
    foreach ($owner in $owners) {
        Stop-Process -Id $owner -Force -ErrorAction SilentlyContinue
        Write-Host "Stopped process $owner (port $port)."
    }
    if (-not $owners) { Write-Host "Nothing is listening on port $port." }
}

switch ($Action) {
    'reset' {
        Initialize-DemoEnv
        php artisan demo:reset --env=demo
    }
    'api' {
        Initialize-DemoEnv
        $env:APP_ENV = 'demo'
        Write-Host 'DivaWaste demo API on http://127.0.0.1:8010 — open the application at http://localhost:4300 (Ctrl+C to stop).'
        Set-Location "$root\public"
        php -S 127.0.0.1:8010 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
    }
    'scheduler' {
        Initialize-DemoEnv
        php artisan accreditations:alert --env=demo
        php artisan subscriptions:run --env=demo
    }
    'mail' {
        # Emails are written to the log in this environment: the links are what a tester needs.
        $links = Select-String -Path "$root\storage\logs\laravel.log" -Pattern 'http://localhost:4300/[^\s"<)\]]+' -AllMatches |
            ForEach-Object { $_.Matches.Value } | ForEach-Object { $_ -replace '&amp;', '&' }
        if (-not $links) { Write-Host 'No link found yet in storage\logs\laravel.log.'; return }
        $links = @($links)
        [array]::Reverse($links)
        # Newest first, each link once.
        $links | Select-Object -Unique | Select-Object -First 8
    }
    'stop' {
        Stop-Port 8010
        Stop-Port 4300
    }
}
