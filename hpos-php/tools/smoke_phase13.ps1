# HTTP smoke test for Phase 13 (buying advice): the advice screen, ticking lines into a draft purchase order, the item's
# buying details, and who may do what.
#   pwsh tools/smoke_phase13.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }

# materials with known usage and stock come from the database test, so make sure there are some
& $php "$root\tools\buying_test.php" | Out-Null

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/buying-advice', '/buying-advice?show=all', '/purchase-orders') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/buying-advice'
  Ok 'menu: Buying advice is in the Buying group' ($r.Content -match '>Buying advice<')
  Ok 'advice: counts, groups by supplier, columns' ($r.Content -match 'Order now' -and $r.Content -match 'Uses per day' -and $r.Content -match 'Days left' -and $r.Content -match 'Create draft purchase order')
  Ok 'advice: it says nothing is sent to the supplier' ($r.Content -match 'nothing is sent to the supplier')
  $all = Get-Page $s '/buying-advice?show=all'
  Ok 'advice: the All tab also lists materials that are fine' (([regex]::Matches($all.Content, 'pill pill-ok">OK<')).Count -ge 1)

  # ticking lines
  $row = [regex]::Match($r.Content, 'name="item\[\]" value="([0-9a-f-]{36})" checked')
  Ok 'advice: an Order-now material is ticked by default with a suggested quantity' ($row.Success -and $r.Content -match 'name="qty\[[0-9a-f-]{36}\]"')
  $item = $row.Groups[1].Value
  $sup = [regex]::Match($r.Content, '<option value="([0-9a-f-]{36})"').Groups[1].Value
  $before = (Get-Page $s '/purchase-orders').Content
  $res = Post $s '/buying-advice' '/buying-advice/po' @(@('supplier_id', ''), @('item[]', $item), @("qty[$item]", '10'))
  Ok 'order: no supplier is refused' ((Flash-Of (Get-Page $s '/buying-advice').Content) -match 'active supplier')
  $res = Post $s '/buying-advice' '/buying-advice/po' @(@('supplier_id', $sup), @('item[]', $item), @("qty[$item]", '0'))
  Ok 'order: a zero quantity is refused' ((Flash-Of (Get-Page $s '/buying-advice').Content) -match 'above zero')
  $res = Post $s '/buying-advice' '/buying-advice/po' @(@('supplier_id', $sup), @("qty[$item]", '10'))
  Ok 'order: nothing ticked is refused' ((Flash-Of (Get-Page $s '/buying-advice').Content) -match 'Tick at least one')
  $res = Post $s '/buying-advice' '/buying-advice/po' @(@('supplier_id', $sup), @('item[]', $item), @("qty[$item]", '125'))
  Ok 'order: a draft purchase order is created and opened' ($res.StatusCode -eq 303 -and $res.Location -match '/purchase-orders/[0-9a-f-]{36}')
  $po = Id $res.Location
  $page = (Get-Page $s "/purchase-orders/$po").Content
  Ok 'order: it is a Draft with the quantity typed' ($page -match 'Draft' -and $page -match '125' -and (Flash-Of $page) -match 'Draft purchase order created')
  $r = Get-Page $s '/buying-advice'
  Ok 'advice: that material now says it is on a draft and can be ticked no more' ($r.Content -match 'on draft BUY-PO' -and $r.Content -notmatch "value=`"$item`" checked")
  $res = Post $s '/buying-advice' '/buying-advice/po' @(@('supplier_id', $sup), @('item[]', $item), @("qty[$item]", '5'))
  Ok 'order: the same material cannot go on a second draft' ((Flash-Of (Get-Page $s '/buying-advice').Content) -match 'already on a draft')

  # the item's buying details
  $page = (Get-Page $s "/items/$item").Content
  Ok 'item: a Buying card with lead time, smallest order and usual supplier' ($page -match '<h2>Buying</h2>' -and $page -match 'name="lead_time_days"' -and $page -match 'name="reorder_qty"' -and $page -match 'name="preferred_supplier_id"')
  Post $s "/items/$item" "/items/$item/buying" @(@('lead_time_days', 'abc')) | Out-Null
  Ok 'item: a lead time that is not a number is refused' ((Flash-Of (Get-Page $s "/items/$item").Content) -match 'whole number of days')
  Post $s "/items/$item" "/items/$item/buying" @(@('lead_time_days', '400'), @('preferred_supplier_id', '')) | Out-Null
  Ok 'item: a lead time over a year is refused' ((Flash-Of (Get-Page $s "/items/$item").Content) -match '0 to 365')
  Post $s "/items/$item" "/items/$item/buying" @(@('lead_time_days', '12'), @('reorder_qty', '250'), @('preferred_supplier_id', $sup)) | Out-Null
  $page = (Get-Page $s "/items/$item").Content
  Ok 'item: saved and shown again' ($page -match 'value="12"' -and $page -match 'value="250"' -and (Flash-Of $page) -match 'Buying details saved')
  $fgPage = ([regex]::Matches((Get-Page $s '/items?category=FINISHED_GOODS').Content, '/items/([0-9a-f-]{36})"'))[0].Groups[1].Value
  if ($fgPage) { Ok 'item: a finished-goods item has no Buying card' ((Get-Page $s "/items/$fgPage").Content -notmatch '<h2>Buying</h2>') }

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('store', 'Store'), @('qc', 'QC'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $store = (Sign-In "store.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $store '/buying-advice'
  Ok 'Store: reads the advice but has no ticking and no create button' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'name="item\[\]"' -and $r.Content -notmatch 'Create draft purchase order')
  Ok 'Store: creating a purchase order from it is 403' ((Post $store '/buying-advice' '/buying-advice/po' @(@('supplier_id', $sup), @('item[]', $item), @("qty[$item]", '5'))).StatusCode -eq 403)
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: no buying advice (403) and no menu entry' ((Get-Page $qc '/buying-advice').StatusCode -eq 403 -and (Get-Page $qc '/').Content -notmatch '>Buying advice<')
} finally { Stop-Dev }
Summary
