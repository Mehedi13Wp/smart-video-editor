$ErrorActionPreference = 'Stop'
$remixRoot = Split-Path $PSScriptRoot -Parent
$remixPhp = (Get-Command php -ErrorAction Stop).Source
# Fixed CLI arguments, no uploaded filenames or request data. Never starts or changes the HTTP server.
$remixProcess = Start-Process -FilePath $remixPhp -ArgumentList @('artisan', 'remix:work') -WorkingDirectory $remixRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $remixRoot 'storage/logs/remix-worker.log') -RedirectStandardError (Join-Path $remixRoot 'storage/logs/remix-worker-error.log')
Write-Output "Started local remix worker (PID $($remixProcess.Id)). Check storage/logs/remix-worker.log."
