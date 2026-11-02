# HTTP smoke test for Phase 2 (sales cockpit): orders, material check, confirm gate, shortage PO, roles.
#   pwsh tools/smoke_phase2.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Order-Pairs($customer, $date, $lines, $notes = '') {
  $p = @(@('customer_id', $customer), @('delivery_date', $date), @('approval_method', 'WHATSAPP'), @('notes', $notes))
  foreach ($l in $lines) { $p += , @('item_id[]', $l[0]); $p += , @('qty[]', $l[1]); $p += , @('rate[]', $l[2]) }
  , $p
}

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ })
  $admin = Sign-In (Get-Content "$root\config\dev-admin.txt")[0] (Get-Content "$root\config\dev-admin.txt")[1]
  $s = $admin.Session
  Ok 'admin login' ($admin.Response.StatusCode -eq 303)
  foreach ($p in '/orders', '/orders/new', '/orders?status=CONFIRMED') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }

  # master data through the Phase 1 screens
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE'))
  $r = Post $s '/customers/new' '/customers' @(, @('name', "Cust $tag"))
  $cust = Id $r.Location
  $r = Post $s '/items/new' '/items' @(@('code', "RM$tag"), @('name', "Compound $tag"), @('category', 'RAW_MATERIAL'), @('uom', 'Kg'), @('standard_cost', '90'))
  $rm = Id $r.Location
  $r = Post $s '/items/new' '/items' @(@('code', "FG$tag"), @('name', "Drip $tag"), @('category', 'FINISHED_GOODS'), @('uom', 'Meter'))
  $fg = Id $r.Location
  $r = Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $rm), @('qty_per_unit[]', '5'))
  Ok 'master data set up' ($r.StatusCode -eq 303 -and $cust -and $rm -and $fg)
  $future = (Get-Date).AddDays(10).ToString('yyyy-MM-dd')

  # form validation
  $r = Post $s '/orders/new' '/orders' (Order-Pairs '' $future @(, @($fg, '500', '40')))
  Ok 'order: no customer is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Choose a customer')
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust $future @())
  Ok 'order: no items is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'at least one item')
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust $future @(, @($fg, '0', '40')))
  Ok 'order: zero quantity is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'above zero')
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust '' @(, @($fg, '500', '40')))
  Ok 'order: missing delivery date is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'delivery date')

  # order A: 500 m needs 25.64 kg and there is no stock yet
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust $future @(, @($fg, '500', '40')) 'first order')
  Ok 'order A: create' ($r.StatusCode -eq 303 -and $r.Location -match '/orders/[0-9a-f-]{36}') $r.Location
  $A = Id $r.Location
  $r = Get-Page $s "/orders/$A"
  $orderNo = [regex]::Match($r.Content, 'SO-\d{4}-\d{5}').Value
  Ok 'order A: detail shows total, notes and status' ($r.Content -match '20,000.00' -and $r.Content -match 'first order' -and $r.Content -match 'Draft')
  Ok 'order A: material check says Deficit, no confirm form' ($r.Content -match 'Deficit' -and $r.Content -match 'not enough raw material' -and $r.Content -notmatch 'Confirm order')
  Ok 'order A: shortage PO button is offered' ($r.Content -match 'Create purchase order for the shortage')

  $r = Post $s "/orders/$A" "/orders/$A/confirm" @(@('approval_method', 'EMAIL'))
  $r = Get-Page $s "/orders/$A"
  Ok 'OVERSELL: forced confirm is refused by the database' ($r.Content -match 'Strict Hard Gate' -and $r.Content -match 'Draft')

  $r = Post $s "/orders/$A" "/orders/$A/shortage-po" @()
  Ok 'shortage PO: created' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/orders/$A"
  Ok 'shortage PO: message names the draft PO' ($r.Content -match 'Draft purchase order BUY-PO-\d{4}-\d{5}')
  $pos = Db-Call 'sp_po_list' @('@admin')
  $po = $pos[0] | Where-Object { $_.notes -like "*$orderNo*" } | Select-Object -First 1
  Ok 'shortage PO: exists in the database, covers 26 kg' ($po -and [math]::Round([double]$po.total_amount, 0) -gt 0) "$($po.total_amount)"
  Db-Call 'sp_po_receive' @('@admin', $po.id) | Out-Null

  $r = Get-Page $s "/orders/$A"
  Ok 'order A: after receiving stock the check is Available and confirm is offered' ($r.Content -match 'Available' -and $r.Content -notmatch 'not enough raw material' -and $r.Content -match 'Confirm order')
  $r = Post $s "/orders/$A" "/orders/$A/confirm" @(@('approval_method', 'EMAIL'), @('notes', 'ok by mail'))
  Ok 'order A: confirm' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/orders/$A"
  Ok 'order A: now Confirmed, with work order and reservation' ($r.Content -match 'Confirmed' -and $r.Content -match 'PROD-WO-\d{4}-\d{5}' -and $r.Content -match 'Reserved material')
  Ok 'order A: no Edit or Confirm any more, Cancel is offered' ($r.Content -notmatch '/edit"' -and $r.Content -notmatch 'Confirm order' -and $r.Content -match 'Cancel order')
  $r = Get-Page $s "/orders/$A/edit"
  Ok 'order A: edit is refused once confirmed' ($r.StatusCode -eq 400 -and $r.Content -match 'cannot be edited')
  $r = Post $s "/orders/$A" "/orders/$A/delete" @()
  $r = Get-Page $s "/orders/$A"
  Ok 'order A: delete is refused once confirmed' ($r.StatusCode -eq 200 -and $r.Content -match 'Only draft or cancelled')

  # order B: only 0.36 kg is left, so 500 m is refused, and so is 10 m; 5 m fits
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust $future @(, @($fg, '500', '40')))
  $B = Id $r.Location
  $r = Get-Page $s "/orders/$B"
  Ok 'order B: Deficit because order A holds the stock' ($r.Content -match 'Deficit' -and $r.Content -match 'Reserved for others')
  $r = Get-Page $s "/orders/$B/edit"
  Ok 'order B: edit form is pre-filled' ($r.StatusCode -eq 200 -and $r.Content -match 'value="500"' -and $r.Content -match 'value="40"' -and $r.Content -notmatch '500\.000')
  $r = Post $s "/orders/$B/edit" "/orders/$B" (Order-Pairs $cust $future @(, @($fg, '5', '40')))
  Ok 'order B: edit to 5 m' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/orders/$B"
  Ok 'order B: total follows the edit' ($r.Content -match '200.00' -and $r.Content -match 'Confirm order')
  $r = Post $s "/orders/$B" "/orders/$B/confirm" @(@('approval_method', 'PHONE'))
  Ok 'order B: confirm now passes the gate' ($r.StatusCode -eq 303)
  $r = Get-Page $s '/orders?status=CONFIRMED'
  Ok 'list: status filter shows both confirmed orders' ($r.Content -match $orderNo -and ([regex]::Matches($r.Content, 'SO-\d{4}-\d{5}').Count -ge 2))

  # order C: proforma, cancel, delete
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust $future @(, @($fg, '10', '40')))
  $C = Id $r.Location
  $r = Post $s "/orders/$C" "/orders/$C/status" @(@('status', 'PROFORMA_SENT'), @('proforma_ref', "PF-$tag"))
  $r = Get-Page $s "/orders/$C"
  Ok 'order C: proforma sent with reference' ($r.Content -match 'Proforma sent' -and $r.Content -match "PF-$tag")
  $r = Post $s "/orders/$C" "/orders/$C/status" @(@('status', 'DISPATCHED'))
  $r = Get-Page $s "/orders/$C"
  Ok 'order C: arbitrary status is refused' ($r.Content -match 'cannot be set here' -and $r.Content -match 'Proforma sent')
  $r = Post $s "/orders/$C" "/orders/$C/status" @(@('status', 'CANCELLED'))
  $r = Get-Page $s "/orders/$C"
  Ok 'order C: cancelled' ($r.Content -match 'Cancelled')
  $r = Post $s "/orders/$C" "/orders/$C/delete" @()
  Ok 'order C: delete' ($r.StatusCode -eq 303 -and $r.Location -match '/orders$')
  $r = Get-Page $s "/orders/$C"
  Ok 'order C: gone (404)' ($r.StatusCode -eq 404)

  # overdue
  $past = (Get-Date).AddDays(-3).ToString('yyyy-MM-dd')
  $r = Post $s '/orders/new' '/orders' (Order-Pairs $cust $past @(, @($fg, '10', '40')))
  $D = Id $r.Location
  $r = Get-Page $s '/orders'
  Ok 'list: an order past its delivery date is flagged Overdue' ($r.Content -match 'Overdue')

  # roles: Production can read orders only; Sales can create and confirm but not delete or raise POs
  $page = (Get-Page $s '/users/new').Content
  $prodRole = Role-Id $page 'Production'
  $salesRole = Role-Id $page 'Sales'
  $opRole = Role-Id $page 'Operator'
  foreach ($u in @(@('prod', $prodRole), @('sales', $salesRole), @('op', $opRole))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$($tag.ToLower())@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', $u[1])) | Out-Null
  }
  $prod = Sign-In "prod.$($tag.ToLower())@test.local" 'Role-Test-Pass-1'
  $r = Get-Page $prod.Session '/orders'
  Ok 'Production: can read the order list, no New order button' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'New order')
  $r = Get-Page $prod.Session "/orders/$D"
  Ok 'Production: can open an order, sees no Edit, Confirm or Delete' ($r.StatusCode -eq 200 -and $r.Content -notmatch '/edit"' -and $r.Content -notmatch 'Confirm order' -and $r.Content -notmatch '>Delete<')
  $r = Get-Page $prod.Session '/orders/new'
  Ok 'Production: new order page is 403' ($r.StatusCode -eq 403)
  $r = Post $prod.Session "/orders/$D" "/orders/$D/confirm" @(@('approval_method', 'EMAIL'))
  Ok 'Production: confirm is 403' ($r.StatusCode -eq 403)

  $op = Sign-In "op.$($tag.ToLower())@test.local" 'Role-Test-Pass-1'
  $r = Get-Page $op.Session '/orders'
  Ok 'Operator: orders are 403 and not in the menu' ($r.StatusCode -eq 403 -and $r.Content -notmatch '>Orders<')

  $sales = Sign-In "sales.$($tag.ToLower())@test.local" 'Role-Test-Pass-1'
  $r = Get-Page $sales.Session '/orders/new'
  Ok 'Sales: can open the new order form with customers and items' ($r.StatusCode -eq 200 -and $r.Content -match "Cust $tag" -and $r.Content -match "FG$tag")
  $r = Post $sales.Session '/orders/new' '/orders' (Order-Pairs $cust $future @(, @($fg, '10', '40')))
  Ok 'Sales: can create an order' ($r.StatusCode -eq 303)
  $E = Id $r.Location
  $r = Get-Page $sales.Session "/orders/$E"
  Ok 'Sales: sees Edit but no Delete and no shortage PO button' ($r.Content -match '/edit"' -and $r.Content -notmatch '>Delete<' -and $r.Content -notmatch 'Create purchase order')
  $r = Post $sales.Session "/orders/$E" "/orders/$E/delete" @()
  Ok 'Sales: delete is 403' ($r.StatusCode -eq 403)
  $r = Post $sales.Session "/orders/$E" "/orders/$E/shortage-po" @()
  Ok 'Sales: shortage PO is 403' ($r.StatusCode -eq 403)
  $r = Get-Page $s '/orders/not-a-real-id'
  Ok 'unknown order id is a 404' ($r.StatusCode -eq 404)
} finally { Stop-Dev }
Summary
