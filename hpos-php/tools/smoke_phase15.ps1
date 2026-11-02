# HTTP smoke test for Phase 15 (stock near the machines): the Machine stock screen, issuing sacks to a machine and
# returning them, the Stock page column, the shop-floor card, and who may do what.
#   pwsh tools/smoke_phase15.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }

# machines running jobs with material at them come from the database test, so make sure there are some
& $php "$root\tools\machine_stock_test.php" | Out-Null

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/machine-stock', '/stock') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/machine-stock'
  Ok 'menu: Machine stock is in the Stock group' ($r.Content -match '>Machine stock<')
  Ok 'machine stock: levels with hours left, the issue and return forms, the store table and the latest movements' ($r.Content -match 'At the machines' -and $r.Content -match 'Hours left' -and $r.Content -match 'Issue to a machine' -and $r.Content -match 'Return to the store' -and $r.Content -match 'In the store' -and $r.Content -match 'Latest movements')
  Ok 'machine stock: the default lot is 50 sacks' ($r.Content -match 'name="bags" type="number"[^>]*value="50"' -or $r.Content -match 'value="50"')
  Ok 'machine stock: a machine that used material and ran out is flagged' ($r.Content -match 'Ran out' -or $r.Content -match 'Running low' -or $r.Content -match 'pill pill-ok">Used<')
  Ok 'stock page: the At machines column and the Machine stock button' ((Get-Page $s '/stock').Content -match 'At machines' -and (Get-Page $s '/stock').Content -match '/machine-stock')

  # a fresh machine and material
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "SM$tag"), @('name', "Stock Line $tag"), @('hourly_rate', '0'), @('status', 'IDLE')); $ws = Id $r.Location
  $r = Post $s '/items/new' '/items' @(@('code', "SMR$tag"), @('name', "Sack Material $tag"), @('category', 'RAW_MATERIAL'), @('uom', 'Kg'), @('hsn_code', '3904')); $item = Id $r.Location
  Post $s '/stock/receive' '/stock/receive' @(@('item_code', "SMR$tag"), @('bags', '40'), @('qty', '25')) | Out-Null
  $page = Get-Page $s '/machine-stock'
  Ok 'machine stock: the new material shows 1,000 in the store' ($page.Content -match "SMR$tag" -and $page.Content -match '1,000.0')
  $res = Post $s '/machine-stock' '/machine-stock/issue' @(@('workstation_id', ''), @('item_id', $item), @('bags', '20'), @('weight', '25'))
  Ok 'issue: no machine is refused' ((Flash-Of (Get-Page $s '/machine-stock').Content) -match 'machine and the material')
  Post $s '/machine-stock' '/machine-stock/issue' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '0'), @('weight', '25')) | Out-Null
  Ok 'issue: zero sacks is refused' ((Flash-Of (Get-Page $s '/machine-stock').Content) -match 'number of sacks')
  Post $s '/machine-stock' '/machine-stock/issue' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '20'), @('weight', 'abc')) | Out-Null
  Ok 'issue: a weight that is not a number is refused' ((Flash-Of (Get-Page $s '/machine-stock').Content) -match 'weight of one sack')
  Post $s '/machine-stock' '/machine-stock/issue' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '100'), @('weight', '25')) | Out-Null
  Ok 'issue: more than the store holds is refused, saying how much is there' ((Flash-Of (Get-Page $s '/machine-stock').Content) -match 'Only 1,000 Kg is in the store')
  $res = Post $s '/machine-stock' '/machine-stock/issue' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '20'), @('weight', '25'), @('note', 'first lot'))
  Ok 'issue: 20 sacks of 25 kg (500) go to the machine' ($res.StatusCode -eq 303)
  $page = (Get-Page $s '/machine-stock').Content
  Ok 'machine stock: 500 at the machine, 500 left in the store, and the movement is listed' ($page -match "SM$tag" -and $page -match '500.0' -and $page -match '20 sacks' -and $page -match 'first lot' -and (Flash-Of $page) -match 'Issued 500')
  Ok 'stock page: the At machines figure is 500 and the total is unchanged at 1,000' ((Get-Page $s '/stock?tier=RAW_MATERIAL').Content -match '(?s)SMR' + $tag + '.*?1,000.000.*?500.000')
  Post $s '/machine-stock' '/machine-stock/return' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '1'), @('weight', '900')) | Out-Null
  Ok 'return: more than the machine holds is refused' ((Flash-Of (Get-Page $s '/machine-stock').Content) -match 'only has 500')
  Post $s '/machine-stock' '/machine-stock/return' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '1'), @('weight', '100')) | Out-Null
  Ok 'return: 100 kg goes back (400 at the machine)' ((Flash-Of (Get-Page $s '/machine-stock').Content) -match 'Returned 100')

  # the shop floor
  $board = (Get-Page $s '/shop-floor').Content
  Ok 'shop floor: a machine card shows the material at it and the hours left' ($board -match 'class="chip' -and $board -match 'h left')

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('store', 'Store'), @('op', 'Operator'), @('acct', 'Accounts'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $store = (Sign-In "store.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Store: sees the screen with the forms, and can issue' ((Get-Page $store '/machine-stock').Content -match 'Issue to a machine' -and (Post $store '/machine-stock' '/machine-stock/issue' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '2'), @('weight', '25'))).StatusCode -eq 303)
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Operator: no Machine stock screen (403) but the shop floor works' ((Get-Page $op '/machine-stock').StatusCode -eq 403 -and (Get-Page $op '/shop-floor').StatusCode -eq 200)
  Ok 'Operator: issuing is 403' ((Post $op '/shop-floor' '/machine-stock/issue' @(@('workstation_id', $ws), @('item_id', $item), @('bags', '1'), @('weight', '25'))).StatusCode -eq 403)
  $acct = (Sign-In "acct.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Accounts: no Machine stock (403) and no menu entry' ((Get-Page $acct '/machine-stock').StatusCode -eq 403 -and (Get-Page $acct '/').Content -notmatch '>Machine stock<')
} finally { Stop-Dev }
Summary
