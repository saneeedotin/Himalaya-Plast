# Runs every check in order and prints one summary. Use a scratch database (name ends in _scratch or _test).
#
#   pwsh tools/run_all_tests.ps1
#   pwsh tools/run_all_tests.ps1 -ConfigPath C:\path\to\config-for-hpos_app.php
#
# -ConfigPath runs the whole app as another database account, for example the real EXECUTE-only one,
# to prove the app needs nothing more. The database scenario test reads tables directly, so it is
# skipped in that mode.
param([string]$ConfigPath = '')

$root = Split-Path $PSScriptRoot -Parent
$php  = (Get-Command php -ErrorAction Stop).Source
if ($ConfigPath -ne '') { $env:HPOS_CONFIG = $ConfigPath; Write-Host "Running as the database account in $ConfigPath" }
$env:HPOS_PUSH_DRY_RUN = '1'   # the web push sender pretends the push service accepted a message; nothing leaves this machine

$results = @()
function Run($name, [scriptblock]$block) {
  Write-Host "`n=== $name"
  $out = & $block *>&1 | Out-String   # *>&1 also captures Write-Host from the smoke tests
  $code = $LASTEXITCODE
  $summary = ($out -split "`n" | Where-Object { $_ -match '^\d+ passed|passed, \d+ failed|No problems|No syntax|can run HPOS' } | Select-Object -Last 1)
  $failures = ($out -split "`n" | Where-Object { $_ -match '^\[FAIL\]' })
  $failures | ForEach-Object { Write-Host $_ }
  Write-Host ("{0}  {1}" -f $(if ($code -eq 0) { 'OK  ' } else { 'FAIL' }), $summary)
  $script:results += [pscustomobject]@{ Name = $name; Ok = ($code -eq 0); Summary = "$summary".Trim() }
}

Run 'PHP syntax' {
  $bad = 0
  Get-ChildItem "$root" -Recurse -Filter *.php | ForEach-Object { $o = & $php -l $_.FullName 2>&1; if ($o -notmatch 'No syntax errors') { $o; $bad++ } }
  'No syntax errors in any file'
  cmd /c "exit $([int]($bad -gt 0))"   # sets the exit code the runner reads (a bare "exit" would end this whole script)
}
Run 'Route audit' { & $php "$root\tools\audit_routes.php" | Select-Object -Last 3 }
Run 'Server check' { & $php "$root\tools\setup.php" | Select-String -Pattern 'FAIL|can run' }
Run 'Web Push cryptography' { & $php "$root\tools\push_selftest.php" }
if ($ConfigPath -eq '') {
  Run 'Database scenario (stored procedures)' { & $php "$root\tools\scenario_test.php" }
  Run 'Books ledger (stored procedures)' { & $php "$root\tools\books_test.php" }
  Run 'QC sheets (stored procedures)' { & $php "$root\tools\qc_sheets_test.php" }
  Run 'Job checklist (stored procedures)' { & $php "$root\tools\checklist_test.php" }
  Run 'Planning board (stored procedures)' { & $php "$root\tools\planning_test.php" }
  Run 'Repeat orders and leads (stored procedures)' { & $php "$root\tools\insight_test.php" }
  Run 'Buying advice (stored procedures)' { & $php "$root\tools\buying_test.php" }
  Run 'Deadline advice (stored procedures)' { & $php "$root\tools\deadline_test.php" }
  Run 'Machine stock (stored procedures)' { & $php "$root\tools\machine_stock_test.php" }
  Run 'What to do today (stored procedures)' { & $php "$root\tools\today_test.php" }
}
foreach ($n in 1..17) { Run "HTTP smoke test, phase $n" { & "$root\tools\smoke_phase$n.ps1" } }

Write-Host "`n================ SUMMARY"
$results | ForEach-Object { Write-Host ("{0}  {1,-40} {2}" -f $(if ($_.Ok) { 'OK  ' } else { 'FAIL' }), $_.Name, $_.Summary) }
Remove-Item Env:HPOS_PUSH_DRY_RUN -ErrorAction SilentlyContinue
if ($ConfigPath -ne '') { Remove-Item Env:HPOS_CONFIG }
if ($results | Where-Object { -not $_.Ok }) { exit 1 }
