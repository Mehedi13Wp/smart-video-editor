$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
foreach ($directory in @('bootstrap/cache','storage/app/private','storage/app/private/incoming','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','database')) {
    New-Item -ItemType Directory -Force $directory | Out-Null
}
if (!(Test-Path .env)) { Copy-Item .env.example .env }
if (!(Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite | Out-Null }
$env:COMPOSER_CACHE_DIR = Join-Path (Get-Location) '.cache/composer'
composer install --prefer-dist --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer install failed. Check access to Packagist and GitHub.' }
if ((Get-Content .env) -contains 'APP_KEY=') {
    php artisan key:generate
    if ($LASTEXITCODE -ne 0) { throw 'Application key generation failed.' }
}
php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
npm.cmd install --cache .cache/npm --no-audit --no-fund
if ($LASTEXITCODE -ne 0) { throw 'npm install failed. Check access to registry.npmjs.org.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
Write-Host 'Setup complete. Run: powershell -ExecutionPolicy Bypass -File scripts/start.ps1'
