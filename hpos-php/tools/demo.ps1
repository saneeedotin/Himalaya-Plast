# Runs the demo: the app on http://127.0.0.1:8082 against the demo database named in config/demo-config.php
# (a copy of config.php that points at hpos_demo; git-ignored). Needs PHP on PATH. Stop it with Ctrl+C.
#   pwsh tools/demo.ps1 [-Port 8082]
param([int]$Port = 8082)
$root = Split-Path $PSScriptRoot -Parent
if (-not (Test-Path "$root\config\demo-config.php")) { throw "config\demo-config.php is missing. See docs/WALKTHROUGH.md, 'Set up the demo'." }
$env:HPOS_CONFIG = "$root\config\demo-config.php"
Write-Host "Demo on http://127.0.0.1:$Port  (Ctrl+C to stop)"
php -S "127.0.0.1:$Port" -t "$root\public" "$root\tools\dev-router.php"
