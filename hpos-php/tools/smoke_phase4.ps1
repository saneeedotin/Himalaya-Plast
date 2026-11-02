# HTTP smoke test for Phase 4 (buying, quality, dispatch): purchase orders, goods receipts, inspections,
# carton packing and labels, the dock-gate scan, and who may do what.
#   pwsh tools/smoke_phase4.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Po-Pairs($supplier, $lines) {
  $p = @(@('supplier_id', $supplier), @('notes', 'smoke'))
  foreach ($l in $lines) { $p += , @('item_id[]', $l[0]); $p += , @('qty[]', $l[1]); $p += , @('rate[]', $l[2]) }
  , $p
}

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/purchase-orders', '/purchase-orders/new', '/receipts', '/quality', '/quality/new', '/dispatch', '/dispatch/new', '/scan/dispatch') {
    $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)"
  }

  # master data
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE')); $sup = Id $r.Location
  $r = Post $s '/customers/new' '/customers' @(, @('name', "Cust $tag")); $cust = Id $r.Location
  $ids = @{}
  foreach ($def in @(@('rm', "RM$tag", 'RAW_MATERIAL', 'Kg', ''), @('rs', "RS$tag", 'RAW_MATERIAL', 'Kg', ''), @('fg', "FG$tag", 'FINISHED_GOODS', 'Meter', '["Pin size","Width"]'))) {
    $r = Post $s '/items/new' '/items' @(@('code', $def[1]), @('name', "Item $($def[1])"), @('category', $def[2]), @('uom', $def[3]), @('qc_template', $def[4])); $ids[$def[0]] = Id $r.Location
  }
  Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $ids.fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $ids.rm), @('qty_per_unit[]', '5')) | Out-Null
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "WS$tag"), @('name', "Line $tag"), @('hourly_rate', '400'), @('status', 'IDLE')); $ws = Id $r.Location
  function Stock($itemId) { $h = (Get-Page $s "/items/$itemId").Content; [regex]::Match($h, '<dt>In stock</dt><dd>([0-9,.\-]+)').Groups[1].Value }

  # purchase orders
  $r = Post $s '/purchase-orders/new' '/purchase-orders' (Po-Pairs '' @(, @($ids.rm, '30', '100')))
  Ok 'PO: no supplier is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Choose a supplier')
  $r = Post $s '/purchase-orders/new' '/purchase-orders' (Po-Pairs $sup @())
  Ok 'PO: no items is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'at least one item')
  $r = Post $s '/purchase-orders/new' '/purchase-orders' (Po-Pairs $sup @(, @($ids.rm, '0', '100')))
  Ok 'PO: zero quantity is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'above zero')
  $r = Post $s '/purchase-orders/new' '/purchase-orders' (Po-Pairs $sup @(, @($ids.rm, '30', '100')))
  Ok 'PO 1: created as a draft' ($r.StatusCode -eq 303 -and $r.Location -match '/purchase-orders/[0-9a-f-]{36}'); $po1 = Id $r.Location
  $r = Get-Page $s "/purchase-orders/$po1"
  Ok 'PO 1: detail shows Draft and the total' ($r.Content -match 'Draft' -and $r.Content -match '3,000.00' -and $r.Content -match 'Submit to supplier')
  $r = Get-Page $s "/purchase-orders/$po1/edit"
  Ok 'PO 1: edit form is pre-filled' ($r.StatusCode -eq 200 -and $r.Content -match 'value="30"' -and $r.Content -match 'value="100"')
  $r = Post $s "/purchase-orders/$po1/edit" "/purchase-orders/$po1" (Po-Pairs $sup @(, @($ids.rm, '40', '100')))
  Ok 'PO 1: edit to 40 kg' ($r.StatusCode -eq 303 -and (Get-Page $s "/purchase-orders/$po1").Content -match '4,000.00')
  $r = Post $s "/purchase-orders/$po1" "/purchase-orders/$po1/status" @(, @('status', 'SUBMITTED'))
  Ok 'PO 1: submitted' ($r.StatusCode -eq 303 -and (Get-Page $s "/purchase-orders/$po1").Content -match 'Submitted')
  $r = Get-Page $s "/purchase-orders/$po1/edit"
  Ok 'PO 1: a submitted order cannot be edited' ($r.StatusCode -eq 400 -and $r.Content -match 'Only draft')
  Post $s "/purchase-orders/$po1" "/purchase-orders/$po1/status" @(, @('status', 'RECEIVED')) | Out-Null
  Ok 'PO 1: RECEIVED cannot be set by hand' ((Flash-Of (Get-Page $s "/purchase-orders/$po1").Content) -match 'cannot be set here')

  # goods receipt: nothing moves until it is submitted
  $r = Post $s "/purchase-orders/$po1" "/purchase-orders/$po1/receipt" @()
  Ok 'GRN: drafted from the order' ($r.StatusCode -eq 303 -and $r.Location -match '/receipts/[0-9a-f-]{36}'); $grn = Id $r.Location
  $r = Get-Page $s "/receipts/$grn"
  Ok 'GRN: draft shows the line and a Submit button' ($r.Content -match 'Draft' -and $r.Content -match "RM$tag" -and $r.Content -match 'Submit and book stock')
  Ok 'GRN: stock is still 0 before it is submitted' ((Stock $ids.rm) -match '^0')
  $r = Post $s "/receipts/$grn" "/receipts/$grn/submit" @()
  Ok 'GRN: submit' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/receipts/$grn"
  Ok 'GRN: submitted with a batch number' ($r.Content -match 'Submitted' -and $r.Content -match 'BATCH-GRN-\d{4}-\d{5}-01')
  Ok 'GRN: stock is now 40' ((Stock $ids.rm) -match '^40')
  Ok 'GRN: the purchase order is Closed' ((Get-Page $s "/purchase-orders/$po1").Content -match 'Closed')
  Post $s "/receipts/$grn" "/receipts/$grn/submit" @() | Out-Null
  Ok 'GRN: submitting twice is refused' ((Flash-Of (Get-Page $s "/receipts/$grn").Content) -match 'already submitted')
  Ok 'GRN: listed' ((Get-Page $s '/receipts').Content -match 'GRN-\d{4}-\d{5}')

  # receive everything in one go (scrap-test material)
  $r = Post $s '/purchase-orders/new' '/purchase-orders' (Po-Pairs $sup @(, @($ids.rs, '10', '50'))); $po2 = Id $r.Location
  $r = Post $s "/purchase-orders/$po2" "/purchase-orders/$po2/receive" @()
  Ok 'PO 2: receive everything' ($r.StatusCode -eq 303 -and (Get-Page $s "/purchase-orders/$po2").Content -match 'Received')
  Ok 'PO 2: stock is 10' ((Stock $ids.rs) -match '^10')
  Post $s "/purchase-orders/$po2" "/purchase-orders/$po2/receive" @() | Out-Null
  Ok 'PO 2: receiving twice is refused' ((Flash-Of (Get-Page $s "/purchase-orders/$po2").Content) -match 'already received')
  $r = Post $s '/purchase-orders/new' '/purchase-orders' (Po-Pairs $sup @(, @($ids.rm, '5', '100'))); $po3 = Id $r.Location
  $r = Post $s "/purchase-orders/$po3" "/purchase-orders/$po3/delete" @()
  Ok 'PO 3: a draft can be deleted' ($r.StatusCode -eq 303 -and (Get-Page $s "/purchase-orders/$po3").StatusCode -eq 404)
  $r = Get-Page $s '/purchase-orders?status=CLOSED'
  Ok 'PO list: status filter' ($r.StatusCode -eq 200 -and $r.Content -match 'BUY-PO-')

  # make finished goods: order 100 m, confirm, job card, produce
  $future = (Get-Date).AddDays(10).ToString('yyyy-MM-dd')
  $r = Post $s '/orders/new' '/orders' @(@('customer_id', $cust), @('delivery_date', $future), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '100'), @('rate[]', '40'))
  $A = Id $r.Location
  Post $s "/orders/$A" "/orders/$A/confirm" @(, @('approval_method', 'EMAIL')) | Out-Null
  $orderNo = [regex]::Match((Get-Page $s "/orders/$A").Content, 'SO-\d{4}-\d{5}').Value
  $woId = ([regex]::Matches((Get-Page $s '/work-orders').Content, "(?s)/work-orders/([0-9a-f-]{36})`"[^<]*</a></td>(?:(?!</tr>).)*$orderNo"))[0].Groups[1].Value
  Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $ws), @('assign_me', '1')) | Out-Null
  $board = (Get-Page $s '/shop-floor').Content
  $card = ($board -split '<section class="card floor-card">') | Where-Object { $_ -match "WS$tag" } | Select-Object -First 1
  $jc = [regex]::Match($card, '/job-cards/([0-9a-f-]{36})/status').Groups[1].Value
  Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor')) | Out-Null
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '100'), @('back', '/shop-floor')) | Out-Null
  Ok 'finished goods: 100 m produced' ((Stock $ids.fg) -match '^100')
  $fgBatch = [regex]::Match((Get-Page $s "/work-orders/$woId").Content, 'BATCH-FG-\d{4}-\d{5}').Value
  Ok 'finished goods: the batch exists' ($fgBatch -ne '')

  # quality
  $r = Get-Page $s "/orders/$A"
  Ok 'order detail links to the inspection for the batch' ($r.Content -match "/quality/new\?batch=$fgBatch")
  $r = Get-Page $s "/quality/new?batch=$fgBatch"
  Ok 'inspection form: batch preselected, measurements pre-filled from the QC template' ($r.Content -match "value=`"$fgBatch`" selected" -and $r.Content -match 'value="Pin size"' -and $r.Content -match 'value="Width"' -and $r.Content -match "Pre-filled from the item")
  $q = @(@('batch_number', $fgBatch), @('status', 'PASS'), @('sample_size', '5'), @('rework_notes', ''), @('param[]', 'Pin size'), @('value[]', '4.02'), @('param[]', 'Width'), @('value[]', '12.04'), @('param[]', 'Fit test'), @('value[]', 'PASS - snug'))
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', ''), @('status', 'PASS'), @('sample_size', '5'))
  Ok 'inspection: no batch is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Choose the batch')
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', $fgBatch), @('status', 'BOGUS'), @('sample_size', '5'))
  Ok 'inspection: an unknown result is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Choose the result')
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', $fgBatch), @('status', 'PASS'), @('sample_size', '0'))
  Ok 'inspection: sample size 0 is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'sample size')
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', $fgBatch), @('status', 'REJECT'), @('sample_size', '5'), @('rework_notes', ''))
  Ok 'inspection: reject needs notes' ($r.StatusCode -eq 422 -and $r.Content -match 'notes')
  $r = Post $s '/quality/new' '/quality' $q
  Ok 'inspection: pass saved' ($r.StatusCode -eq 303 -and $r.Location -match '/quality$')
  $r = Get-Page $s '/quality?status=PASS'
  Ok 'inspection list: shows the report for the batch' ($r.Content -match $fgBatch -and $r.Content -match 'QC-\d{4}-\d{5}')
  $qcId = ([regex]::Matches($r.Content, "(?s)/quality/([0-9a-f-]{36})`"[^<]*</a></td>\s*<td>$fgBatch"))[0].Groups[1].Value
  $r = Get-Page $s "/quality/$qcId"
  Ok 'inspection detail: readings are listed' ($r.Content -match 'Pin size' -and $r.Content -match '4.02' -and $r.Content -match 'PASS - snug')
  # scrap a whole batch of the other raw material
  $rsBatch = [regex]::Match((Get-Page $s '/quality/new').Content, "value=`"(BATCH-RM-[A-Z0-9-]+)`">[^<]*Item RS$tag").Groups[1].Value
  Ok 'inspection form lists the received batch' ($rsBatch -ne '')
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', $rsBatch), @('status', 'SCRAP'), @('sample_size', '5'), @('rework_notes', 'contaminated'))
  Ok 'SCRAP: saved' ($r.StatusCode -eq 303)
  Ok 'SCRAP: the whole batch left stock' ((Stock $ids.rs) -match '^0')
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', $rsBatch), @('status', 'SCRAP'), @('sample_size', '5'), @('rework_notes', 'again'))
  Ok 'SCRAP: scrapping the same batch twice is refused, form kept' ($r.StatusCode -eq 422 -and $r.Content -match 'already been scrapped' -and $r.Content -match 'again')
  Ok 'SCRAP: the second attempt did not write off more stock' ((Stock $ids.rs) -match '^0(\.0+)?$')

  # packing
  $r = Get-Page $s "/dispatch/new?order=$A"
  Ok 'pack form: order preselected, finished batch offered' ($r.Content -match "value=`"$A`" selected" -and $r.Content -match $fgBatch)
  $batchId = [regex]::Match($r.Content, "value=`"([0-9a-f-]{36})`">$fgBatch").Groups[1].Value
  $r = Post $s '/dispatch/new' '/dispatch' @(@('order_id', $A), @('batch_id[]', ''), @('carton_count[]', '3'), @('qty_per_carton[]', '30'))
  Ok 'pack: a line without a batch is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'needs a batch')
  $r = Post $s '/dispatch/new' '/dispatch' @(@('order_id', $A), @('batch_id[]', $batchId), @('carton_count[]', '3000'), @('qty_per_carton[]', '30'))
  Ok 'pack: 3000 cartons is refused' ($r.StatusCode -eq 422)
  $r = Post $s '/dispatch/new' '/dispatch' @(@('order_id', $A), @('batch_id[]', $batchId), @('carton_count[]', '3'), @('qty_per_carton[]', '30'))
  Ok 'pack: 3 cartons of 30 m' ($r.StatusCode -eq 303 -and $r.Location -match '/dispatch/[0-9a-f-]{36}'); $dn = Id $r.Location
  $r = Get-Page $s "/dispatch/$dn"
  $codes = [regex]::Matches($r.Content, '<td>(CTN-\d{4}-\d{5})</td>') | ForEach-Object { $_.Groups[1].Value }
  Ok 'delivery note: three serial-numbered cartons, none scanned' ($codes.Count -eq 3 -and $r.Content -match '0 / 3' -and $r.Content -match 'Draft')
  Post $s "/dispatch/$dn" "/dispatch/$dn/cartons" @(@('quantity', '10'), @('carton_code', '')) | Out-Null
  $r = Get-Page $s "/dispatch/$dn"
  $codes = [regex]::Matches($r.Content, '<td>(CTN-\d{4}-\d{5})</td>') | ForEach-Object { $_.Groups[1].Value }
  Ok 'delivery note: one more carton added' ($codes.Count -eq 4 -and $r.Content -match '100 m')
  Post $s "/dispatch/$dn" "/dispatch/$dn/cartons" @(@('quantity', '10'), @('carton_code', 'bad*code')) | Out-Null
  Ok 'carton code with a character Code 39 cannot hold is refused' ((Flash-Of (Get-Page $s "/dispatch/$dn").Content) -match 'letters, digits')
  Post $s "/dispatch/$dn" "/dispatch/$dn/cartons" @(@('quantity', '0'), @('carton_code', '')) | Out-Null
  Ok 'carton quantity 0 is refused' ((Flash-Of (Get-Page $s "/dispatch/$dn").Content) -match 'above zero')
  Post $s "/dispatch/$dn" "/dispatch/$dn/transport" @(@('transporter', "Fast Cargo $tag"), @('vehicle', 'GJ01AB1234'), @('lr', 'LR778')) | Out-Null
  $r = Get-Page $s "/dispatch/$dn"
  Ok 'transport details saved' ($r.Content -match "Fast Cargo $tag" -and $r.Content -match 'GJ01AB1234')

  $r = Get-Page $s "/dispatch/$dn/labels"
  Ok 'labels: one Code 39 barcode per carton, with the code printed' ([regex]::Matches($r.Content, '<svg class="barcode"').Count -eq 4 -and $r.Content -match $codes[0] -and $r.Content -match 'data-print')
  Ok 'labels: barcode starts and ends with the * guard (10 bars + gaps per char)' ($r.Content -match '<rect x="20" y="0" width="2"')
  $r = Get-Page $s "/dispatch/$dn/print"
  Ok 'delivery note print: totals and signature lines' ($r.Content -match 'Delivery note' -and $r.Content -match '>100<' -and $r.Content -match 'Received by')

  # dock-gate scan
  $r = Post $s '/scan/dispatch' '/scan/dispatch' @(, @('carton_code', 'CTN-0000-00000'))
  Ok 'scan: an unknown carton is refused' ($r.StatusCode -eq 409 -and $r.Content -match 'not recognized')
  $r = Post $s '/scan/dispatch' '/scan/dispatch' @(, @('carton_code', $codes[0].ToLower()))
  Ok 'scan: first carton (lower case is accepted), 1 of 4' ($r.StatusCode -eq 200 -and $r.Content -match '1 of 4 cartons scanned')
  $r = Post $s '/scan/dispatch' '/scan/dispatch' @(, @('carton_code', $codes[0]))
  Ok 'scan: the same carton again is refused, supervisor re-scan is offered' ($r.StatusCode -eq 409 -and $r.Content -match 'carton was already scanned' -and $r.Content -notmatch 'ALREADY_SCANNED' -and $r.Content -match 'Supervisor re-scan')
  $r = Post $s '/scan/dispatch' '/scan/dispatch' @(@('carton_code', $codes[0]), @('override', '1'), @('override_note', ''))
  Ok 'scan: a re-scan needs a reason' ((Flash-Of (Get-Page $s '/scan/dispatch').Content) -match 'reason')
  $r = Post $s '/scan/dispatch' '/scan/dispatch' @(@('carton_code', $codes[0]), @('override', '1'), @('override_note', 'label torn'))
  Ok 'scan: re-scan with a reason is accepted' ($r.StatusCode -eq 200 -and $r.Content -match '1 of 4')
  Ok 'scan: stock fell by one carton only (100 - 30)' ((Stock $ids.fg) -match '^70')
  $page = (Get-Page $s "/dispatch/$dn").Content
  Ok 'load: the note shows tick boxes only for cartons not yet loaded, and no scanner is needed' (([regex]::Matches($page, 'name="cartons\[\]"')).Count -eq 3 -and $page -match 'Mark ticked cartons as loaded' -and $page -notmatch 'Dock-gate scan')
  Post $s "/dispatch/$dn" "/dispatch/$dn/load" @() | Out-Null
  Ok 'load: nothing ticked is refused' ((Flash-Of (Get-Page $s "/dispatch/$dn").Content) -match 'Tick at least one')
  Post $s "/dispatch/$dn" "/dispatch/$dn/load" @(@('cartons[]', $codes[1]), @('cartons[]', $codes[2])) | Out-Null
  $r = Get-Page $s "/dispatch/$dn"
  Ok 'load: two ticked cartons are marked loaded (3 / 4)' ((Flash-Of $r.Content) -match '2 cartons marked as loaded' -and $r.Content -match '3 / 4')
  Post $s "/dispatch/$dn" "/dispatch/$dn/load" @(, @('all', '1')) | Out-Null
  $r = Get-Page $s "/dispatch/$dn"
  Ok 'load: marking the rest completes the delivery note' ((Flash-Of $r.Content) -match '1 carton marked as loaded' -and $r.Content -match '4 / 4' -and $r.Content -match 'Dispatched')
  Ok 'scan: finished goods stock is 0' ((Stock $ids.fg) -match '^0(\.0+)?$')
  $r = Get-Page $s "/dispatch/$dn"
  Ok 'delivery note: Dispatched, all scanned, re-scan flagged' ($r.Content -match 'Dispatched' -and $r.Content -match '4 / 4' -and $r.Content -match 'Re-scan')
  Ok 'order is Dispatched' ((Get-Page $s "/orders/$A").Content -match 'Dispatched')
  Ok 'dispatch list shows the note with 4 / 4' ((Get-Page $s '/dispatch').Content -match '4 / 4')

  # dispatching goods that are not in stock
  $r = Post $s '/dispatch/new' '/dispatch' @(@('order_id', $A), @('batch_id[]', $batchId), @('carton_count[]', '1'), @('qty_per_carton[]', '50'))
  Ok 'pack: a second note is created on request' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $dn2 = Id $r.Location
  $code2 = [regex]::Match((Get-Page $s "/dispatch/$dn2").Content, '<td>(CTN-\d{4}-\d{5})</td>').Groups[1].Value
  $r = Post $s '/scan/dispatch' '/scan/dispatch' @(, @('carton_code', $code2))
  Ok 'OVER-DISPATCH: a carton with no stock behind it is refused' ($r.StatusCode -eq 409 -and $r.Content -match 'Not enough finished-goods stock')
  Post $s "/dispatch/$dn2" "/dispatch/$dn2/load" @(, @('all', '1')) | Out-Null
  Ok 'OVER-DISPATCH: loading it from the delivery note is refused too, naming the carton' ((Flash-Of (Get-Page $s "/dispatch/$dn2").Content) -match "Stopped at $code2.*Not enough finished-goods stock")

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('store', 'Store'), @('qc', 'QC'), @('disp', 'Dispatch'), @('op', 'Operator'), @('acct', 'Accounts'), @('sales', 'Sales'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $store = (Sign-In "store.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Store: can read purchase orders' ((Get-Page $store '/purchase-orders').StatusCode -eq 200)
  Ok 'Store: cannot create one (403)' ((Get-Page $store '/purchase-orders/new').StatusCode -eq 403)
  $r = Post $store "/purchase-orders/$po1" "/purchase-orders/$po1/receive" @()
  Ok 'Store: may receive goods (update right), already received here so the DB refuses' ($r.StatusCode -eq 303)
  Ok 'Store: no access to quality or dispatch' ((Get-Page $store '/quality').StatusCode -eq 403 -and (Get-Page $store '/dispatch').StatusCode -eq 403)

  $qcu = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: can read and create inspections' ((Get-Page $qcu '/quality').StatusCode -eq 200 -and (Get-Page $qcu '/quality/new').StatusCode -eq 200)
  Ok 'QC: batch list works for the form (Stock read)' ((Get-Page $qcu '/quality/new').Content -match $fgBatch)
  Ok 'QC: no dispatch' ((Get-Page $qcu '/dispatch').StatusCode -eq 403)

  $disp = (Sign-In "disp.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Dispatch: can open the scan screen and pack' ((Get-Page $disp '/scan/dispatch').StatusCode -eq 200 -and (Get-Page $disp '/dispatch/new').StatusCode -eq 200)
  Ok 'Dispatch: can read delivery notes and print labels' ((Get-Page $disp "/dispatch/$dn").StatusCode -eq 200 -and (Get-Page $disp "/dispatch/$dn/labels").StatusCode -eq 200)
  Ok 'Dispatch: no purchase orders' ((Get-Page $disp '/purchase-orders').StatusCode -eq 403)
  $r = Post $disp "/dispatch/$dn" "/dispatch/$dn/transport" @(@('transporter', "By Dispatch $tag"), @('vehicle', 'GJ05XX0001'), @('lr', 'LR1'))
  Ok 'Dispatch: can record transport details (Dispatch right, not an order right)' ($r.StatusCode -eq 303 -and (Get-Page $disp "/dispatch/$dn").Content -match "By Dispatch $tag")
  $r = Post $disp "/orders/$A" "/orders/$A/confirm" @(, @('approval_method', 'EMAIL'))
  Ok 'Dispatch: cannot confirm or change an order (403)' ($r.StatusCode -eq 403)
  $r = Post $disp "/orders/$A" "/orders/$A/status" @(, @('status', 'CANCELLED'))
  Ok 'Dispatch: cannot cancel an order (403)' ($r.StatusCode -eq 403)

  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Operator: no quality, no dispatch, no purchasing' ((Get-Page $op '/quality').StatusCode -eq 403 -and (Get-Page $op '/dispatch').StatusCode -eq 403 -and (Get-Page $op '/purchase-orders').StatusCode -eq 403)
  $r = Post $op '/scan/dispatch' '/scan/dispatch' @(, @('carton_code', $codes[0]))
  Ok 'Operator: dispatch scan is 403' ($r.StatusCode -eq 403)

  $acct = (Sign-In "acct.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $acct "/purchase-orders/$po1"
  Ok 'Accounts: can read a purchase order but sees no action buttons' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Receive everything' -and $r.Content -notmatch 'Submit to supplier')
  $r = Post $acct "/purchase-orders/$po1" "/purchase-orders/$po1/receive" @()
  Ok 'Accounts: receiving is 403' ($r.StatusCode -eq 403)

  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: no purchase orders, no dispatch' ((Get-Page $sales '/purchase-orders').StatusCode -eq 403 -and (Get-Page $sales '/dispatch').StatusCode -eq 403)
  Ok 'unknown ids are 404' ((Get-Page $s '/purchase-orders/not-an-id').StatusCode -eq 404 -and (Get-Page $s '/dispatch/not-an-id').StatusCode -eq 404 -and (Get-Page $s '/quality/not-an-id').StatusCode -eq 404)
} finally { Stop-Dev }
Summary
