# HTTP smoke test for Phase 1 (users, customers, suppliers, leads, items, BOMs, workstations, dies).
# Starts the dev server on :8099, signs in as the admin and drives the real pages: create, edit,
# delete, validation errors, 403s for a limited role. See smoke_lib.ps1 for requirements.
#   pwsh tools/smoke_phase1.ps1
. "$PSScriptRoot\smoke_lib.ps1"
Start-Dev
try {
  $s = New-Session
  $r = Post $s '/login' '/login' @(@('email', $creds[0]), @('password', $creds[1]))
  Ok 'admin login' ($r.StatusCode -eq 303) $r.StatusCode

  foreach ($p in '/', '/users', '/customers', '/suppliers', '/leads', '/items', '/items?category=RAW_MATERIAL', '/boms', '/workstations', '/dies', '/users/new', '/customers/new', '/suppliers/new', '/leads/new', '/items/new', '/boms/new', '/workstations/new', '/dies/new') {
    $r = Get-Page $s $p
    Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)"
  }
  $r = Get-Page $s '/'
  Ok 'menu lists masters and admin' ($r.Content -match 'Customers' -and $r.Content -match 'Workstations' -and $r.Content -match '>Users<')

  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ })
  $gstin = '24' + $tag + '1234' + 'F' + '1Z5'

  # customers
  $r = Post $s '/customers/new' '/customers' @(@('name', "Cust $tag"), @('gstin', 'BADGST'))
  Ok 'customer: bad GSTIN shows an error, form kept' ($r.StatusCode -eq 422 -and $r.Content -match 'GSTIN' -and $r.Content -match "Cust $tag") "$($r.StatusCode)"
  $r = Post $s '/customers/new' '/customers' @(@('name', "Cust $tag"), @('gstin', $gstin), @('email', 'a@b.co'), @('phone', '99'))
  Ok 'customer: create redirects to detail' ($r.StatusCode -eq 303 -and (Loc $r) -match '/customers/[0-9a-f-]{36}') (Loc $r)
  $cid = Id (Loc $r)
  $r = Post $s '/customers/new' '/customers' @(@('name', "Dup $tag"), @('gstin', $gstin))
  Ok 'customer: duplicate GSTIN is a readable error' ($r.StatusCode -eq 422 -and $r.Content -match 'already exists') "$($r.StatusCode)"
  $r = Get-Page $s "/customers/$cid"
  Ok 'customer: detail shows data' ($r.StatusCode -eq 200 -and $r.Content -match "Cust $tag" -and $r.Content -match $gstin)
  $r = Post $s "/customers/$cid" "/customers/$cid/follow-ups" @(@('notes', 'Call back about rate'), @('expected_next', '2026-12-01'))
  Ok 'follow-up: logged' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/customers/$cid"
  Ok 'follow-up: appears on the page' ($r.Content -match 'Call back about rate')
  $fid = [regex]::Match($r.Content, '/follow-ups/([0-9a-f-]{36})/status').Groups[1].Value
  $r = Post $s "/customers/$cid" "/follow-ups/$fid/status" @(@('customer_id', $cid), @('status', 'COMPLETED'))
  Ok 'follow-up: mark done' ($r.StatusCode -eq 303)
  $r = Post $s "/customers/$cid" "/customers/$cid" @(@('name', "Cust $tag Ltd"), @('gstin', $gstin))
  Ok 'customer: update' ($r.StatusCode -eq 303)
  $r = Post $s "/customers/$cid" "/follow-ups/$fid/delete" @(, @('customer_id', $cid))
  Ok 'follow-up: delete' ($r.StatusCode -eq 303)

  # suppliers and leads
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE'))
  Ok 'supplier: create' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $sid = Id (Loc $r)
  $r = Get-Page $s "/suppliers/$sid"
  Ok 'supplier: detail' ($r.StatusCode -eq 200 -and $r.Content -match "Sup $tag")
  $r = Post $s '/leads/new' '/leads' @(@('company_name', "Lead $tag"), @('status', 'NEW'), @('next_follow_up', '2026-12-02T10:00'))
  Ok 'lead: create' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $r = Get-Page $s '/leads'
  Ok 'lead: listed' ($r.Content -match "Lead $tag")
  $lid = [regex]::Match($r.Content, '/leads/([0-9a-f-]{36})/edit').Groups[1].Value
  $r = Post $s "/leads/$lid/edit" "/leads/$lid" @(@('company_name', "Lead $tag"), @('status', 'QUALIFIED'))
  Ok 'lead: update' ($r.StatusCode -eq 303)

  # items, BOM
  $r = Post $s '/items/new' '/items' @(@('code', "RM$tag"), @('name', "Compound $tag"), @('category', 'RAW_MATERIAL'), @('uom', 'Kg'), @('standard_cost', '90'))
  Ok 'item: create raw material' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $rm = Id (Loc $r)
  $r = Post $s '/items/new' '/items' @(@('code', "FG$tag"), @('name', "Drip $tag"), @('category', 'FINISHED_GOODS'), @('uom', 'Meter'))
  $fg = Id (Loc $r)
  Ok 'item: create finished good' ($r.StatusCode -eq 303)
  $r = Post $s '/items/new' '/items' @(@('code', "FG$tag"), @('name', 'x'), @('category', 'FINISHED_GOODS'), @('uom', 'M'))
  Ok 'item: duplicate code is a readable error' ($r.StatusCode -eq 422 -and $r.Content -match 'already exists')
  $r = Post $s '/items/new' '/items' @(@('code', "Q$tag"), @('name', 'x'), @('category', 'SCRAP'), @('uom', 'Kg'), @('qc_template', '{bad'))
  Ok 'item: invalid QC JSON refused' ($r.StatusCode -eq 422 -and $r.Content -match 'JSON')
  $r = Get-Page $s "/items/$rm"
  Ok 'item: detail' ($r.StatusCode -eq 200 -and $r.Content -match "Compound $tag")
  $r = Get-Page $s '/items?category=RAW_MATERIAL'
  Ok 'item: category filter lists it' ($r.Content -match "RM$tag")
  $r = Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $rm), @('qty_per_unit[]', '5'))
  Ok 'bom: create' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $bid = Id (Loc $r)
  $r = Post $s '/boms/new' '/boms' @(@('name', "Bad $tag"), @('fg_item_id', $fg), @('output_qty', '100'), @('scrap_percent', '150'), @('rm_item_id[]', $rm), @('qty_per_unit[]', '5'))
  Ok 'bom: scrap factor >= 1 refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Scrap must be')
  $r = Get-Page $s "/boms/$bid"
  Ok 'bom: detail' ($r.StatusCode -eq 200 -and $r.Content -match "Compound $tag")
  $r = Get-Page $s "/boms/$bid/edit"
  Ok 'bom: edit form pre-fills trimmed numbers' ($r.StatusCode -eq 200 -and $r.Content -match 'value="5"' -and $r.Content -match 'name="scrap_percent" type="number" value="2.5"' -and $r.Content -notmatch '5.000000')
  $r = Post $s "/boms/$bid/edit" "/boms/$bid" @(@('name', "BOM $tag v2"), @('fg_item_id', $fg), @('output_qty', '100'), @('scrap_percent', '3'), @('rm_item_id[]', $rm), @('qty_per_unit[]', '6'))
  Ok 'bom: update' ($r.StatusCode -eq 303)

  # workstations and dies
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "WS$tag"), @('name', "Line $tag"), @('hourly_rate', '450'), @('status', 'IDLE'))
  Ok 'workstation: create' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $wid = Id (Loc $r)
  $r = Get-Page $s "/workstations/$wid"
  Ok 'workstation: detail' ($r.StatusCode -eq 200 -and $r.Content -match "Line $tag")
  $r = Get-Page $s '/workstations'
  Ok 'workstation: listed with downtime columns' ($r.Content -match "WS$tag")
  $r = Post $s '/dies/new' '/dies' @(@('code', "D$tag"), @('name', "Die $tag"), @('maintenance_threshold_hours', '5000'), @('status', 'ACTIVE'))
  Ok 'die: create' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $r = Get-Page $s '/dies'
  $did = [regex]::Match($r.Content, '/dies/([0-9a-f-]{36})/edit').Groups[1].Value
  Ok 'die: listed' ($r.Content -match "D$tag")
  $r = Post $s '/dies' "/dies/$did/delete" @()
  Ok 'die: delete' ($r.StatusCode -eq 303)

  # users and permissions
  $roles = [regex]::Matches((Get-Page $s '/users/new').Content, 'name="roles\[\]" value="([0-9a-f-]{36})"')
  $r = Get-Page $s '/users/new'
  $operatorId = ''
  foreach ($m in [regex]::Matches($r.Content, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq 'Operator') { $operatorId = $m.Groups[1].Value } }
  Ok 'user form lists roles' ($operatorId -ne '')
  $email = "op.$($tag.ToLower())@test.local"
  $r = Post $s '/users/new' '/users' @(@('name', "Op $tag"), @('email', $email), @('password', 'short'), @('roles[]', $operatorId))
  Ok 'user: short password refused' ($r.StatusCode -eq 422 -and $r.Content -match 'at least 10')
  $r = Post $s '/users/new' '/users' @(@('name', "Op $tag"), @('email', $email), @('password', 'Operator-Pass-123'), @('roles[]', $operatorId))
  Ok 'user: create' ($r.StatusCode -eq 303) "$($r.StatusCode)"
  $r = Post $s '/users/new' '/users' @(@('name', "Op $tag"), @('email', $email), @('password', 'Operator-Pass-123'), @('roles[]', $operatorId))
  Ok 'user: duplicate email refused' ($r.StatusCode -eq 422 -and $r.Content -match 'already exists')
  $r = Post $s '/users/new' '/users' @(@('name', "NoRole $tag"), @('email', "nr.$($tag.ToLower())@test.local"), @('password', 'Operator-Pass-123'))
  Ok 'user: no role refused' ($r.StatusCode -eq 422 -and $r.Content -match 'role')
  $r = Get-Page $s '/users'
  $uid = [regex]::Match($r.Content, '/users/([0-9a-f-]{36})/edit[^<]*</a></td></tr>(?s:.*?)' + [regex]::Escape($email)).Groups[1].Value
  $uid = ([regex]::Matches($r.Content, "(?s)<tr>(?:(?!</tr>).)*$([regex]::Escape($email))(?:(?!</tr>).)*/users/([0-9a-f-]{36})/edit"))[0].Groups[1].Value
  Ok 'user: listed with edit link' ($uid -ne '')

  $o = New-Session
  $r = Post $o '/login' '/login' @(@('email', $email), @('password', 'Operator-Pass-123'))
  Ok 'operator can sign in' ($r.StatusCode -eq 303)
  $r = Get-Page $o '/'
  Ok 'operator menu hides Users, Customers and BOMs' ($r.Content -notmatch '>Users<' -and $r.Content -notmatch '>Customers<' -and $r.Content -notmatch '>BOMs<' -and $r.Content -match '>Items<')
  foreach ($p in '/users', '/customers', '/boms', '/customers/new', '/items/new') {
    $r = Get-Page $o $p
    Ok "operator GET $p is 403" ($r.StatusCode -eq 403) "$($r.StatusCode)"
  }
  $r = Get-Page $o '/items'
  Ok 'operator GET /items is 200' ($r.StatusCode -eq 200)
  $r = Post $o '/items' '/items' @(@('code', "X$tag"), @('name', 'x'), @('category', 'SCRAP'), @('uom', 'Kg'))
  Ok 'operator POST /items is refused' ($r.StatusCode -eq 403) "$($r.StatusCode)"

  # my profile: any signed-in user, own details only
  $r = Get-Page $o '/account'
  Ok 'profile: operator sees their own profile, email and role' ($r.StatusCode -eq 200 -and $r.Content -match [regex]::Escape($email) -and $r.Content -match 'Recent sign-ins')
  $r = Post $o '/account' '/account' @(@('name', "  Renamed $tag  "), @('phone', '+91 98765-43210'))
  Ok 'profile: save name and phone' ($r.StatusCode -eq 303)
  $r = Get-Page $o '/account'
  Ok 'profile: new name and phone are shown' ($r.Content -match "Renamed $tag" -and $r.Content -match '\+91 98765-43210')
  $r = Post $o '/account' '/account' @(@('name', 'X'), @('phone', 'call me'))
  Ok 'profile: a phone with letters is refused' ($r.StatusCode -eq 303 -and (Get-Page $o '/account').Content -match 'digits, spaces')
  $r = Post $o '/account' '/account' @(@('name', '   '), @('phone', ''))
  Ok 'profile: an empty name is refused' ($r.StatusCode -eq 303 -and (Get-Page $o '/account').Content -match 'Name is required')
  $r = Post $o '/account' '/account' @(@('name', "Renamed $tag"), @('phone', ''))
  Ok 'profile: phone can be cleared' ($r.StatusCode -eq 303 -and (Get-Page $o '/account').Content -notmatch '\+91 98765')
  $r = Post $o '/account' '/account/password' @(@('current_password', 'not-my-password'), @('new_password', 'Another-Pass-123'), @('confirm_password', 'Another-Pass-123'))
  Ok 'profile: wrong current password is refused on the profile page' ($r.StatusCode -eq 422 -and $r.Content -match 'current password is incorrect' -and $r.Content -match 'My profile')
  $r = Get-Page $o '/account/password'
  Ok 'profile: the old password URL goes to the profile' ($r.StatusCode -eq 303 -and $r.Location -match '/account')
  $r = Post $s "/users/$uid/edit" "/users/$uid" @(@('roles[]', $operatorId))
  Ok 'user: deactivate (is_active omitted)' ($r.StatusCode -eq 303)
  $o2 = New-Session
  $r = Post $o2 '/login' '/login' @(@('email', $email), @('password', 'Operator-Pass-123'))
  Ok 'deactivated user cannot sign in' ($r.StatusCode -eq 401 -and $r.Content -match 'deactivated')
  $r = Get-Page $o '/'
  Ok 'deactivated user was signed out' ($r.StatusCode -eq 303)

  $r = Post $s "/customers/$cid" "/customers/$cid/delete" @()
  Ok 'customer: delete' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/customers/$cid"
  Ok 'deleted customer is a 404' ($r.StatusCode -eq 404)
  $r = Get-Page $s '/customers/not-a-real-id'
  Ok 'unknown id is a 404, not a crash' ($r.StatusCode -eq 404) "$($r.StatusCode)"
} finally { Stop-Dev }
Summary
