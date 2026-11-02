# HTTP smoke test for Phase 14 (deadline advice): the Deadlines screen, the delivery check on an order page, the early
# warning and stuck-order alerts, and who may do what.
#   pwsh tools/smoke_phase14.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }

# open orders in every state of health come from the database test, so make sure there are some
& $php "$root\tools\deadline_test.php" | Out-Null

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/deadlines', '/deadlines?show=all') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/deadlines'
  Ok 'menu: Deadlines is under Sales' ($r.Content -match '>Deadlines<')
  Ok 'deadlines: counts and the explanation that it never changes a date' ($r.Content -match 'Will miss the date' -and $r.Content -match 'No slack' -and $r.Content -match 'never changes a delivery date')
  Ok 'deadlines: late orders are listed with the reason' ($r.Content -match 'pill pill-fail">Will miss the date<' -and $r.Content -match 'm a day')
  $all = Get-Page $s '/deadlines?show=all'
  Ok 'deadlines: the All tab also lists orders that are on track and made' ($all.Content -match 'pill pill-ok">On track<' -and $all.Content -match 'pill pill-ok">Made<')

  # an order page
  $late = [regex]::Match($r.Content, '/orders/([0-9a-f-]{36})"').Groups[1].Value
  $page = (Get-Page $s "/orders/$late").Content
  Ok 'order page: a Delivery check with the finish date, the earliest delivery and the reason' ($page -match 'Delivery check' -and $page -match 'Production can finish about' -and $page -match 'earliest realistic delivery' -and $page -match 'the delivery date is yours to set')
  Ok 'order page: a late order says how many days late' ($page -match 'days? late')
  $ok = [regex]::Match($all.Content, '(?s)pill pill-ok">On track<.*?').Success
  $onTrack = ([regex]::Matches($all.Content, '(?s)<tr>(?:(?!</tr>).)*?/orders/([0-9a-f-]{36})"(?:(?!</tr>).)*?pill pill-ok">On track<'))[0].Groups[1].Value
  $page = (Get-Page $s "/orders/$onTrack").Content
  Ok 'order page: an on-track order says how many days are spare' ($page -match 'days? spare')

  # alerts
  $n = (Get-Page $s '/notifications').Content
  Ok 'notifications: an order due soon, orders that will miss the date, and the stuck-order alerts' ($n -match 'is due soon' -and $n -match 'orders will miss their delivery date' -and $n -match 'waiting for the client' -and $n -match 'no job card yet')

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('sales', 'Sales'), @('qc', 'QC'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: sees the Deadlines screen' ((Get-Page $sales '/deadlines').StatusCode -eq 200)
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: no Deadlines (403) and no menu entry' ((Get-Page $qc '/deadlines').StatusCode -eq 403 -and (Get-Page $qc '/').Content -notmatch '>Deadlines<')
} finally { Stop-Dev }
Summary
