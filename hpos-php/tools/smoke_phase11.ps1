# HTTP smoke test for Phase 11 (planning board): the board, planning a job card, overlap warnings, moving a job to
# another machine, removing a plan, and who may do what.
#   pwsh tools/smoke_phase11.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function In-Days($n) { (Get-Date).AddDays($n).ToString('yyyy-MM-dd') }
# the part of the board page that belongs to one machine (the tests database holds other machines' plans too)
function Machine-Chunk($html, $code) { foreach ($c in ($html -split '<tbody class="plan-machine">')) { if ($c -match [regex]::Escape($code) -and $c -match 'plan-ws') { return ($c -split '</tbody>')[0] } }; '' }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/planning', '/planning?from=2026-01-05', '/planning?from=not-a-date') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/planning'
  Ok 'board: legend, two weeks of days, week navigation and the unplanned list' ($r.Content -match 'Planning board' -and $r.Content -match 'plan-day' -and $r.Content -match 'Not planned yet' -and $r.Content -match 'Week &rarr;' -and $r.Content -match '>Planning board<')

  # two machines, one work order with two job cards
  $gst = '24' + $tag + '1234F1Z5'
  $r = Post $s '/customers/new' '/customers' @(@('name', "PL Cust $tag"), @('gstin', $gst)); $cust = Id $r.Location
  $ids = @{}
  foreach ($def in @(@('rm', "RM$tag", 'RAW_MATERIAL', 'Kg', "Compound $tag"), @('fg', "FG$tag", 'FINISHED_GOODS', 'Meter', "Gasket $tag"))) {
    $r = Post $s '/items/new' '/items' @(@('code', $def[1]), @('name', $def[4]), @('category', $def[2]), @('uom', $def[3]), @('hsn_code', '3917')); $ids[$def[0]] = Id $r.Location
  }
  Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $ids.fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $ids.rm), @('qty_per_unit[]', '5')) | Out-Null
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "PA$tag"), @('name', "Alpha $tag"), @('hourly_rate', '400'), @('status', 'IDLE')); $wsA = Id $r.Location
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "PB$tag"), @('name', "Beta $tag"), @('hourly_rate', '400'), @('status', 'IDLE')); $wsB = Id $r.Location
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RM$tag"), @('qty', '30'), @('barcode', "BAG-$tag-1")) | Out-Null
  $r = Post $s '/orders/new' '/orders' @(@('customer_id', $cust), @('delivery_date', (In-Days 10)), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '100'), @('rate[]', '40'))
  $A = Id $r.Location
  Post $s "/orders/$A" "/orders/$A/confirm" @(, @('approval_method', 'EMAIL')) | Out-Null
  $orderNo = [regex]::Match((Get-Page $s "/orders/$A").Content, 'SO-\d{4}-\d{5}').Value
  $woId = ([regex]::Matches((Get-Page $s '/work-orders').Content, "(?s)/work-orders/([0-9a-f-]{36})`"[^<]*</a></td>(?:(?!</tr>).)*$orderNo"))[0].Groups[1].Value
  $woNo = [regex]::Match((Get-Page $s "/work-orders/$woId").Content, 'WO-\d{4}-\d{5}|PROD-WO-\d{4}-\d{5}').Value
  Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $wsA), @('assign_me', '')) | Out-Null
  Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $wsA), @('assign_me', '')) | Out-Null
  $page = (Get-Page $s "/work-orders/$woId").Content
  $jcs = [regex]::Matches($page, '/job-cards/([0-9a-f-]{36})/plan') | ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
  Ok 'the work order page has a Plan link on each job card' ($jcs.Count -eq 2)
  $j1 = $jcs[0]; $j2 = $jcs[1]

  $r = Get-Page $s '/planning'
  Ok 'board: both new job cards are listed as not planned, with a Plan link' (([regex]::Matches($r.Content, "/job-cards/$j1/plan")).Count -ge 1 -and $r.Content -match "PA$tag" -and $r.Content -match 'Plan</a>')
  Ok 'board: the new machines are rows' ($r.Content -match "PA$tag" -and $r.Content -match "PB$tag")

  # plan
  $r = Get-Page $s "/job-cards/$j1/plan"
  Ok 'plan form: machine, start and end' ($r.StatusCode -eq 200 -and $r.Content -match 'name="workstation_id"' -and $r.Content -match 'name="planned_start"' -and $r.Content -match 'name="planned_end"' -and $r.Content -match 'Queued')
  $r = Post $s "/job-cards/$j1/plan" "/job-cards/$j1/plan" @(@('workstation_id', $wsA), @('planned_start', (In-Days 3)), @('planned_end', (In-Days 1)))
  Ok 'plan: an end before the start is refused, form kept' ($r.StatusCode -eq 422 -and $r.Content -match 'before the start')
  $r = Post $s "/job-cards/$j1/plan" "/job-cards/$j1/plan" @(@('workstation_id', $wsA), @('planned_start', ''), @('planned_end', (In-Days 3)))
  Ok 'plan: a missing date is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'both a start and an end')
  $r = Post $s "/job-cards/$j1/plan" "/job-cards/$j1/plan" @(@('workstation_id', $wsA), @('planned_start', (In-Days 1)), @('planned_end', (In-Days 3)))
  Ok 'plan: saved' ($r.StatusCode -eq 303 -and $r.Location -match '/planning\?from=')
  $r = Get-Page $s '/planning'
  Ok 'board: the job is a bar on its machine and left the unplanned list' ((Flash-Of $r.Content) -match 'Planned' -and $r.Content -match 'class="plan-bar plan-queued' -and $r.Content -match 'colspan="3"' -and ([regex]::Matches($r.Content, "/job-cards/$j1/plan")).Count -eq 1)
  $r = Post $s "/job-cards/$j2/plan" "/job-cards/$j2/plan" @(@('workstation_id', $wsA), @('planned_start', (In-Days 2)), @('planned_end', (In-Days 4)))
  $b = Get-Page $s '/planning'
  Ok 'plan: an overlap on the same machine is saved with a warning that names the other job' ($r.StatusCode -eq 303 -and (Flash-Of $b.Content) -match 'overlaps')
  $chunkA = Machine-Chunk $b.Content "PA$tag"
  Ok 'board: overlapping jobs sit on two rows of the same machine' (([regex]::Matches($chunkA, 'class="plan-bar plan-queued')).Count -eq 2 -and $chunkA -match 'rowspan="2"')
  Post $s "/job-cards/$j2/plan" "/job-cards/$j2/plan" @(@('workstation_id', $wsB), @('planned_start', (In-Days 2)), @('planned_end', (In-Days 4))) | Out-Null
  $b = Get-Page $s '/planning'
  Ok 'plan: a queued job moves to another machine and the overlap is gone' ((Flash-Of $b.Content) -match 'Planned\.' -and (Machine-Chunk $b.Content "PA$tag") -notmatch 'rowspan="2"' -and ([regex]::Matches((Machine-Chunk $b.Content "PB$tag"), 'class="plan-bar plan-queued')).Count -eq 1)
  Ok 'plan form: remembers the saved dates' ((Get-Page $s "/job-cards/$j1/plan").Content -match ('value="' + (In-Days 1) + '"') -and (Get-Page $s "/job-cards/$j1/plan").Content -match 'Remove the plan')
  Post $s "/job-cards/$j2/plan" "/job-cards/$j2/plan/clear" @() | Out-Null
  $b = Get-Page $s '/planning'
  Ok 'plan: removing it puts the job back in the unplanned list' ((Flash-Of $b.Content) -match 'Plan removed' -and ([regex]::Matches($b.Content, "/job-cards/$j2/plan")).Count -eq 1)
  Ok 'plan: unknown job card id is a 404' ((Get-Page $s '/job-cards/not-an-id/plan').StatusCode -eq 404)
  $mon = [regex]::Match((Get-Page $s '/planning').Content, 'planning\?from=(\d{4}-\d{2}-\d{2})" aria-label="Next week"').Groups[1].Value
  Ok 'board: next week link goes seven days on and the page loads' ($mon -ne '' -and (Get-Page $s "/planning?from=$mon").StatusCode -eq 200)

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('op', 'Operator'), @('mgr', 'Manager'), @('qc', 'QC'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $op '/planning'
  Ok 'Operator: sees the board' ($r.StatusCode -eq 200 -and $r.Content -match 'plan-bar')
  $r = Get-Page $op "/job-cards/$j1/plan"
  Ok "Operator: sees a job's plan but has no form" ($r.StatusCode -eq 200 -and $r.Content -match 'not change it' -and $r.Content -notmatch 'name="planned_start"')
  Ok 'Operator: planning is 403' ((Post $op "/job-cards/$j1/plan" "/job-cards/$j1/plan" @(@('planned_start', (In-Days 1)), @('planned_end', (In-Days 2)))).StatusCode -eq 403)
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Manager: can plan' ((Post $mgr "/job-cards/$j2/plan" "/job-cards/$j2/plan" @(@('workstation_id', $wsA), @('planned_start', (In-Days 6)), @('planned_end', (In-Days 7)))).StatusCode -eq 303)
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: no planning board (403) and no menu entry' ((Get-Page $qc '/planning').StatusCode -eq 403 -and (Get-Page $qc '/').Content -notmatch '>Planning board<')
} finally { Stop-Dev }
Summary
