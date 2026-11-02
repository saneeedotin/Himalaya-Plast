# HTTP smoke test for Phase 16 (the QC sheet the client shapes, one sheet per machine): the setup screen, adding a field
# and using it on a sheet, renaming the header words so the sheet, the print and the CSV follow, the machine board, and who may do what.
#   pwsh tools/smoke_phase16.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Download($session, $path) { $resp = $session.GetAsync("$base$path").GetAwaiter().GetResult(); [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Bytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult() } }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $today = (Get-Date).ToString('yyyy-MM-dd')
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/quality/setup', '/quality/machines', '/quality/sheets') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/quality/machines'
  Ok 'menu: QC machines and QC sheets are both listed' ($r.Content -match '>QC machines<' -and $r.Content -match '>QC sheets<')
  $r = Get-Page $s '/quality/setup'
  Ok 'setup: the five starting fields, the add form and the header words are there' ($r.Content -match 'What is measured' -and $r.Content -match 'value="Pin size"' -and $r.Content -match 'value="Fitting"' -and $r.Content -match 'Add something to measure' -and $r.Content -match 'name="label\[DIE_NO\]"' -and $r.Content -match 'value="Label approved by :"')

  # a running job with a batch on its own machine
  Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE')) | Out-Null
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
  Ok 'setup: a job card exists on the new machine' ($jc -ne '')
  Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor')) | Out-Null
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '10'), @('back', '/shop-floor')) | Out-Null

  # the machine board
  $r = Get-Page $s '/quality/machines'
  $row = ([regex]::Matches($r.Content, '(?s)<tr>(?:(?!</tr>).)*WS' + $tag + '(?:(?!</tr>).)*</tr>'))[0].Value
  Ok 'machines: the new machine is listed with its running batch, no sheet yet, and a Start sheet link' ($row -match 'BATCH-FG-' -and $row -match 'No open sheet' -and $row -match "sheets/new\?job_card=$jc" -and $row -match 'On time')

  # a new measured field
  $r = Post $s '/quality/setup' '/quality/setup/fields' @(@('code', "T$tag"), @('label', "Thickness $tag"), @('kind', 'NUMBER'), @('unit', 'mm'), @('sort_no', '90'), @('has_limits', '1'), @('is_active', '1'))
  Ok 'setup: a number field is added' ($r.StatusCode -eq 303 -and (Flash-Of (Get-Page $s '/quality/setup').Content) -match 'Field added')
  $page = (Get-Page $s '/quality/setup').Content
  Ok 'setup: the new field is listed with its unit and "has limits" ticked' ($page -match "value=`"Thickness $tag`"" -and $page -match "(?s)value=`"T$tag`" readonly.{0,900}value=`"mm`".{0,200}name=`"has_limits`" value=`"1`" checked")
  Post $s '/quality/setup' '/quality/setup/fields' @(@('code', "T$tag"), @('label', 'Again'), @('kind', 'NUMBER'), @('is_active', '1')) | Out-Null
  Ok 'setup: a code already used is refused' ((Flash-Of (Get-Page $s '/quality/setup').Content) -match 'already used')
  Post $s '/quality/setup' '/quality/setup/fields' @(@('code', 'bad code'), @('label', 'X'), @('kind', 'NUMBER'), @('is_active', '1')) | Out-Null
  Ok 'setup: a code with a space or small letters is refused' ((Flash-Of (Get-Page $s '/quality/setup').Content) -match 'capital letters')
  Post $s '/quality/setup' '/quality/setup/fields' @(@('code', "CH$tag"), @('label', 'Look'), @('kind', 'CHOICE'), @('choices', 'OK, not ok'), @('is_active', '1')) | Out-Null
  Ok 'setup: a choice with a badly written list is refused' ((Flash-Of (Get-Page $s '/quality/setup').Content) -match 'VALUE=Name')
  Post $s '/quality/setup' '/quality/setup/fields' @(@('code', "CH$tag"), @('label', "Look $tag"), @('kind', 'CHOICE'), @('choices', 'GOOD=Good;SCRATCH=Scratched'), @('bad_values', 'SCRATCH'), @('sort_no', '91'), @('is_active', '1')) | Out-Null
  Ok 'setup: a choice field is added' ((Get-Page $s '/quality/setup').Content -match "value=`"Look $tag`"")

  # limits on the product for the new field
  $r = Get-Page $s "/items/$($ids.fg)"
  Ok 'item: the limits form lists the new field but not the choice' ($r.Content -match "name=`"T${tag}_min`"" -and $r.Content -notmatch "name=`"CH${tag}_min`"" -and $r.Content -match 'name="PIN_min"')
  Post $s "/items/$($ids.fg)" "/items/$($ids.fg)/specs" @(@('colour', 'Black'), @("T${tag}_min", '1.5'), @("T${tag}_max", '2.5')) | Out-Null
  Ok 'item: limits on the new field are saved' ((Get-Page $s "/items/$($ids.fg)").Content -match 'value="1.5"')

  # a sheet with the new field on it
  $r = Post $s '/quality/sheets/new' '/quality/sheets' @(@('job_card_id', $jc), @('mfg_date', $today))
  Ok 'sheet: started from the machine board link' ($r.StatusCode -eq 303); $sh = Id $r.Location
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'sheet: the entry form has the new number and the new choice' ($r.Content -match "name=`"v\[T$tag\]`"" -and $r.Content -match "name=`"v\[CH$tag\]`"" -and $r.Content -match 'Scratched' -and $r.Content -match "Thickness $tag")
  Ok 'sheet: the machine shows on the header' ($r.Content -match "WS$tag")
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@("v[T$tag]", '2.0'), @("v[CH$tag]", 'GOOD')) | Out-Null
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'reading: a thickness inside the limits is not flagged' ($r.Content -match 'L-01' -and $r.Content -notmatch 'class="num out"' -and $r.Content -notmatch 'is outside the limits')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@("v[T$tag]", '1.2'), @("v[CH$tag]", 'SCRATCH')) | Out-Null
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'reading: a thin gasket and a scratched look are flagged' ($r.Content -match 'L-02' -and ([regex]::Matches($r.Content, 'class="num out"')).Count -eq 1 -and ([regex]::Matches($r.Content, 'class=" out"')).Count -eq 1 -and $r.Content -match '1 reading is outside the limits')
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/rows" @(@("v[CH$tag]", 'DENTED')) | Out-Null
  Ok 'reading: a choice that is not in the list is refused' ((Flash-Of (Get-Page $s "/quality/sheets/$sh").Content) -match 'listed choices')

  # renaming the words on the sheet
  Post $s '/quality/setup' '/quality/setup/labels' @(@('label[DIE_NO]', "Mould $tag"), @('visible[DIE_NO]', '1'), @('label[X1]', "Operator $tag"), @('visible[X1]', '1')) | Out-Null
  $page = (Get-Page $s '/quality/setup').Content
  Ok 'setup: the renamed words are in the form' ($page -match "value=`"Mould $tag`"" -and $page -match "value=`"Operator $tag`"")
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'sheet: the header uses the new word' ($r.Content -match "Mould $tag")
  $r = Get-Page $s "/quality/sheets/$sh/print"
  Ok 'print: the header and the new field follow the setup' ($r.Content -match "MOULD $($tag.ToUpper()):" -and $r.Content -match "THICKNESS $($tag.ToUpper())" -and $r.Content -match "LOOK $($tag.ToUpper())")
  $d = Download $s "/quality/sheets/$sh/csv"
  $csv = [Text.Encoding]::UTF8.GetString($d.Bytes).Replace('"', '')
  Ok 'CSV: the new word, the new column and the readings follow the setup' ($d.StatusCode -eq 200 -and $csv -match "MOULD $($tag.ToUpper())" -and $csv -match "THICKNESS $($tag.ToUpper()) \(MM\)" -and $csv -match 'Scratched')
  $r = Get-Page $s '/quality/sheets/new'
  Ok 'new sheet: the spare header field that was switched on is asked for' ($r.Content -match 'name="x1"' -and $r.Content -match "Operator $tag")
  # put the words back
  Post $s '/quality/setup' '/quality/setup/labels' @(@('label[DIE_NO]', 'Die no.'), @('visible[DIE_NO]', '1'), @('label[X1]', 'Extra 1')) | Out-Null
  Ok 'setup: the words are put back and the spare field is hidden again' ((Get-Page $s '/quality/sheets/new').Content -notmatch 'name="x1"')

  # the machine board with a sheet, and the sheets filtered by machine
  $r = Get-Page $s '/quality/machines'
  $row = ([regex]::Matches($r.Content, '(?s)<tr>(?:(?!</tr>).)*WS' + $tag + '(?:(?!</tr>).)*</tr>'))[0].Value
  Ok 'machines: the machine now shows its open sheet, 2 readings and an Add reading link' ($row -match 'QS-\d{4}-\d{5}' -and $row -match 'Add reading' -and $row -match '<td class="num">2</td>' -and $row -notmatch 'Start sheet')
  $r = Get-Page $s "/quality/sheets?machine=$ws"
  Ok 'sheets: filtering by machine lists only that machine''s sheets' ($r.Content -match "/quality/sheets/$sh" -and $r.Content -match "WS$tag")
  $r = Get-Page $s '/quality/sheets?machine=00000000-0000-0000-0000-000000000000'
  Ok 'sheets: another machine has none' ($r.Content -notmatch "/quality/sheets/$sh" -and $r.Content -match 'Show every machine')

  # switch the extra fields off again (a sheet holds at most 12 fields)
  $page = (Get-Page $s '/quality/setup').Content
  foreach ($code in @("T$tag", "CH$tag")) {
    $m = [regex]::Match($page, "name=`"id`" value=`"([0-9a-f-]{36})`">\s*<input type=`"hidden`" name=`"kind`" value=`"(\w+)`">\s*<div><label>Code</label><input type=`"text`" value=`"$code`"")
    if ($m.Success) {
      $pairs = @(@('id', $m.Groups[1].Value), @('kind', $m.Groups[2].Value), @('label', "Off $code"), @('sort_no', '90'))
      if ($m.Groups[2].Value -eq 'CHOICE') { $pairs += @(, @('choices', 'GOOD=Good;SCRATCH=Scratched')); $pairs += @(, @('bad_values', 'SCRATCH')) } else { $pairs += @(, @('has_limits', '1')) }
      Post $s '/quality/setup' '/quality/setup/fields' $pairs | Out-Null
    }
  }
  $r = Get-Page $s "/quality/sheets/$sh"
  Ok 'setup: a field switched off stays on the sheet that has readings for it' ($r.Content -match "Off T$tag" -and $r.Content -notmatch "name=`"v\[T$tag\]`"")
  Post $s "/quality/sheets/$sh" "/quality/sheets/$sh/close" @() | Out-Null
  $r = Post $s '/quality/sheets/new' '/quality/sheets' @(@('job_card_id', $jc), @('mfg_date', $today)); $sh2 = Id $r.Location
  Ok 'a new sheet no longer has the field that was switched off' ((Get-Page $s "/quality/sheets/$sh2").Content -notmatch "Off T$tag")

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('qc', 'QC'), @('mgr', 'Manager'), @('sales', 'Sales'), @('op', 'Operator'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $qc '/quality/setup'
  Ok 'QC: can open the setup, with the add form' ($r.StatusCode -eq 200 -and $r.Content -match 'Add something to measure' -and (Get-Page $qc '/quality/machines').StatusCode -eq 200)
  $r = Post $qc '/quality/setup' '/quality/setup/labels' @(@('label[COLOUR]', 'Colour'), @('visible[COLOUR]', '1'))
  Ok 'QC: can save labels' ($r.StatusCode -eq 303)
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $mgr '/quality/setup'
  Ok 'Manager: reads the setup but has no add form and no save button' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Add something to measure' -and $r.Content -notmatch 'Save labels')
  Ok 'Manager: changing a field or a label is 403' ((Post $mgr '/quality/setup' '/quality/setup/fields' @(@('code', "ZZ$tag"), @('label', 'Z'), @('kind', 'NUMBER'))).StatusCode -eq 403 -and (Post $mgr '/quality/setup' '/quality/setup/labels' @(@('label[COLOUR]', 'Hacked'))).StatusCode -eq 403)
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: no setup and no machine board' ((Get-Page $sales '/quality/setup').StatusCode -eq 403 -and (Get-Page $sales '/quality/machines').StatusCode -eq 403)
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Operator: no setup, no machine board, no menu entry' ((Get-Page $op '/quality/setup').StatusCode -eq 403 -and (Get-Page $op '/quality/machines').StatusCode -eq 403 -and (Get-Page $op '/').Content -notmatch '>QC machines<')
} finally { Stop-Dev }
Summary
