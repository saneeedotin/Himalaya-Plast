# HTTP smoke test for Phase 12 (repeat-order tracking and customer leads): the Reorder watch list, creating a
# follow-up on request, the customer page headline, the leads list with convert to customer, and who may do what.
#   pwsh tools/smoke_phase12.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }

# customers with known order histories come from the database test, so make sure there are some
& $php "$root\tools\insight_test.php" | Out-Null

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/reorders', '/customer-leads', '/customer-leads/new', '/customers') { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/reorders'
  Ok 'menu: Reorder watch and Customer leads are under Sales' ($r.Content -match '>Reorder watch<' -and $r.Content -match '>Customer leads<')
  Ok 'reorder watch: counts, statuses and the explanation' ($r.Content -match 'Due to reorder' -and $r.Content -match 'Gone quiet' -and $r.Content -match 'Reorder soon' -and $r.Content -match 'Nothing is sent')
  $due = [regex]::Match($r.Content, '/reorders/([0-9a-f-]{36})/follow-up').Groups[1].Value
  Ok 'reorder watch: a Create follow-up button for a customer who is due' ($due -ne '')

  # create the follow-up on request
  $page = (Get-Page $s "/customers/$due").Content
  Ok 'customer page: the ordering pattern headline with usual gap and the button' ($page -match 'Ordering pattern' -and $page -match 'Usual gap' -and $page -match 'Expected next order' -and $page -match 'Create a reorder follow-up')
  $r = Post $s "/customers/$due" "/reorders/$due/follow-up" @()
  Ok 'follow-up: created only when asked, and it takes you to the customer' ($r.StatusCode -eq 303 -and $r.Location -match "/customers/$due")
  $page = (Get-Page $s "/customers/$due").Content
  Ok 'follow-up: the note carries the numbers and a reminder is set' ($page -match 'Reorder watch: usually orders every' -and (Flash-Of $page) -match 'Follow-up created')
  Post $s "/customers/$due" "/reorders/$due/follow-up" @() | Out-Null
  Ok 'follow-up: pressing it again is refused' ((Flash-Of (Get-Page $s "/customers/$due").Content) -match 'already open')
  $r = Get-Page $s '/reorders'
  Ok 'reorder watch: shows "Follow-up open" for that customer instead of the button' ($r.Content -notmatch "/reorders/$due/follow-up" -and $r.Content -match 'Follow-up open')
  $r = Get-Page $s '/customers'
  Ok 'customer list: worked-out dates and a status pill' ($r.Content -match '\(worked out\)' -and $r.Content -match 'Due to reorder')
  $r = Get-Page $s '/notifications'
  Ok 'notifications: the customers-due alert is there' ($r.Content -match 'customers due to reorder')

  # leads
  $r = Post $s '/customer-leads/new' '/customer-leads' @(@('company_name', ''), @('status', 'NEW'))
  Ok 'lead: a company name is required' ($r.StatusCode -eq 422 -and $r.Content -match 'company name is required')
  $r = Post $s '/customer-leads/new' '/customer-leads' @(@('company_name', "Lead $tag"), @('email', 'not-an-email'), @('status', 'NEW'))
  Ok 'lead: a bad email is refused, form kept' ($r.StatusCode -eq 422 -and $r.Content -match 'email address' -and $r.Content -match "Lead $tag")
  $r = Post $s '/customer-leads/new' '/customer-leads' @(@('company_name', "Lead $tag"), @('contact_name', 'Ravi'), @('phone', '9811111111'), @('email', "lead.$t@example.com"), @('source', 'Trade fair'), @('status', 'NEW'), @('next_follow_up', (Get-Date).AddHours(-2).ToString('yyyy-MM-ddTHH:mm')))
  Ok 'lead: added' ($r.StatusCode -eq 303)
  $r = Get-Page $s '/customer-leads'
  Ok 'leads list: the lead with a Due pill for its follow-up' ($r.Content -match "Lead $tag" -and $r.Content -match 'pill pill-fail">Due<')
  $lid = ([regex]::Matches($r.Content, "(?s)Lead $tag.*?/customer-leads/([0-9a-f-]{36})/edit"))[0].Groups[1].Value
  $r = Get-Page $s "/customer-leads/$lid/edit"
  Ok 'lead form: Won is not a choice (only Convert to customer wins a lead)' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'value="WON"' -and $r.Content -match 'value="QUOTED"')
  Post $s "/customer-leads/$lid/edit" "/customer-leads/$lid" @(@('company_name', "Lead $tag"), @('contact_name', 'Ravi'), @('phone', '9811111111'), @('email', "lead.$t@example.com"), @('status', 'QUOTED')) | Out-Null
  Ok 'lead: moved to Quoted, and the Quoted tab shows it' ((Get-Page $s '/customer-leads?status=QUOTED').Content -match "Lead $tag" -and (Get-Page $s '/customer-leads?status=LOST').Content -notmatch "Lead $tag")
  $r = Post $s '/customer-leads' "/customer-leads/$lid/convert" @()
  Ok 'lead: converted to a customer' ($r.StatusCode -eq 303 -and $r.Location -match '/customers/[0-9a-f-]{36}')
  $cust = Id $r.Location
  Ok "customer: exists with the lead's name and email" ((Get-Page $s "/customers/$cust").Content -match "Lead $tag" -and (Get-Page $s "/customers/$cust").Content -match "lead.$t@example.com")
  $r = Get-Page $s '/customer-leads'
  Ok 'leads list: the lead is Won and links to its customer, with no Convert button' ($r.Content -match "/customers/$cust" -and $r.Content -match 'pill pill-ok">Won<' -and $r.Content -notmatch "/customer-leads/$lid/convert")
  Post $s '/customer-leads' "/customer-leads/$lid/convert" @() | Out-Null
  Ok 'lead: converting twice is refused' ((Flash-Of (Get-Page $s '/customer-leads').Content) -match 'already a customer')

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('sales', 'Sales'), @('mgr', 'Manager'), @('qc', 'QC'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: sees the watch list and can add a lead' ((Get-Page $sales '/reorders').StatusCode -eq 200 -and (Get-Page $sales '/customer-leads/new').StatusCode -eq 200)
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $mgr '/reorders'
  Ok 'Manager: reads the watch list but has no Create follow-up buttons' ($r.StatusCode -eq 200 -and $r.Content -notmatch '/follow-up')
  Ok 'Manager: adding a lead is 403, creating a follow-up is 403' ((Get-Page $mgr '/customer-leads/new').StatusCode -eq 403 -and (Post $mgr '/reorders' "/reorders/$due/follow-up" @()).StatusCode -eq 403)
  $qc = (Sign-In "qc.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'QC: no watch list and no leads (403), no menu entry' ((Get-Page $qc '/reorders').StatusCode -eq 403 -and (Get-Page $qc '/customer-leads').StatusCode -eq 403 -and (Get-Page $qc '/').Content -notmatch '>Reorder watch<')
} finally { Stop-Dev }
Summary
