# HTTP smoke test for Phase 3 (shop floor): work orders, job cards, board, production log, downtime,
# handovers, material receipt and mixing scans, and who may do what.
#   pwsh tools/smoke_phase3.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
# the board card (an HTML chunk) that mentions $needle
function Card($html, $needle) { ($html -split '<section class="card floor-card">') | Where-Object { $_ -match [regex]::Escape($needle) } | Select-Object -First 1 }
function Machine($html, $needle) { ($html -split '<section class="card floor-card">') | Where-Object { $_ -match [regex]::Escape($needle) -and $_ -match 'Report stop|Back in service' } | Select-Object -First 1 }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ })
  $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/shop-floor', '/work-orders', '/work-orders/new', '/downtime', '/downtime?days=7', '/handovers', '/handovers/new', '/scan/receive', '/scan/mixing') {
    $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)"
  }

  # master data: two raw materials, one finished good, a two-material BOM, a machine
  Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE')) | Out-Null
  $r = Post $s '/customers/new' '/customers' @(, @('name', "Cust $tag")); $cust = Id $r.Location
  $ids = @{}
  foreach ($def in @(@('rm1', "RA$tag", 'RAW_MATERIAL', 'Kg'), @('rm2', "RB$tag", 'RAW_MATERIAL', 'Kg'), @('rm3', "RC$tag", 'RAW_MATERIAL', 'Kg'), @('fg', "FG$tag", 'FINISHED_GOODS', 'Meter'))) {
    $r = Post $s '/items/new' '/items' @(@('code', $def[1]), @('name', "Item $($def[1])"), @('category', $def[2]), @('uom', $def[3])); $ids[$def[0]] = Id $r.Location
  }
  $r = Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $ids.fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $ids.rm1), @('qty_per_unit[]', '5'), @('rm_item_id[]', $ids.rm2), @('qty_per_unit[]', '1'))
  Ok 'two-material BOM created' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "WS$tag"), @('name', "Line $tag"), @('hourly_rate', '400'), @('status', 'IDLE'))
  $ws = Id $r.Location

  # receive material by scanning
  $r = Get-Page $s '/scan/receive'
  Ok 'receive screen: bags, weight and an optional lot reference, no barcode box; items listed' ($r.Content -match 'name="bags"' -and $r.Content -match 'name="lot"' -and $r.Content -notmatch 'id="f_barcode"' -and $r.Content -match "RA$tag")
  Ok 'receive screen: also reachable at /stock/receive' ((Get-Page $s '/stock/receive').StatusCode -eq 200)
  $r = Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RA$tag"), @('qty', '30'), @('barcode', "BAG-$tag-1"))
  Ok 'receive: first bag' ($r.StatusCode -eq 303 -and $r.Location -match 'stock/receive')
  $r = Get-Page $s '/scan/receive'
  Ok 'receive: success message names the batch' ((Flash-Of $r.Content) -match "BATCH-RM-BAG-$tag-1")
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RB$tag"), @('qty', '10'), @('barcode', "BAG-$tag-2")) | Out-Null
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RC$tag"), @('qty', '10'), @('barcode', "BAG-$tag-3")) | Out-Null
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RA$tag"), @('qty', '30'), @('barcode', "BAG-$tag-1"))  | Out-Null
  $r = Get-Page $s '/scan/receive'
  Ok 'receive: the same barcode twice is refused' ((Flash-Of $r.Content) -match 'already been received')
  Post $s '/stock/receive' '/stock/receive' @(@('item_code', "RA$tag"), @('bags', '3'), @('qty', '10'), @('lot', '')) | Out-Null
  Ok 'receive: no lot reference needed, the lot is numbered and 3 bags of 10 make 30' ((Flash-Of (Get-Page $s '/stock/receive').Content) -match 'Received 30 of .* as lot BATCH-RM-\d{4}-\d{5}')
  Post $s '/stock/receive' '/stock/receive' @(@('item_code', "RA$tag"), @('bags', '0'), @('qty', '10')) | Out-Null
  Ok 'receive: zero bags is refused' ((Flash-Of (Get-Page $s '/stock/receive').Content) -match 'number of bags')
  Post $s '/stock/receive' '/stock/receive' @(@('item_code', "RA$tag"), @('bags', '2'), @('qty', '5'), @('lot', "LOT-$tag-A")) | Out-Null
  Ok 'receive: a supplier lot reference names the lot' ((Flash-Of (Get-Page $s '/stock/receive').Content) -match "as lot BATCH-RM-LOT-$tag-A")
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RA$tag"), @('qty', '0'), @('barcode', "BAG-$tag-9")) | Out-Null
  Ok 'receive: zero weight is refused' ((Flash-Of (Get-Page $s '/scan/receive').Content) -match 'above zero')

  # order 500 m (needs 25.64 kg of RA and 5.13 kg of RB), confirm, find the work order
  $future = (Get-Date).AddDays(10).ToString('yyyy-MM-dd')
  $r = Post $s '/orders/new' '/orders' @(@('customer_id', $cust), @('delivery_date', $future), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '500'), @('rate[]', '40'))
  $A = Id $r.Location
  $r = Post $s "/orders/$A" "/orders/$A/confirm" @(, @('approval_method', 'EMAIL'))
  Ok 'order confirmed against scanned stock' ($r.StatusCode -eq 303)
  $orderNo = [regex]::Match((Get-Page $s "/orders/$A").Content, 'SO-\d{4}-\d{5}').Value
  $r = Get-Page $s '/work-orders'
  $woId = ([regex]::Matches($r.Content, "(?s)/work-orders/([0-9a-f-]{36})`"[^<]*</a></td>(?:(?!</tr>).)*$orderNo"))[0].Groups[1].Value
  Ok 'work order was created by the confirm and is in the list' ($woId -ne '')
  $r = Get-Page $s '/work-orders?status=PENDING'
  Ok 'work order list: status filter' ($r.Content -match $orderNo)

  $r = Get-Page $s "/work-orders/$woId"
  Ok 'work order: detail shows both raw materials needed' ($r.Content -match "RA$tag" -and $r.Content -match '25.64' -and $r.Content -match '5.13' -and $r.Content -match 'No job cards yet')
  $r = Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', ''))
  Ok 'job card: no workstation is refused' ((Flash-Of (Get-Page $s "/work-orders/$woId").Content) -match 'Choose a workstation')
  $r = Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $ws), @('assign_me', '1'))
  Ok 'job card: created' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/work-orders/$woId"
  Ok 'job card: queued and assigned to the admin' ($r.Content -match 'Queued' -and $r.Content -match 'Administrator')

  # the board
  $board = (Get-Page $s '/shop-floor').Content
  $card = Card $board "WS$tag"
  Ok 'board: the queued job shows with a Start button and no logging buttons yet' ($card -and $card -match 'Start this job' -and $card -notmatch '\+100 m')
  $jc = [regex]::Match($card, '/job-cards/([0-9a-f-]{36})/status').Groups[1].Value
  $r = Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor'))
  Ok 'board: start returns to the board' ($r.StatusCode -eq 303 -and $r.Location -match '/shop-floor$')
  $card = Card (Get-Page $s '/shop-floor').Content "WS$tag"
  Ok 'board: active card has the +100 / +500 / +1000 buttons and custom boxes' ($card -match '\+100 m' -and $card -match '\+500 m' -and $card -match '\+1,000 m' -and $card -match 'Add metres' -and $card -match 'Add scrap')

  $r = Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '100'), @('back', '/shop-floor'))
  $board = (Get-Page $s '/shop-floor').Content
  Ok 'log +100 m: flash and progress' ((Flash-Of $board) -match 'Logged \+100 m' -and (Card $board "WS$tag") -match '<strong>100</strong> of 500')
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '400'), @('back', '/shop-floor')) | Out-Null
  Ok 'log +400 m: work order is complete on the board' ((Card (Get-Page $s '/shop-floor').Content "WS$tag") -match '<strong>500</strong> of 500')
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '1000'), @('back', '/shop-floor')) | Out-Null
  Ok 'NEGATIVE STOCK: 1000 m more is refused' ((Flash-Of (Get-Page $s '/shop-floor').Content) -match 'Negative Stock')
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'SCRAP'), @('amount', '2.5'), @('back', '/shop-floor')) | Out-Null
  Ok 'scrap: 2.5 kg is shown on the card' ((Card (Get-Page $s '/shop-floor').Content "WS$tag") -match '2\.5 kg scrap')
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '0'), @('back', '/shop-floor')) | Out-Null
  Ok 'log: zero is refused' ((Flash-Of (Get-Page $s '/shop-floor').Content) -match 'above zero')
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', 'abc'), @('back', '/shop-floor')) | Out-Null
  Ok 'log: a non-number is refused' ((Flash-Of (Get-Page $s '/shop-floor').Content) -match 'above zero')
  $r = Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'SCRAP'), @('amount', '0.5'), @('back', '//evil.example/x'))
  Ok 'back link: an off-site address is ignored' ($r.StatusCode -eq 303 -and $r.Location -notmatch 'evil' -and $r.Location -match '/shop-floor$') $r.Location
  $r = Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'SCRAP'), @('amount', '0.5'), @('back', '/work-orders'))
  Ok 'back link: a local page is honoured' ($r.Location -match '/work-orders$')

  $r = Get-Page $s "/work-orders/$woId"
  Ok 'work order: produced 500, status In progress, batch assigned' ($r.Content -match '500.000' -and $r.Content -match 'In progress' -and $r.Content -match 'BATCH-FG-\d{4}-\d{5}')
  $r = Get-Page $s "/orders/$A"
  Ok 'order detail: now In production with the job card listed' ($r.Content -match 'In production' -and $r.Content -match "WS$tag")
  $r = Post $s "/work-orders/$woId" "/work-orders/$woId/delete" @()
  Ok 'work order with production cannot be deleted' ((Flash-Of (Get-Page $s "/work-orders/$woId").Content) -match 'already has production')

  # mixing check
  $r = Get-Page $s "/scan/mixing?job=$jc"
  Ok 'mixing: job card is preselected' ($r.Content -match "value=`"$jc`" selected")
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BATCH-RM-BAG-$tag-1"), @('barcode_2', "BATCH-RM-BAG-$tag-2"))
  Ok 'mixing: two different BOM materials match' ($r.StatusCode -eq 200 -and $r.Content -match 'Match. The lots are on the BOM')
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BAG-$tag-1"), @('barcode_2', "BAG-$tag-2"))
  Ok 'mixing: the bag barcode that was typed when receiving works as well as the batch number' ($r.Content -match 'Match. The lots are on the BOM')
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BATCH-RM-BAG-$tag-1"), @('barcode_2', "BATCH-RM-BAG-$tag-1"))
  Ok 'mixing: the same material twice is a mismatch, and says so' ($r.Content -match 'Mismatch Detected' -and $r.Content -match 'same material')
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BATCH-RM-BAG-$tag-1"), @('barcode_2', 'NOT-A-REAL-CODE'))
  Ok 'mixing: an unknown lot is named in the message' ($r.Content -match 'second lot' -and $r.Content -match 'NOT-A-REAL-CODE' -and $r.Content -match 'not found')
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BATCH-RM-BAG-$tag-1"), @('barcode_2', "BATCH-RM-BAG-$tag-3"))
  Ok 'mixing: a material that is not on the BOM is a mismatch' ($r.Content -match 'Mismatch Detected')
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BATCH-RM-BAG-$tag-1"), @('barcode_2', 'NOT-A-REAL-CODE'))
  Ok 'mixing: an unknown barcode is a mismatch' ($r.Content -match 'Mismatch Detected')
  $r = Post $s '/scan/mixing' '/scan/mixing' @(@('job_card_id', $jc), @('barcode_1', "BATCH-RM-BAG-$tag-1"), @('barcode_2', ''))
  Ok 'mixing: one lot only is refused' ((Flash-Of (Get-Page $s '/scan/mixing').Content) -match 'Choose both lots')

  # downtime
  $card = Machine (Get-Page $s '/shop-floor').Content "WS$tag"
  $r = Post $s '/shop-floor' '/downtime/start' @(@('workstation_id', $ws), @('reason_code', 'MECHANICAL'), @('back', '/shop-floor'))
  $board = (Get-Page $s '/shop-floor').Content
  $card = Machine $board "WS$tag"
  Ok 'downtime: the machine shows as stopped with Back in service' ($card -match 'Stopped since' -and $card -match 'Mechanical breakdown' -and $card -match 'Back in service')
  $stopId = [regex]::Match($card, '/downtime/([0-9a-f-]{36})/end').Groups[1].Value
  $r = Get-Page $s '/downtime'
  Ok 'downtime list: the stop is Open' ($r.Content -match "WS$tag" -and $r.Content -match 'Open')
  Post $s '/shop-floor' '/downtime/start' @(@('workstation_id', $ws), @('reason_code', 'NOPE'), @('back', '/shop-floor')) | Out-Null
  Ok 'downtime: an unknown reason is refused' ((Flash-Of (Get-Page $s '/shop-floor').Content) -match 'Choose why')
  $r = Post $s '/shop-floor' "/downtime/$stopId/end" @(, @('back', '/shop-floor'))
  $card = Machine (Get-Page $s '/shop-floor').Content "WS$tag"
  Ok 'downtime: back in service closes the stop' ($r.StatusCode -eq 303 -and $card -notmatch 'Stopped since' -and $card -match 'Report stop')
  Post $s '/shop-floor' "/downtime/$stopId/end" @(, @('back', '/shop-floor')) | Out-Null
  Ok 'downtime: closing twice is refused' ((Flash-Of (Get-Page $s '/shop-floor').Content) -match 'already closed')

  # handovers
  $r = Post $s '/handovers/new' '/handovers' @(@('shift_type', 'MORNING'), @('workstation_id', $ws), @('scrap_qty', '-1'), @('notes', 'x'))
  Ok 'handover: negative scrap is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'zero or more')
  $r = Post $s '/handovers/new' '/handovers' @(@('shift_type', 'MORNING'), @('workstation_id', $ws), @('scrap_qty', '1.5'), @('notes', "Die was cleaned $tag"))
  Ok 'handover: recorded' ($r.StatusCode -eq 303)
  $r = Get-Page $s '/handovers?status=PENDING'
  Ok 'handover: waiting list shows it' ($r.Content -match "Die was cleaned $tag" -and $r.Content -match 'Waiting')
  $hid = ([regex]::Matches($r.Content, "(?s)<tr>(?:(?!</tr>).)*Die was cleaned $tag(?:(?!</tr>).)*?/handovers/([0-9a-f-]{36})/accept"))[0].Groups[1].Value
  $r = Post $s '/handovers' "/handovers/$hid/accept" @()
  $r = Get-Page $s '/handovers?status=ACCEPTED'
  Ok 'handover: accepted' ($r.Content -match "Die was cleaned $tag")
  Post $s '/handovers' "/handovers/$hid/accept" @() | Out-Null
  Ok 'handover: accepting twice is refused' ((Flash-Of (Get-Page $s '/handovers').Content) -match 'already accepted')

  # complete the job card, then the work order
  $r = Post $s "/work-orders/$woId" "/job-cards/$jc/status" @(@('status', 'COMPLETED'), @('back', "/work-orders/$woId"))
  Ok 'job card: completed' ($r.StatusCode -eq 303 -and (Get-Page $s "/work-orders/$woId").Content -match 'Completed')
  $r = Post $s "/work-orders/$woId" "/work-orders/$woId" @(@('status', 'COMPLETED'), @('planned_qty', '500'))
  Ok 'work order: marked completed' ($r.StatusCode -eq 303 -and (Get-Page $s "/work-orders/$woId").Content -match 'Completed')
  Post $s "/work-orders/$woId" "/work-orders/$woId" @(@('status', 'BOGUS')) | Out-Null
  Ok 'work order: a bogus status is refused' ((Flash-Of (Get-Page $s "/work-orders/$woId").Content) -match 'valid status')

  # a stock work order made by hand
  $r = Post $s '/work-orders/new' '/work-orders' @(@('sales_order_id', ''), @('fg_item_id', $ids.fg), @('planned_qty', '0'))
  Ok 'new work order: zero quantity is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'above zero')
  $r = Post $s '/work-orders/new' '/work-orders' @(@('sales_order_id', ''), @('fg_item_id', $ids.fg), @('planned_qty', '200'), @('fg_batch_number', "FGB-$tag"))
  Ok 'new work order: created by hand' ($r.StatusCode -eq 303)
  $W2 = Id $r.Location
  $r = Post $s "/work-orders/$W2" "/work-orders/$W2/delete" @()
  Ok 'new work order: can be deleted while nothing is produced' ($r.StatusCode -eq 303 -and $r.Location -match '/work-orders$')

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('op', 'Operator'), @('store', 'Store'), @('qc', 'QC'), @('prod', 'Production'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $op '/shop-floor'
  Ok 'Operator: sees the board, no Start/Complete controls' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Back in service' -and $r.Content -notmatch '>Complete<')
  $r = Get-Page $op '/work-orders'
  Ok 'Operator: can read work orders but not create' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'New work order')
  Ok 'Operator: new work order is 403' ((Get-Page $op '/work-orders/new').StatusCode -eq 403)
  Ok 'Operator: receive material is 403 (scan right is not receive right)' ((Get-Page $op '/scan/receive').StatusCode -eq 403)
  Ok 'Operator: mixing check is allowed' ((Get-Page $op '/scan/mixing').StatusCode -eq 200)
  $r = Post $op '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor'))
  Ok 'Operator: changing a job card status is 403' ($r.StatusCode -eq 403)
  $r = Post $op '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'SCRAP'), @('amount', '0.1'), @('back', '/shop-floor'))
  Ok 'Operator: may log production' ($r.StatusCode -eq 303)

  $store = (Sign-In "store.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Store: can receive material' ((Get-Page $store '/scan/receive').StatusCode -eq 200)
  Ok 'Store: shop floor is 403' ((Get-Page $store '/shop-floor').StatusCode -eq 403)

  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: can read the board' ((Get-Page $qc '/shop-floor').StatusCode -eq 200)
  $r = Post $qc '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'SCRAP'), @('amount', '0.1'), @('back', '/shop-floor'))
  Ok 'QC: logging production is 403' ($r.StatusCode -eq 403)

  $prod = (Sign-In "prod.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $prod "/work-orders/$woId"
  Ok 'Production: work order page offers add job card, status and no delete once produced' ($r.StatusCode -eq 200 -and $r.Content -match 'Add a job card' -and $r.Content -notmatch '>Delete<')
  Post $prod '/handovers' "/handovers/$hid/accept" @() | Out-Null
  $r = Post $prod '/handovers/new' '/handovers' @(@('shift_type', 'NIGHT'), @('workstation_id', $ws), @('scrap_qty', '0'), @('notes', "Night note $tag"))
  $r = Get-Page $prod '/handovers?status=PENDING'
  $hid2 = ([regex]::Matches($r.Content, "(?s)<tr>(?:(?!</tr>).)*Night note $tag(?:(?!</tr>).)*?/handovers/([0-9a-f-]{36})/accept"))[0].Groups[1].Value
  Post $s '/handovers' "/handovers/$hid2/accept" @() | Out-Null
  $r = Get-Page $s '/handovers?status=ACCEPTED'
  Ok 'handover: another user accepts the Production user''s handover' ($r.Content -match "Night note $tag")
  Ok 'unknown work order id is a 404' ((Get-Page $s '/work-orders/not-an-id').StatusCode -eq 404)
} finally { Stop-Dev }
Summary
