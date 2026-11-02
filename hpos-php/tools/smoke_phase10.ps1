# HTTP smoke test for Phase 10 (job-card checklist): the page, ticking and undoing steps, the air pressure,
# the shop-floor counter, and who may do what.
#   pwsh tools/smoke_phase10.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)

  # a queued job card
  $gst = '24' + $tag + '1234F1Z5'
  $r = Post $s '/customers/new' '/customers' @(@('name', "CL Cust $tag"), @('gstin', $gst)); $cust = Id $r.Location
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
  $wop = (Get-Page $s "/work-orders/$woId").Content
  $jc = [regex]::Match($wop, '/job-cards/([0-9a-f-]{36})/checklist').Groups[1].Value
  Ok 'the work order page links each job card to its checklist' ($jc -ne '')

  $r = Get-Page $s "/job-cards/$jc/checklist"
  Ok 'checklist: all eight steps, 0 of 8, with the job details' ($r.StatusCode -eq 200 -and $r.Content -match 'Material verification' -and $r.Content -match 'Material change' -and $r.Content -match 'Hopper loading' -and $r.Content -match 'Die change' -and $r.Content -match 'Air pressure check' -and $r.Content -match 'Trial production' -and $r.Content -match 'First quality verification' -and $r.Content -match 'Production started' -and $r.Content -match '<strong>0 of 8</strong>' -and $r.Content -match "FG$tag")
  Ok 'checklist: only the two optional steps offer Not needed' (([regex]::Matches($r.Content, 'value="NOT_NEEDED"')).Count -eq 2)
  Post $s "/job-cards/$jc/checklist" "/job-cards/$jc/checklist" @(@('step', 'MATERIAL_VERIFY'), @('action', 'DONE'), @('note', "Lot $tag")) | Out-Null
  Post $s "/job-cards/$jc/checklist" "/job-cards/$jc/checklist" @(@('step', 'DIE_CHANGE'), @('action', 'NOT_NEEDED')) | Out-Null
  Post $s "/job-cards/$jc/checklist" "/job-cards/$jc/checklist" @(@('step', 'AIR_PRESSURE'), @('action', 'DONE'), @('reading', '6.5')) | Out-Null
  $r = Get-Page $s "/job-cards/$jc/checklist"
  Ok 'checklist: three steps done, with note, pressure and who' ($r.Content -match '<strong>3 of 8</strong>' -and $r.Content -match "Lot $tag" -and $r.Content -match '<strong>6.5 bar</strong>' -and $r.Content -match 'Not needed')
  Post $s "/job-cards/$jc/checklist" "/job-cards/$jc/checklist" @(@('step', 'AIR_PRESSURE'), @('action', 'DONE'), @('reading', 'abc')) | Out-Null
  Ok 'checklist: a pressure that is not a number is refused' ((Flash-Of (Get-Page $s "/job-cards/$jc/checklist").Content) -match 'must be a number')
  Post $s "/job-cards/$jc/checklist" "/job-cards/$jc/checklist" @(@('step', 'AIR_PRESSURE'), @('action', 'NOT_NEEDED')) | Out-Null
  Ok 'checklist: a step that cannot be skipped is refused' ((Flash-Of (Get-Page $s "/job-cards/$jc/checklist").Content) -match 'not needed')
  Post $s "/job-cards/$jc/checklist" "/job-cards/$jc/checklist" @(@('step', 'DIE_CHANGE'), @('action', 'RESET')) | Out-Null
  Ok 'checklist: a step can be undone (2 of 8)' ((Get-Page $s "/job-cards/$jc/checklist").Content -match '<strong>2 of 8</strong>')

  # the shop floor counter
  Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor')) | Out-Null
  $board = Get-Page $s '/shop-floor'
  Ok 'shop floor: the card shows Checklist 2/8 and links to it' ($board.Content -match "/job-cards/$jc/checklist" -and $board.Content -match 'Checklist 2/8')
  Ok 'checklist: a running job with steps left says so' ((Get-Page $s "/job-cards/$jc/checklist").Content -match 'unfinished')
  Ok 'checklist: unknown job card id is a 404' ((Get-Page $s '/job-cards/not-an-id/checklist').StatusCode -eq 404)

  # completed jobs are final
  Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'COMPLETED'), @('back', '/shop-floor')) | Out-Null
  $r = Get-Page $s "/job-cards/$jc/checklist"
  Ok 'checklist: a completed job shows no buttons' ($r.Content -notmatch 'Mark done' -and $r.Content -match 'Completed')

  # roles
  $jc2 = ''
  Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $ws), @('assign_me', '')) | Out-Null
  $jc2 = ([regex]::Matches((Get-Page $s "/work-orders/$woId").Content, '/job-cards/([0-9a-f-]{36})/checklist') | ForEach-Object { $_.Groups[1].Value } | Where-Object { $_ -ne $jc } | Select-Object -First 1)
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('op', 'Operator'), @('mgr', 'Manager'), @('sales', 'Sales'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Post $op "/job-cards/$jc2/checklist" "/job-cards/$jc2/checklist" @(@('step', 'TRIAL_PRODUCTION'), @('action', 'DONE')) | Out-Null
  $r = Get-Page $op "/job-cards/$jc2/checklist"
  Ok 'Operator: ticks a step, signed with their own name' ($r.Content -match '<strong>1 of 8</strong>' -and $r.Content -match "op $tag")
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $mgr "/job-cards/$jc2/checklist"
  Ok 'Manager: reads the checklist but has no buttons' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Mark done')
  Ok 'Manager: ticking a step is 403' ((Post $mgr "/job-cards/$jc2/checklist" "/job-cards/$jc2/checklist" @(@('step', 'MATERIAL_VERIFY'), @('action', 'DONE'))).StatusCode -eq 403)
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: no checklist (403)' ((Get-Page $sales "/job-cards/$jc2/checklist").StatusCode -eq 403)
} finally { Stop-Dev }
Summary
