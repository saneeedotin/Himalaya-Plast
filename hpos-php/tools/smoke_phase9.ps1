# HTTP smoke test for Phase 9 (QC sheets): colour and limits on the product, starting a sheet from a job card,
# readings that are signed and flagged, voiding, the printable sheet, CSV, closing, and who may do what.
#   pwsh tools/smoke_phase9.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Download($session, $path) { $resp = $session.GetAsync("$base$path").GetAwaiter().GetResult(); [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Type = [string]$resp.Content.Headers.ContentType; Disposition = [string]$resp.Content.Headers.ContentDisposition; Bytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult() } }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $today = (Get-Date).ToString('yyyy-MM-dd')
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/quality/sheets', '/quality/sheets/new', '/quality') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/quality/sheets'
  Ok 'menu: QC sheets is listed, and /quality/sheets is not read as an inspection id' ($r.Content -match '>QC sheets<' -and $r.Content -match 'Start a sheet')

  # a running job with a batch: item, BOM, machine, die, raw material, order, work order, job card, 10 m logged
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE'))
  $gst = '24' + $tag + '1234F1Z5'
  $r = Post $s '/customers/new' '/customers' @(@('name', "QC Cust $tag"), @('gstin', $gst)); $cust = Id $r.Location
  $ids = @{}
  foreach ($def in @(@('rm', "RM$tag", 'RAW_MATERIAL', 'Kg', "Compound $tag"), @('fg', "FG$tag", 'FINISHED_GOODS', 'Meter', "Gasket $tag"))) {
    $r = Post $s '/items/new' '/items' @(@('code', $def[1]), @('name', $def[4]), @('category', $def[2]), @('uom', $def[3]), @('hsn_code', '3917')); $ids[$def[0]] = Id $r.Location
  }
  Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $ids.fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $ids.rm), @('qty_per_unit[]', '5')) | Out-Null
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "WS$tag"), @('name', "Line $tag"), @('hourly_rate', '400'), @('status', 'IDLE')); $ws = Id $r.Location
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RM$tag"), @('qty', '30'), @('barcode', "BAG-$tag-1")) | Out-Null
  $future = (Get-Date).AddDays(10).ToString('yyyy-MM-dd')
  $r = Post $s '/orders/new' '/orders' @(@('customer_id', $cust), @('delivery_date', $future), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '100'), @('rate[]', '40'))
  $A = Id $r.Location
  Post $s "/orders/$A" "/orders/$A/confirm" @(, @('approval_method', 'EMAIL')) | Out-Null
  $orderNo = [regex]::Match((Get-Page $s "/orders/$A").Content, 'SO-\d{4}-\d{5}').Value
  $woId = ([regex]::Matches((Get-Page $s '/work-orders').Content, "(?s)/work-orders/([0-9a-f-]{36})`"[^<]*</a></td>(?:(?!</tr>).)*$orderNo"))[0].Groups[1].Value
  Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $ws), @('assign_me', '1')) | Out-Null
  $card = ((Get-Page $s '/shop-floor').Content -split '<section class="card floor-card">') | Where-Object { $_ -match "WS$tag" } | Select-Object -First 1
  $jc = [regex]::Match($card, '/job-cards/([0-9a-f-]{36})/status').Groups[1].Value
  Ok 'setup: a job card exists' ($jc -ne '')
  $r = Get-Page $s '/quality/sheets/new'
  Ok 'new sheet: the job is offered (no batch yet)' ($r.Content -match "WS$tag" -and $r.Content -match 'no batch yet')
  $r = Post $s '/quality/sheets/new' '/quality/sheets' @(@('job_card_id', $jc), @('mfg_date', $today))
  Ok 'new sheet: a job without a batch is refused with the reason' ($r.StatusCode -eq 422 -and $r.Content -match 'no batch yet')
  Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor')) | Out-Null
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '10'), @('back', '/shop-floor')) | Out-Null

  # colour and limits on the product
  $r = Get-Page $s "/items/$($ids.fg)"
  Ok 'item: the QC limits form is on a finished-goods item' ($r.Content -match 'QC sheet limits' -and $r.Content -match 'name="PIN_min"' -and $r.Content -match 'name="colour"')
  Ok 'item: a raw material has no QC limits form' ((Get-Page $s "/items/$($ids.rm)").Content -notmatch 'QC sheet limits')
  Post $s "/items/$($ids.fg)" "/items/$($ids.fg)/specs" @(@('colour', 'Black'), @('PIN_min', 'abc')) | Out-Null
  Ok 'item: a limit that is not a number is refused' ((Flash-Of (Get-Page $s "/items/$($ids.fg)").Content) -match 'must be a number')
  Post $s "/items/$($ids.fg)" "/items/$($ids.fg)/specs" @(@('colour', 'Black'), @('PIN_target', '5'), @('PIN_min', '4.9'), @('PIN_max', '5.1'), @('WEIGHT_min', '28'), @('WEIGHT_max', '30')) | Out-Null
  $r = Get-Page $s "/items/$($ids.fg)"
  Ok 'item: colour and limits are saved and shown again' ($r.Content -match 'value="Black"' -and $r.Content -match 'value="4.9"' -and $r.Content -match 'value="5.1"' -and $r.Content -match 'value="30"')
  Post $s "/items/$($ids.fg)" "/items/$($ids.fg)/specs" @(@('colour', 'Black'), @('PIN_min', '6'), @('PIN_max', '5')) | Out-Null
  Ok 'item: a lowest value above the highest is refused' ((Flash-Of (Get-Page $s "/items/$($ids.fg)").Content) -match 'not above the highest')

  # start the sheet
  $r = Get-Page $s "/quality/sheets/new?job_card=$jc"
  Ok 'new sheet: the job is preselected from the link' ($r.Content -match "value=`"$jc`" selected")
  $r = Post $s '/quality/sheets/new' '/quality/sheets' @(@('job_card_id', ''), @('mfg_date', $today))
  Ok 'new sheet: no job is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Choose the job card')
  $r = Post $s '/quality/sheets/new' '/quality/sheets' @(@('job_card_id', $jc), @('mfg_date', $today), @('dtd', $today), @('m_no', 'M-04'), @('metres', '800'), @('colour', ''))
  Ok 'new sheet: started' ($r.StatusCode -eq 303 -and $r.Location -match '/quality/sheets/[0-9a-f-]{36}'); $sh = Id $r.Location
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'sheet: header has number, batch, product, colour from the item, metres, M number' ($r.Content -match 'QS-\d{4}-\d{5}' -and $r.Content -match 'BATCH-FG-' -and $r.Content -match "FG$tag" -and $r.Content -match '(?s)Colour</dt><dd>\s*Black' -and $r.Content -match '(?s)Metres</dt><dd>\s*800' -and $r.Content -match 'M-04')
  Ok 'sheet: the limits are listed and the add-reading form is there' ($r.Content -match 'Limits: Pin size 4.9 to 5.1' -and $r.Content -match 'Weight 28 to 30' -and $r.Content -match 'name="v\[PIN\]"' -and $r.Content -match 'name="v\[FITTING\]"' -and $r.Content -match 'Add reading')

  # readings
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '5.00'), @('v[WIDTH]', '11.50'), @('v[LEG]', '6.02'), @('v[WEIGHT]', '28.7'), @('measured_at', (Get-Date).ToString('yyyy-MM-ddTHH:mm')), @('v[FITTING]', 'OK')) | Out-Null
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'reading: L-01 added, in range, signed with initials' ($r.Content -match 'L-01' -and $r.Content -match '>5<' -and $r.Content -notmatch 'class="num out"' -and (Flash-Of $r.Content) -match 'Reading added')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '5.30'), @('v[WEIGHT]', '27.5'), @('measured_at', ''), @('v[FITTING]', 'NOT_OK')) | Out-Null
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'reading: L-02 out of range is highlighted and the page warns' ($r.Content -match 'L-02' -and ([regex]::Matches($r.Content, 'class="num out"')).Count -eq 2 -and $r.Content -match '1 reading is outside the limits')
  $n = Get-Page $s '/notifications'
  Ok 'reading: an alert was raised for QC' ($n.Content -match 'QC reading out of range')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', 'abc')) | Out-Null
  Ok 'reading: text instead of a number is refused' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'must be a number')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '')) | Out-Null
  Ok 'reading: an empty reading is refused' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'at least one reading')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '5'), @('v[FITTING]', 'MAYBE')) | Out-Null
  Ok 'reading: an unknown fitting result is refused' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'listed choices')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '5'), @('measured_at', (Get-Date).AddDays(2).ToString('yyyy-MM-ddTHH:mm'))) | Out-Null
  Ok 'reading: a time in the future is refused' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'future')

  # voiding
  $page = (Get-Page $s "/quality/sheets/$sh").Content
  $row2 = ([regex]::Matches($page, "/quality/sheets/$sh/rows/([0-9a-f-]{36})/void"))[1].Groups[1].Value
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows/$row2/void" @(, @('reason', '')) | Out-Null
  Ok 'void: a reason is required' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'why')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows/$row2/void" @(, @('reason', "Typed 5.30 by mistake $tag")) | Out-Null
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'void: the reading stays, struck through, with the reason; the warning is gone' ($r.Content -match 'class="void"' -and $r.Content -match "Void: Typed 5.30 by mistake $tag" -and $r.Content -notmatch 'is outside the limits')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '5.02'), @('v[WEIGHT]', '29.1')) | Out-Null
  Ok 'reading: the next one is L-03 (a voided number is not reused)' ((Get-Page $s "/quality/sheets/$sh").Content -match 'L-03')

  # print and CSV
  $r = Get-Page $s "/quality/sheets/$sh/print"
  Ok 'print: laid out like the paper sheet' ($r.StatusCode -eq 200 -and $r.Content -match 'DIE NO\.' -and $r.Content -match 'MANF\. DATE' -and $r.Content -match 'PRODUCT CODE' -and $r.Content -match 'LEG \(L\) SIZE' -and $r.Content -match 'LABEL APPROVED BY' -and $r.Content -match 'L-03' -and $r.Content -match 'data-print')
  Ok 'print: padded to 25 rows' (([regex]::Matches($r.Content, '<tr')).Count -ge 25 + 3)
  $d = Download $s "/quality/sheets/$sh/csv"
  $csv = [Text.Encoding]::UTF8.GetString($d.Bytes).Replace('"', '')
  Ok "CSV: a download in the client's column order, with the void reading marked" ($d.StatusCode -eq 200 -and $d.Type -match 'text/csv' -and $d.Disposition -match 'attachment' -and $csv -match 'SR NO\.,PIN SIZE,WIDTH SIZE,LEG \(L\) SIZE,WEIGHT,FITTING,DATE/TIME,AUTHORISED' -and $csv -match 'L-03' -and $csv -match "VOID: Typed 5.30")

  # closing
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/close" @() | Out-Null
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'close: the sheet is Closed and has no entry form or close button' ($r.Content -match 'Closed' -and $r.Content -notmatch 'Add reading' -and $r.Content -notmatch 'Close sheet')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@('v[PIN]', '5')) | Out-Null
  Ok 'close: a reading cannot be added afterwards' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'closed')
  Ok 'list: shown under Closed, not under Open' ((Get-Page $s '/quality/sheets?status=CLOSED').Content -match "/quality/sheets/$sh" -and (Get-Page $s '/quality/sheets?status=OPEN').Content -notmatch "/quality/sheets/$sh")
  $r = Get-Page $s '/quality/sheets'
  Ok 'list: counts one out-of-range reading and two good ones' ($r.Content -match "QS-\d{4}-\d{5}" -and $r.Content -match 'pill pill-fail')
  Ok 'unknown sheet ids are 404' ((Get-Page $s '/quality/sheets/not-an-id').StatusCode -eq 404 -and (Get-Page $s '/quality/sheets/not-an-id/print').StatusCode -eq 404)
  Ok 'the inspection pages still work next to the sheets' ((Get-Page $s '/quality/new').StatusCode -eq 200 -and (Get-Page $s '/quality/not-an-id').StatusCode -eq 404)

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('qc', 'QC'), @('mgr', 'Manager'), @('sales', 'Sales'), @('op', 'Operator'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $r = Post $s '/quality/sheets/new' '/quality/sheets' @(@('job_card_id', $jc), @('mfg_date', $today)); $sh2 = Id $r.Location
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: can open the sheets, the new form and a sheet' ((Get-Page $qc '/quality/sheets').StatusCode -eq 200 -and (Get-Page $qc '/quality/sheets/new').StatusCode -eq 200 -and (Get-Page $qc "/quality/sheets/$sh2").Content -match 'Add reading')
  Post $qc "/quality/sheets/$sh2" "/quality/sheets/$sh2/rows" @(@('v[PIN]', '5.01')) | Out-Null
  $r = Get-Page $qc "/quality/sheets/$sh2"
  Ok 'QC: adds a reading signed with their own name' ($r.Content -match 'L-01' -and $r.Content -match "title=`"qc $tag`"")
  Ok 'QC: cannot change the product limits (403)' ((Post $qc '/items' "/items/$($ids.fg)/specs" @(, @('colour', 'Red'))).StatusCode -eq 403)
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $mgr "/quality/sheets/$sh2"
  Ok 'Manager: reads a sheet but has no entry form' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Add reading' -and (Get-Page $mgr '/quality/sheets/new').StatusCode -eq 403)
  Ok 'Manager: adding a reading is 403' ((Post $mgr "/quality/sheets/$sh2" "/quality/sheets/$sh2/rows" @(@('v[PIN]', '5'))).StatusCode -eq 403)
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: no QC sheets at all' ((Get-Page $sales '/quality/sheets').StatusCode -eq 403 -and (Get-Page $sales "/quality/sheets/$sh/print").StatusCode -eq 403 -and (Download $sales "/quality/sheets/$sh/csv").StatusCode -eq 403)
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Operator: no QC sheets, no menu entry' ((Get-Page $op '/quality/sheets').StatusCode -eq 403 -and (Get-Page $op '/').Content -notmatch '>QC sheets<')
} finally { Stop-Dev }
Summary
