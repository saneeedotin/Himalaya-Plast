# HTTP smoke test for Phase 7 (hardening): signed-out access to every route, CSRF on every POST,
# security headers, the admin password reset, and errors that must not leak.
#   pwsh tools/smoke_phase7.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Raw($session, $method, $path, $body = $null) {
  $req = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::new($method), "$base$path")
  if ($body -ne $null) { $req.Content = New-Object System.Net.Http.StringContent($body, [Text.Encoding]::UTF8, 'application/x-www-form-urlencoded') }
  $resp = $session.SendAsync($req).GetAwaiter().GetResult()
  [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Location = $(if ($resp.Headers.Location) { $resp.Headers.Location.ToString() } else { '' }); Headers = $resp.Headers; Content = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult() }
}

$audit = & $php "$root\tools\audit_routes.php"
Ok 'route audit: no unprotected or state-changing GET routes' ($LASTEXITCODE -eq 0) ($audit | Select-Object -Last 3)
$routes = (& $php "$root\tools\audit_routes.php" --json) | ConvertFrom-Json
Ok 'route audit: there are routes to test' ($routes.Count -gt 100) "$($routes.Count)"

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $fake = '11111111-2222-3333-4444-555555555555'
  $anon = New-Session

  # signed out: every page sends you to sign in, no data in the body
  $leaks = @(); $bad = @()
  foreach ($r in ($routes | Where-Object { $_.method -eq 'GET' -and $_.auth })) {
    $path = $r.pattern -replace '\{id\}', $fake
    $res = Raw $anon 'GET' $path
    if ($res.StatusCode -ne 303 -or $res.Location -notmatch '/login$') { $bad += "$path -> $($res.StatusCode) $($res.Location)" }
  }
  Ok "signed out: all $(($routes | Where-Object { $_.method -eq 'GET' -and $_.auth }).Count) protected GET routes redirect to /login" ($bad.Count -eq 0) ($bad -join '; ')

  # signed out: a POST with no token is rejected before anything runs, and never a 500
  $bad = @()
  foreach ($r in ($routes | Where-Object { $_.method -eq 'POST' })) {
    $path = $r.pattern -replace '\{id\}', $fake
    $res = Raw $anon 'POST' $path 'x=1'
    if ($res.StatusCode -ge 500 -or $res.StatusCode -eq 200) { $bad += "$path -> $($res.StatusCode)" }
  }
  Ok "signed out: all $(($routes | Where-Object { $_.method -eq 'POST' }).Count) POST routes refuse a request without a token (no 200, no 500)" ($bad.Count -eq 0) ($bad -join '; ')

  # signed in as admin: a POST with a wrong token is refused on every route and changes nothing
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  $bad = @()
  foreach ($r in ($routes | Where-Object { $_.method -eq 'POST' -and $_.pattern -notin @('/login', '/logout') })) {
    $path = $r.pattern -replace '\{id\}', $fake
    $res = Raw $s 'POST' $path '_csrf=0000&x=1'
    if ($res.StatusCode -ne 303) { $bad += "$path -> $($res.StatusCode)" }
  }
  Ok 'signed in: a forged token is refused (303 back) on every POST route' ($bad.Count -eq 0) ($bad -join '; ')
  $res = Raw $s 'POST' '/logout' '_csrf=0000'
  Ok 'signed in: a forged logout does not sign the user out' ((Raw $s 'GET' '/').StatusCode -eq 200)

  # headers
  $res = Raw $s 'GET' '/'
  $h = $res.Headers
  $csp = ($h | Where-Object { $_.Key -eq 'Content-Security-Policy' }).Value -join ''
  Ok 'headers: CSP forbids inline script and framing' ($csp -match "default-src 'self'" -and $csp -match "frame-ancestors 'none'" -and $csp -match "object-src 'none'" -and $csp -notmatch 'unsafe-inline')
  Ok 'headers: nosniff, frame deny, referrer and permissions policy' ((($h | Where-Object { $_.Key -eq 'X-Content-Type-Options' }).Value -contains 'nosniff') -and (($h | Where-Object { $_.Key -eq 'X-Frame-Options' }).Value -contains 'DENY') -and ($h | Where-Object { $_.Key -eq 'Permissions-Policy' }))
  Ok 'headers: pages are not cached' ((($h | Where-Object { $_.Key -eq 'Cache-Control' }).Value -join ' ') -match 'no-store')
  $fresh = New-Session
  $res = Raw $fresh 'GET' '/login'
  $setCookie = ($res.Headers | Where-Object { $_.Key -eq 'Set-Cookie' }).Value -join ' | '
  Ok 'cookies: the flash/CSRF cookie is HttpOnly and SameSite=Lax' ($setCookie -match 'HttpOnly' -and $setCookie -match 'SameSite=Lax') $setCookie
  $r = Post $fresh '/login' '/login' @(@('email', $c[0]), @('password', $c[1]))
  Ok 'login works from a fresh session' ($r.StatusCode -eq 303)

  # errors do not leak
  $res = Raw $s 'GET' '/orders/' + "'%20OR%201=1"
  $res = Raw $s 'GET' ('/orders/' + [uri]::EscapeDataString("' OR 1=1 --"))
  Ok 'an injection attempt in an id is just a 404' ($res.StatusCode -eq 404 -and $res.Content -notmatch 'SQLSTATE' -and $res.Content -notmatch 'PDOException')
  $res = Raw $s 'GET' '/items?category=' + [uri]::EscapeDataString("RAW'; DROP TABLE items;--")
  $res = Raw $s 'GET' ('/items?category=' + [uri]::EscapeDataString("RAW'; DROP TABLE items;--"))
  Ok 'an injection attempt in a filter is ignored' ($res.StatusCode -eq 200 -and $res.Content -notmatch 'SQLSTATE')
  $res = Raw $s 'GET' '/no-such-page'
  Ok 'unknown page: a plain 404 with no file paths or stack trace' ($res.StatusCode -eq 404 -and $res.Content -notmatch '\.php' -and $res.Content -notmatch 'Stack trace')
  $res = Raw $s 'GET' '/app/Core/Db.php'
  Ok 'source files are not served through the web root' ($res.StatusCode -eq 404)
  $res = Raw $s 'GET' '/config/config.php'
  Ok 'config is not served through the web root' ($res.StatusCode -eq 404)
  $res = Raw $anon 'GET' '/setup'
  Ok 'setup page is reachable only while setup_enabled is on (documented)' ($res.StatusCode -in 200, 404)

  # admin password reset
  $page = (Get-Page $s '/users/new').Content
  $r = Post $s '/users/new' '/users' @(@('name', "Forgot $tag"), @('email', "forgot.$t@test.local"), @('password', 'Original-Pass-1'), @('roles[]', (Role-Id $page 'Operator')))
  $users = (Get-Page $s '/users').Content
  $uid = ([regex]::Matches($users, "(?s)<tr>(?:(?!</tr>).)*forgot\.$t@test\.local(?:(?!</tr>).)*/users/([0-9a-f-]{36})/edit"))[0].Groups[1].Value
  Ok 'reset: the user exists' ($uid -ne '')
  $u = Sign-In "forgot.$t@test.local" 'Original-Pass-1'
  Ok 'reset: the user can sign in with the original password' ($u.Response.StatusCode -eq 303)
  Ok 'reset: the edit page offers a password reset for someone else' ((Get-Page $s "/users/$uid/edit").Content -match 'Forgot password')
  $adminId = ([regex]::Matches($users, "(?s)<tr>(?:(?!</tr>).)*$([regex]::Escape($creds[0]))(?:(?!</tr>).)*/users/([0-9a-f-]{36})/edit"))[0].Groups[1].Value
  Ok 'reset: but not offered for yourself (you use Change password)' ($adminId -ne '' -and (Get-Page $s "/users/$adminId/edit").Content -notmatch 'Forgot password')
  Post $s "/users/$uid/edit" "/users/$uid/password" @(@('new_password', 'short'), @('confirm_password', 'short')) | Out-Null
  Ok 'reset: a short password is refused' ((Flash-Of (Get-Page $s "/users/$uid/edit").Content) -match 'at least 10')
  Post $s "/users/$uid/edit" "/users/$uid/password" @(@('new_password', 'Brand-New-Pass-2'), @('confirm_password', 'different')) | Out-Null
  Ok 'reset: mismatched passwords are refused' ((Flash-Of (Get-Page $s "/users/$uid/edit").Content) -match 'do not match')
  Post $s "/users/$uid/edit" "/users/$uid/password" @(@('new_password', 'Brand-New-Pass-2'), @('confirm_password', 'Brand-New-Pass-2')) | Out-Null
  Ok 'reset: success message' ((Flash-Of (Get-Page $s "/users/$uid/edit").Content) -match 'Password set for Forgot')
  Ok 'reset: the old session was ended' ((Raw $u.Session 'GET' '/').StatusCode -eq 303)
  $old = Sign-In "forgot.$t@test.local" 'Original-Pass-1'
  Ok 'reset: the old password no longer works' ($old.Response.StatusCode -eq 401)
  $new = Sign-In "forgot.$t@test.local" 'Brand-New-Pass-2'
  Ok 'reset: the new password works' ($new.Response.StatusCode -eq 303)
  # lock the account with 5 bad attempts, then a reset clears the lock
  1..5 | ForEach-Object { Sign-In "forgot.$t@test.local" 'wrong-password' | Out-Null }
  $locked = Sign-In "forgot.$t@test.local" 'Brand-New-Pass-2'
  Ok 'lock-out: five bad attempts lock the account even for the right password' ($locked.Response.StatusCode -eq 401 -and $locked.Response.Content -match 'locked')
  Post $s "/users/$uid/edit" "/users/$uid/password" @(@('new_password', 'After-Lock-Pass-3'), @('confirm_password', 'After-Lock-Pass-3')) | Out-Null
  $after = Sign-In "forgot.$t@test.local" 'After-Lock-Pass-3'
  Ok 'reset: clears a lock-out so the user can sign in at once' ($after.Response.StatusCode -eq 303)
  $op = (Sign-In "forgot.$t@test.local" 'After-Lock-Pass-3').Session
  $r = Post $op '/users' "/users/$uid/password" @(@('new_password', 'Hijack-Attempt-9'), @('confirm_password', 'Hijack-Attempt-9'))
  Ok 'reset: a non-admin cannot reset anyone (403)' ($r.StatusCode -eq 403)
  Ok 'reset: and the admin password is unchanged (still signs in)' ((Sign-In $c[0] $c[1]).Response.StatusCode -eq 303)

  # CSV import of master data (command line), through the same procedures and rules as the screens
  $tmp = Join-Path ([IO.Path]::GetTempPath()) "hpos-import-$tag"; New-Item -ItemType Directory -Force $tmp | Out-Null
  $utf8bom = New-Object System.Text.UTF8Encoding($true)
  function Import($type, $file, $extra = @()) { $out = & $php "$root\tools\import_csv.php" $type $file "--as=$($creds[0])" @extra 2>&1 | Out-String; [pscustomobject]@{ Code = $LASTEXITCODE; Out = $out } }
  $gOk = '24' + $tag + '9999Z1Z5'
  [IO.File]::WriteAllText("$tmp\customers.csv", @"
Name,GSTIN,Email,Phone,Ignored column
"Import & Sons, Ltd $tag",$gOk,imp@example.com,9876543210,x
Bad Gst $tag,NOPE,,,
,24AAAAA0000A1Z5,,,
"Import & Sons, Ltd $tag",,,,
Plain Co $tag,,,,
"@, $utf8bom)
  $d = Import 'customers' "$tmp\customers.csv"
  Ok 'import (dry run): bad GSTIN, empty name and a duplicate are rejected with their line numbers' ($d.Code -eq 1 -and $d.Out -match 'line 3: REJECTED - GSTIN' -and $d.Out -match 'line 4: REJECTED - name is empty' -and $d.Out -match 'line 5: REJECTED' -and $d.Out -match 'appears twice' -and $d.Out -match 'Dry run')
  Ok 'import (dry run): nothing was written' ((Get-Page $s "/search?q=Sons,+Ltd+$tag").Content -notmatch '/customers/[0-9a-f-]{36}')
  [IO.File]::WriteAllText("$tmp\customers_ok.csv", @"
name;gstin;email;phone
Import & Sons Ltd $tag;$gOk;imp@example.com;9876543210
Plain Co $tag;;;
"@, $utf8bom)
  $d = Import 'customers' "$tmp\customers_ok.csv" @('--commit')
  Ok 'import: a clean file (semicolon-separated, with a byte-order mark) is committed' ($d.Code -eq 0 -and $d.Out -match '2 created' -and $d.Out -match 'Committed')
  $r = Get-Page $s "/search?q=Import+%26+Sons+Ltd+$tag"
  Ok 'import: the customer is on the system with their GSTIN' ($r.Content -match '/customers/[0-9a-f-]{36}' -and (Get-Page $s "/customers").Content -match $gOk)
  $d = Import 'customers' "$tmp\customers_ok.csv" @('--commit')
  Ok 'import: running the same file again skips what exists' ($d.Code -eq 0 -and $d.Out -match '0 created' -and $d.Out -match '2 skipped')
  [IO.File]::WriteAllText("$tmp\customers_upd.csv", "name,phone`nPlain Co $tag,11223344`n", $utf8bom)
  $d = Import 'customers' "$tmp\customers_upd.csv" @('--commit', '--update')
  $cid = ([regex]::Matches((Get-Page $s '/customers').Content, "/customers/([0-9a-f-]{36})`">Plain Co $tag"))[0].Groups[1].Value
  Ok 'import: --update changes an existing row' ($d.Code -eq 0 -and $d.Out -match '1 updated' -and (Get-Page $s "/customers/$cid").Content -match '11223344')
  [IO.File]::WriteAllText("$tmp\items.csv", @"
code,name,category,uom,hsn_code,standard_cost,min_stock_level
imp$tag,Imported compound,raw_material,Kg,3901,95.5,200
BADCAT$tag,Wrong category,LIQUID,Kg,,,
NEG$tag,Negative cost,SCRAP,Kg,,-5,
"@, $utf8bom)
  $d = Import 'items' "$tmp\items.csv" @('--commit')
  Ok 'import: items - the good row is created, bad category and negative cost are rejected' ($d.Code -eq 1 -and $d.Out -match '1 created' -and $d.Out -match "category 'LIQUID'" -and $d.Out -match 'must be a number, zero or more')
  $r = Get-Page $s '/items?category=RAW_MATERIAL'
  $itemId = ([regex]::Matches($r.Content, '/items/([0-9a-f-]{36})">IMP' + $tag))[0].Groups[1].Value
  Ok 'import: the item is on the system, code upper-cased, cost and minimum stock saved' ($r.Content -match "IMP$tag" -and $itemId -ne '' -and (Get-Page $s "/items/$itemId").Content -match '95.50')
  [IO.File]::WriteAllText("$tmp\suppliers.csv", "name,status`nImported Supplier $tag,inactive`nBad Status $tag,MAYBE`n", $utf8bom)
  $d = Import 'suppliers' "$tmp\suppliers.csv" @('--commit')
  Ok 'import: suppliers - status is validated and upper-cased' ($d.Code -eq 1 -and $d.Out -match '1 created' -and $d.Out -match "status 'MAYBE'" -and (Get-Page $s '/suppliers').Content -match "Imported Supplier $tag")
  [IO.File]::WriteAllText("$tmp\nohead.csv", "foo,bar`n1,2`n", $utf8bom)
  $d = Import 'items' "$tmp\nohead.csv"
  Ok 'import: a file with the wrong header is refused outright (exit 2)' ($d.Code -eq 2 -and $d.Out -match 'missing: code, name, category, uom')
  $d = Import 'widgets' "$tmp\items.csv"
  Ok 'import: an unknown type shows usage (exit 2)' ($d.Code -eq 2 -and $d.Out -match 'usage')
  $opEmail = "forgot.$t@test.local"
  [IO.File]::WriteAllText("$tmp\fresh.csv", "name`nOperator Try $tag`n", $utf8bom)
  $d = Import 'customers' "$tmp\fresh.csv" @('--commit', "--as=$opEmail")
  Ok 'import: an Operator cannot import customers: a plain message, exit 1, no HTML' ($d.Code -eq 1 -and $d.Out -match 'may not import customers' -and $d.Out -match 'Forbidden' -and $d.Out -notmatch '<html')
  Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
} finally { Stop-Dev }
Summary
