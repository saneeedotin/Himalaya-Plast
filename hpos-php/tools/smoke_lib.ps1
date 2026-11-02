# Shared helpers for the HTTP smoke tests (dot-sourced by smoke_phase*.ps1).
# Needs PHP on PATH, PowerShell 7, a *_scratch database and config/dev-admin.txt
# (line 1 admin email, line 2 password). Tests write rows with random codes.
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$php  = (Get-Command php -ErrorAction Stop).Source
$creds = Get-Content "$root\config\dev-admin.txt"
$base = 'http://127.0.0.1:8099'
$global:pass = 0; $global:fail = 0
function Ok($label, [bool]$cond, $detail = '') { if ($cond) { $global:pass++; Write-Host "[PASS] $label" } else { $global:fail++; Write-Host "[FAIL] $label  ($detail)" } }

function New-Session { $h = New-Object System.Net.Http.HttpClientHandler; $h.AllowAutoRedirect = $false; $h.UseCookies = $true; $h.CookieContainer = New-Object System.Net.CookieContainer; New-Object System.Net.Http.HttpClient $h }
function Wrap($resp) { [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Content = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult(); Location = $(if ($resp.Headers.Location) { $resp.Headers.Location.ToString() } else { '' }) } }
function Send($s, $method, $path, $pairs = @()) {
  if ($method -eq 'POST') {
    if ($pairs.Count -gt 0 -and $pairs[0] -is [string]) { $pairs = @(, $pairs) }   # a lone @('name','value') arrives flattened
    $form = Wrap ($s.GetAsync("$base$($global:formPage)").GetAwaiter().GetResult())
    $token = [regex]::Match($form.Content, 'name="_csrf" value="([a-f0-9]+)"').Groups[1].Value
    $body = (@(, @('_csrf', $token)) + $pairs | ForEach-Object { [uri]::EscapeDataString($_[0]) + '=' + [uri]::EscapeDataString([string]$_[1]) }) -join '&'
    $content = New-Object System.Net.Http.StringContent($body, [Text.Encoding]::UTF8, 'application/x-www-form-urlencoded')
    $req = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::Post, "$base$path")
    $req.Content = $content
    $req.Headers.Referrer = [uri]"$base$($global:formPage)"
    return Wrap ($s.SendAsync($req).GetAwaiter().GetResult())
  }
  Wrap ($s.GetAsync("$base$path").GetAwaiter().GetResult())
}
function Get-Page($s, $path) { $global:formPage = $path; Send $s 'GET' $path }
function Post($s, $formPage, $path, $pairs) { $global:formPage = $formPage; Send $s 'POST' $path $pairs }
function Loc($r) { $r.Location }
function Id($loc) { ($loc -split '/')[-1] }


function Start-Dev {
  # A server left over from an earlier run would answer instead of ours, with the wrong config and account.
  if (Get-NetTCPConnection -LocalPort 8099 -State Listen -ErrorAction SilentlyContinue) { throw 'Port 8099 is already in use. Stop the other PHP server first, or the tests would talk to it.' }
  $env:HPOS_PUSH_DRY_RUN = '1'   # a test run must never send a real push
  $global:srv = Start-Process $php -ArgumentList '-S', '127.0.0.1:8099', '-t', "$root\public", "$root\tools\dev-router.php" -PassThru -WindowStyle Hidden; Start-Sleep 2 }
function Stop-Dev { Stop-Process -Id $global:srv.Id -ErrorAction SilentlyContinue }
function Sign-In($email, $password) { $s = New-Session; $r = Post $s '/login' '/login' @(@('email', $email), @('password', $password)); [pscustomobject]@{ Session = $s; Response = $r } }
function Summary { Write-Host "`n$global:pass passed, $global:fail failed"; exit ([int]($global:fail -gt 0)) }

# Calls a stored procedure through tools/dbcall.php; returns the result sets as objects.
function Db-Call($proc, $argList) { $json = $argList | ConvertTo-Json -Compress -AsArray; $out = & $php "$root\tools\dbcall.php" $proc $json; if ($LASTEXITCODE -ne 0) { throw "dbcall $proc failed" }; $out | ConvertFrom-Json -NoEnumerate }   # -NoEnumerate: one result set with one row must stay a list of lists
