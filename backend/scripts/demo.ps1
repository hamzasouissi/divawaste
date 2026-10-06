<#
  Local demonstration environment of DivaWaste (docs/DEVELOPER_RUNBOOK.md).

    .\scripts\demo.ps1 reset       rebuild the demo database (divawaste_demo) with the demo dataset
    .\scripts\demo.ps1 api         start the API on http://127.0.0.1:8010   (keep the window open)
    .\scripts\demo.ps1 scheduler   run the daily tasks once (accreditation alerts, invoicing)
    .\scripts\demo.ps1 mail        list the last emails the application produced: recipient, subject, link, delivered or not
    .\scripts\demo.ps1 mail 12     print email number 12 and open it in the browser
    .\scripts\demo.ps1 mailtest you@example.com   send one test email through the mail server of .env.demo
    .\scripts\demo.ps1 apidocs     regenerate the API documentation (public\docs, OpenAPI in storage\app\private\scribe)
    .\scripts\demo.ps1 stop        stop the API (8010) and the Angular server (4300)

  It never touches .env or the database it names: everything goes through .env.demo.
#>
param(
    [Parameter(Position = 0)][ValidateSet('reset', 'api', 'scheduler', 'mail', 'mailtest', 'apidocs', 'stop')][string]$Action = 'api',
    [Parameter(Position = 1)][string]$Argument = ''
)

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
        # With MAIL_MAILER=log (the default of .env.demo) no email leaves this computer: this is where they are read.
        Initialize-DemoEnv
        if ($Argument) {
            php artisan mail:outbox --show=$Argument --env=demo
            $body = "$root\storage\app\mail-outbox\$Argument.html"
            if (Test-Path $body) { Start-Process $body }
        } else {
            php artisan mail:outbox --env=demo
        }
    }
    'mailtest' {
        Initialize-DemoEnv
        if (-not $Argument) { throw 'Give the address that should receive the test: .\scripts\demo.ps1 mailtest you@example.com' }
        php artisan mail:test $Argument --env=demo
    }
    'apidocs' {
        Initialize-DemoEnv
        php artisan api:docs --env=demo
    }
    'stop' {
        Stop-Port 8010
        Stop-Port 4300
    }
}
