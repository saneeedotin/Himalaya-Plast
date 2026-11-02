# HTTP smoke test for Phase 17 (What to do today): the dashboard list, a new lead showing up on it, every line's link
# opening, and each role seeing only the lines for screens it may open.
#   pwsh tools/smoke_phase17.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Today-Block($html) { $m = [regex]::Match($html, '(?s)<h2>What to do today</h2>(.*?)<p class="muted">These are suggestions'); if ($m.Success) { $m.Groups[1].Value } else { '' } }

# the lines for late orders, material, machines and quality come from the database test, so make sure there are some
& $php "$root\tools\today_test.php" | Out-Null

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  $r = Get-Page $s '/'
  Ok 'home: opens and has the What to do today section' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong' -and $r.Content -match '<h2>What to do today</h2>')
  $block = Today-Block $r.Content
  Ok 'home: the list has lines with a coloured dot, a headline link and the names' ($block -match 'class="today-list"' -and $block -match 'class="bullet bullet-bad"' -and $block -match '<a href="[^"]+">\d+ ')
  Ok 'home: it says nothing is ordered or sent until a person does it' ($r.Content -match 'Nothing is ordered or sent until a person does it')
  Ok 'home: the bad lines come before the warn lines, then the info lines' ((Today-Block $r.Content) -match '(?s)bullet-bad.*bullet-warn' -and (Today-Block $r.Content) -notmatch '(?s)bullet-info.*bullet-warn')

  # a lead whose follow-up date has come becomes a line, naming it
  Post $s '/customer-leads/new' '/customer-leads' @(@('company_name', "Smoke Lead $tag"), @('contact_name', 'Ravi'), @('phone', '9811111111'), @('status', 'NEW'), @('next_follow_up', '2001-01-01T10:00')) | Out-Null
  $block = Today-Block (Get-Page $s '/').Content
  Ok 'home: a lead that is waiting for a follow-up is a line and is named' ($block -match 'lead(s are| is) waiting for a follow-up' -and $block -match "Smoke Lead $tag")

  # every link in the list opens
  $links = [regex]::Matches((Today-Block (Get-Page $s '/').Content), '<a href="([^"]+)">') | ForEach-Object { [System.Net.WebUtility]::HtmlDecode($_.Groups[1].Value) } | Sort-Object -Unique
  $bad = @(); foreach ($l in $links) { $p = $l -replace '^https?://[^/]+', ''; if ((Get-Page $s $p).StatusCode -ne 200) { $bad += $p } }
  Ok "home: all $($links.Count) links in the list open" ($links.Count -ge 3 -and $bad.Count -eq 0) ($bad -join ', ')

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('qc', 'QC'), @('op', 'Operator'), @('sales', 'Sales'), @('store', 'Store'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  $b = Today-Block (Get-Page $qc '/').Content
  Ok 'QC: sees quality lines and no customer, order or buying lines' ($b -match 'QC|machine' -and $b -notmatch 'reorder' -and $b -notmatch 'lead' -and $b -notmatch 'material to order' -and $b -notmatch 'delivery date')
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $op '/'
  $b = Today-Block $r.Content
  Ok 'Operator: the page opens, with no customer, lead, buying or quality lines' ($r.StatusCode -eq 200 -and $b -notmatch 'reorder' -and $b -notmatch 'lead' -and $b -notmatch 'material to order' -and $b -notmatch 'QC reading')
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  $b = Today-Block (Get-Page $sales '/').Content
  Ok 'Sales: sees the lead and customer lines, and no quality lines' ($b -match "Smoke Lead $tag" -and $b -notmatch 'QC reading' -and $b -notmatch 'QC reading')
  $store = (Sign-In "store.$t@test.local" 'Role-Test-Pass-1').Session
  $b = Today-Block (Get-Page $store '/').Content
  Ok 'Store: sees material lines and no lead lines' ($b -match 'material' -and $b -notmatch "Smoke Lead $tag")
} finally { Stop-Dev }
Summary
