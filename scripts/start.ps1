$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
if (!(Test-Path vendor/autoload.php) -or !(Test-Path public/build/manifest.json)) {
    throw 'Dependencies or frontend assets missing. Run scripts/setup.ps1 first.'
}
# Use PHP directly so its upload settings also apply to the server process.
php -d upload_max_filesize=2048M -d post_max_size=2100M -d memory_limit=512M -d max_execution_time=600 -d max_input_time=600 -S 127.0.0.1:8000 -t public scripts/router.php
