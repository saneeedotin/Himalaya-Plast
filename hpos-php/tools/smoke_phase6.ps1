# HTTP smoke test for Phase 6 (dashboard, alerts, search, stock): the home tiles, the cron-raised alerts
# and the bell, the global search (with permission filtering), stock position, ledger and batches.
#   pwsh tools/smoke_phase6.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Run-Cron() { & $php "$root\tools\cron.php" | Out-Null; if ($LASTEXITCODE -ne 0) { throw 'cron failed' } }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/', '/stock', '/stock?low=1', '/stock?tier=RAW_MATERIAL', '/stock/ledger', '/stock/batches', '/notifications', '/notifications?show=all', '/search', '/search?q=zz') {
    $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)"
  }

  # dashboard tiles
  $homeHtml = (Get-Page $s '/').Content
  foreach ($label in 'Open orders', 'In production', 'Overdue orders', 'Sales this month', 'Metres made today', 'Machines running', 'Machines down', 'Low-stock items', 'QC waiting', 'Receivables', 'Payables', 'Unread alerts') {
    if ($homeHtml -notmatch [regex]::Escape($label)) { Ok "home tile: $label" $false }
  }
  Ok 'home: all twelve KPI tiles are shown to the admin' ($homeHtml -match 'class="tiles"' -and ([regex]::Matches($homeHtml, 'class="tile')).Count -ge 12)
  Ok 'header: search box and alerts link on every page' ($homeHtml -match 'role="search"' -and $homeHtml -match 'class="[^"]*\bbell\b')

  # master data: a raw material with a minimum of 100 and only 10 in stock
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE')); $sup = Id $r.Location
  $gst = '24' + $tag + '1234F1Z5'
  $r = Post $s '/customers/new' '/customers' @(@('name', "Cust $tag"), @('gstin', $gst)); $cust = Id $r.Location
  $ids = @{}
  $r = Post $s '/items/new' '/items' @(@('code', "RM$tag"), @('name', "Compound $tag"), @('category', 'RAW_MATERIAL'), @('uom', 'Kg'), @('min_stock_level', '100')); $ids.rm = Id $r.Location
  $r = Post $s '/items/new' '/items' @(@('code', "FG$tag"), @('name', "Drip $tag"), @('category', 'FINISHED_GOODS'), @('uom', 'Meter')); $ids.fg = Id $r.Location
  Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $ids.fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $ids.rm), @('qty_per_unit[]', '5')) | Out-Null
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "WS$tag"), @('name', "Line $tag"), @('hourly_rate', '400'), @('status', 'IDLE')); $ws = Id $r.Location
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RM$tag"), @('qty', '10'), @('barcode', "BAG-$tag-1")) | Out-Null

  # stock screens
  $r = Get-Page $s '/stock?low=1'
  Ok 'stock: the low-stock filter lists the item with a Reorder flag' ($r.Content -match "RM$tag" -and $r.Content -match 'Reorder')
  $r = Get-Page $s '/stock?tier=FINISHED_GOODS'
  Ok 'stock: the category filter hides raw material' ($r.Content -match "FG$tag" -and $r.Content -notmatch "RM$tag")
  $r = Get-Page $s "/stock/ledger?item=$($ids.rm)"
  Ok 'ledger: the receipt shows as +10 Kg against its batch' ($r.Content -match '\+10.000 Kg' -and $r.Content -match 'BATCH-RM-BAG-' -and $r.Content -match 'Received into stock' -and $r.Content -notmatch "<td>FG$tag")
  $r = Get-Page $s "/stock/batches?q=BAG-$tag"
  Ok 'batches: search finds the batch and offers Inspect' ($r.Content -match "BATCH-RM-BAG-$tag-1" -and $r.Content -match '/quality/new\?batch=')
  $r = Get-Page $s "/stock/batches?q=BAG-$tag&source=EXTRUSION"
  Ok 'batches: the source filter hides purchased batches' ($r.Content -notmatch "BATCH-RM-BAG-$tag-1")
  $r = Get-Page $s '/stock/ledger?item=not-a-uuid&limit=7'
  Ok 'ledger: junk filters are ignored, not an error' ($r.StatusCode -eq 200)

  # alerts: low stock, QC waiting, overdue receivable; produced by the cron job
  $r = Post $s '/quality/new' '/quality' @(@('batch_number', "BATCH-RM-BAG-$tag-1"), @('status', 'PENDING'), @('sample_size', '5'))
  Ok 'a PENDING inspection is saved' ($r.StatusCode -eq 303)
  $old1 = (Get-Date).AddDays(-50).ToString('yyyy-MM-dd'); $old2 = (Get-Date).AddDays(-40).ToString('yyyy-MM-dd')
  $r = Post $s '/invoices/new' '/invoices' @(@('order_id', ''), @('customer_id', $cust), @('invoice_date', $old1), @('due_date', $old2)); $inv = Id $r.Location
  Post $s "/invoices/$inv" "/invoices/$inv/lines" @(@('item_id[]', $ids.fg), @('qty[]', '10'), @('rate[]', '100'), @('tax_rate[]', '18')) | Out-Null
  Post $s "/invoices/$inv" "/invoices/$inv/submit" @() | Out-Null
  Run-Cron
  $r = Get-Page $s '/notifications'
  Ok 'alerts: low stock for the item is raised as CRITICAL with a link to its edit page' ($r.Content -match "Compound $tag stock low" -and $r.Content -match "/items/$($ids.rm)/edit" -and $r.Content -match 'pill-fail')
  Ok 'alerts: QC waiting links to the pending list' ($r.Content -match 'QC inspections pending' -and $r.Content -match '/quality\?status=PENDING')
  Ok 'alerts: overdue customer invoices link to receivables' ($r.Content -match 'overdue customer invoices' -and $r.Content -match '/receivables')
  Ok 'alerts: every alert link points at a real page' ((Get-Page $s '/quality?status=PENDING').StatusCode -eq 200 -and (Get-Page $s '/receivables').StatusCode -eq 200 -and (Get-Page $s "/items/$($ids.rm)/edit").StatusCode -eq 200)
  $before = Flash-Of $r.Content
  $count = [regex]::Matches($r.Content, '/notifications/[0-9a-f-]{36}/status').Count
  Ok 'alerts: unread ones can be marked read or dismissed' ($count -ge 3)
  $homeHtml = (Get-Page $s '/').Content
  Ok 'home: the alerts appear under Needs your attention' ($homeHtml -match 'Needs your attention')
  $nid = [regex]::Match($r.Content, "(?s)Compound $tag stock low.*?/notifications/([0-9a-f-]{36})/status").Groups[1].Value
  Run-Cron
  Ok 'cron: running it twice does not duplicate the alert' (([regex]::Matches((Get-Page $s '/notifications?show=all').Content, "Compound $tag stock low")).Count -eq 1)
  $r = Post $s '/notifications' "/notifications/$nid/status" @(@('status', 'READ'), @('back', '/notifications?show=all'))
  Ok 'alerts: mark read goes back to where you were' ($r.StatusCode -eq 303 -and $r.Location -match 'show=all')
  Ok 'alerts: a read alert leaves the Unread tab but stays in All open' ((Get-Page $s '/notifications').Content -notmatch "Compound $tag stock low" -and (Get-Page $s '/notifications?show=all').Content -match "Compound $tag stock low")
  Post $s '/notifications' "/notifications/$nid/status" @(@('status', 'BOGUS'), @('back', '/notifications')) | Out-Null
  Ok 'alerts: a bogus status is refused' ((Flash-Of (Get-Page $s '/notifications').Content) -match 'read or resolved')
  Post $s '/notifications' "/notifications/$nid/status" @(@('status', 'RESOLVED'), @('back', '//evil.example')) | Out-Null
  Ok 'alerts: dismissed alerts are gone from every tab' ((Get-Page $s '/notifications?show=all').Content -notmatch "Compound $tag stock low")
  Post $s '/notifications' '/notifications/read-all' @() | Out-Null
  $r = Get-Page $s '/notifications'
  Ok 'alerts: mark all read empties the Unread tab and zeroes the bell' ((Flash-Of $r.Content) -match 'All notifications marked' -and $r.Content -notmatch 'class="badge"')
  # raising the stock above the minimum resolves the low-stock alert on the next run
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RM$tag"), @('qty', '500'), @('barcode', "BAG-$tag-2")) | Out-Null
  Run-Cron
  Ok 'cron: no new low-stock alert once stock is above the minimum' ((Get-Page $s '/notifications?show=all').Content -notmatch "Compound $tag stock low")

  # live notifications and Web Push
  $r = Get-Page $s '/notifications'
  Ok 'notifications page: has the push card and a service worker address on the page' ($r.Content -match 'data-push-card' -and $r.Content -match 'data-sw="[^"]*/sw\.js"' -and $r.Content -match 'data-bell-count')
  $r = Get-Page $s '/sw.js'
  Ok 'service worker: served as JavaScript and handles push and clicks' ($r.StatusCode -eq 200 -and $r.Content -match "addEventListener\('push'" -and $r.Content -match "addEventListener\('notificationclick'")
  $r = Get-Page $s '/notifications/poll'
  $j = $r.Content | ConvertFrom-Json
  Ok 'poll: JSON with the unread count, the server time and no backlog on the first call' ($r.StatusCode -eq 200 -and $null -ne $j.unread -and $j.now -match '^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d' -and @($j.items).Count -eq 0)
  $r = Get-Page $s ('/notifications/poll?after=' + [uri]::EscapeDataString("x'; DROP TABLE users;--"))
  Ok 'poll: a malformed start point is ignored, not passed to the database' ($r.StatusCode -eq 200 -and (($r.Content | ConvertFrom-Json).items.Count -eq 0))
  Ok 'poll: signed out goes to sign-in, not JSON' ((Get-Page (New-Session) '/notifications/poll').Location -match '/login')
  $r = Post $s '/notifications' '/push/status' @(@('endpoint', ''))
  $j = $r.Content | ConvertFrom-Json
  Ok 'push: status says it is available, gives the public key, and no browser yet' ($r.StatusCode -eq 200 -and $j.available -eq $true -and $j.key.Length -eq 87 -and $j.browsers -eq 0)
  # a real P-256 point, the way a browser would send it
  $ecKey = [System.Security.Cryptography.ECDsa]::Create([System.Security.Cryptography.ECCurve]::CreateFromFriendlyName('nistP256'))
  $q = $ecKey.ExportParameters($false).Q
  $point = [byte[]](@(4) + $q.X + $q.Y)
  $b64u = { param($b) [Convert]::ToBase64String($b).TrimEnd('=').Replace('+', '-').Replace('/', '_') }
  $p256dh = & $b64u $point; $authKey = & $b64u ([byte[]](1..16))
  $endpoint = "https://fcm.googleapis.com/fcm/send/smoke-$tag"
  $r = Post $s '/notifications' '/push/test' @()
  Ok 'push: a test before any browser is registered says to turn push on first' ($r.StatusCode -eq 409 -and $r.Content -match 'Turn push on')
  $r = Post $s '/notifications' '/push/subscribe' @(@('endpoint', 'https://internal.example.test/hook'), @('p256dh', $p256dh), @('auth', $authKey))
  Ok 'push: an address that is not a push service is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'not a browser push service')
  $r = Post $s '/notifications' '/push/subscribe' @(@('endpoint', 'http://fcm.googleapis.com/fcm/send/x'), @('p256dh', $p256dh), @('auth', $authKey))
  Ok 'push: a plain http address is refused' ($r.StatusCode -eq 422)
  $r = Post $s '/notifications' '/push/subscribe' @(@('endpoint', $endpoint), @('p256dh', ''), @('auth', ''))
  Ok 'push: a subscription without keys is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'valid push subscription')
  $r = Post $s '/notifications' '/push/subscribe' @(@('endpoint', $endpoint), @('p256dh', $p256dh), @('auth', $authKey))
  $j = $r.Content | ConvertFrom-Json
  Ok 'push: subscribing is accepted and this browser is recognised' ($r.StatusCode -eq 200 -and $j.ok -eq $true -and $j.browsers -eq 1 -and $j.thisBrowser -eq $true)
  $r = Post $s '/notifications' '/push/subscribe' @(@('endpoint', $endpoint), @('p256dh', $p256dh), @('auth', $authKey))
  Ok 'push: subscribing twice is still one browser' (($r.Content | ConvertFrom-Json).browsers -eq 1)
  $r = Post $s '/notifications' '/push/test' @()
  $j = $r.Content | ConvertFrom-Json
  Ok 'push: a test message is encrypted and accepted by the push service (dry run)' ($r.StatusCode -eq 200 -and $j.ok -eq $true -and $j.accepted -eq 1)
  $r = Post $s '/notifications' '/push/unsubscribe' @(@('endpoint', $endpoint))
  Ok 'push: unsubscribing leaves no browser' ((($r.Content | ConvertFrom-Json).browsers) -eq 0)
  $anon = New-Session
  $r = Post $anon '/login' '/push/subscribe' @(@('endpoint', $endpoint), @('p256dh', $p256dh), @('auth', $authKey))
  Ok 'push: someone who is not signed in cannot subscribe' ($r.StatusCode -in 303, 401, 403, 419)
  # who is told what: an event raises one notification for the people it concerns
  $evCust = Id (Post $s '/customers/new' '/customers' @(@('name', "Event Co $tag"), @('gstin', ''), @('email', ''), @('phone', ''))).Location
  $evDue = (Get-Date).AddDays(20).ToString('yyyy-MM-dd')
  $evOrder = Id (Post $s '/orders/new' '/orders' @(@('customer_id', $evCust), @('delivery_date', $evDue), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '10'), @('rate[]', '40'))).Location
  Post $s "/orders/$evOrder" "/orders/$evOrder/confirm" @(@('approval_method', 'EMAIL')) | Out-Null
  $evNo = [regex]::Match((Get-Page $s "/orders/$evOrder").Content, 'SO-\d{4}-\d{5}').Value
  Ok 'event: confirming an order shows up for the administrator at once' ((Get-Page $s '/notifications?show=all').Content -match "Order $evNo confirmed")
  $r = Get-Page $s ('/notifications/poll?after=' + [uri]::EscapeDataString('2000-01-01 00:00:00.000'))
  $j = $r.Content | ConvertFrom-Json
  Ok 'poll: from an old time it returns the unread ones, each with a link and a priority' (@($j.items).Count -ge 1 -and $j.items[0].url -and $j.items[0].priority -and (@($j.items | Where-Object { $_.title -match "Order $evNo confirmed" }).Count -eq 1))
  # search
  $orderFuture =(Get-Date).AddDays(10).ToString('yyyy-MM-dd')
  $r = Post $s '/orders/new' '/orders' @(@('customer_id', $cust), @('delivery_date', $orderFuture), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '100'), @('rate[]', '40'))
  $A = Id $r.Location; $orderNo = [regex]::Match((Get-Page $s "/orders/$A").Content, 'SO-\d{4}-\d{5}').Value
  $r = Get-Page $s '/search?q=x'
  Ok 'search: one character is refused with a clear message' ($r.StatusCode -eq 200 -and $r.Content -match 'at least 2 characters')
  $r = Get-Page $s '/search?q=%25%25'
  Ok 'search: "%%" is not a match-everything pattern' ($r.Content -match 'at least 2 characters')
  $r = Get-Page $s "/search?q=$orderNo"
  Ok 'search: an order number finds the order and links to it' ($r.Content -match 'Sales orders' -and $r.Content -match "/orders/$A")
  $r = Get-Page $s "/search?q=Cust+$tag"
  Ok 'search: a customer name finds the customer and their order' ($r.Content -match "/customers/$cust" -and $r.Content -match "/orders/$A")
  $r = Get-Page $s "/search?q=FG$tag"
  Ok 'search: an item code finds the item' ($r.Content -match "/items/$($ids.fg)")
  $r = Get-Page $s "/search?q=BAG-$tag-1"
  Ok 'search: a batch number finds the batch' ($r.Content -match 'Batches' -and $r.Content -match 'stock/batches\?q=')
  $r = Get-Page $s "/search?q=INV-"
  Ok 'search: an invoice number finds invoices' ($r.Content -match 'Invoices' -and $r.Content -match "/invoices/$inv")
  $r = Get-Page $s ('/search?q=' + [uri]::EscapeDataString('<script>alert(1)</script>'))
  Ok 'search: what was typed is escaped in the page' ($r.Content -notmatch '<script>alert\(1\)</script>' -and $r.Content -match '&lt;script&gt;')
  $r = Get-Page $s ('/search?q=' + [uri]::EscapeDataString("zz' OR '1'='1"))
  Ok 'search: a quote in the query is handled safely' ($r.StatusCode -eq 200 -and $r.Content -match '0 results')

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('op', 'Operator'), @('sales', 'Sales'), @('acct', 'Accounts'), @('prod', 'Production'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  $oph = (Get-Page $op '/').Content
  Ok 'Operator: home has no KPI tiles but still has search and alerts' ($oph -notmatch 'class="tiles"' -and $oph -match 'role="search"')
  Ok 'Operator: stock pages are allowed? (Stock scan only, no read) so 403' ((Get-Page $op '/stock').StatusCode -eq 403 -and (Get-Page $op '/stock/ledger').StatusCode -eq 403)
  # stock adjustment
  $r = Get-Page $s '/stock/adjust'
  Ok 'stock adjust: the form loads and lists the items with their stock' ($r.StatusCode -eq 200 -and $r.Content -match 'Adjust stock' -and $r.Content -match "FG$tag" -and $r.Content -match 'in stock')
  $r = Post $s '/stock/adjust' '/stock/adjust' @(@('item_id', $ids.rm), @('direction', 'IN'), @('qty', '12.5'), @('reason', 'OPENING'), @('notes', "opening $tag"))
  Ok 'stock adjust: adding opening stock is accepted' ($r.StatusCode -eq 303 -and (Flash-Of (Get-Page $s '/stock').Content) -match 'BATCH-ADJ-')
  Ok 'stock adjust: the lot is listed under Adjustment batches and the ledger names the reason' ((Get-Page $s '/stock/batches?source=ADJUSTMENT').Content -match 'BATCH-ADJ-' -and (Get-Page $s "/stock/ledger?item=$($ids.rm)").Content -match "Opening stock: opening $tag")
  $r = Post $s '/stock/adjust' '/stock/adjust' @(@('item_id', $ids.rm), @('direction', 'OUT'), @('qty', '999999'), @('reason', 'COUNT'))
  Ok 'stock adjust: removing more than the free stock is refused with the reason' ($r.StatusCode -eq 422 -and $r.Content -match 'free to remove')
  $r = Post $s '/stock/adjust' '/stock/adjust' @(@('item_id', $ids.rm), @('direction', 'IN'), @('qty', '1'), @('reason', 'OTHER'))
  Ok 'stock adjust: reason Other needs a note' ($r.StatusCode -eq 422 -and $r.Content -match 'Describe the reason')
  Ok 'stock adjust: an operator is refused (no Stock create right)' ((Get-Page $op '/stock/adjust').StatusCode -eq 403 -and (Post $op '/stock/adjust' '/stock/adjust' @(@('item_id', $ids.rm), @('direction', 'IN'), @('qty', '1'), @('reason', 'OPENING'))).StatusCode -ge 400)
  $r = Get-Page $op "/search?q=Cust+$tag"
  Ok 'Operator: searching a customer name finds nothing (no Customers right)' ($r.StatusCode -eq 200 -and $r.Content -notmatch "/customers/$cust" -and $r.Content -notmatch "/orders/$A")
  $r = Get-Page $op "/search?q=FG$tag"
  Ok 'Operator: searching an item code still finds the item (Items right)' ($r.Content -match "/items/$($ids.fg)")
  # search-as-you-type (JSON behind the header box)
  $r = Get-Page $s "/search/suggest?q=$orderNo"; $j = $r.Content | ConvertFrom-Json
  Ok 'suggest: an order number gives JSON with the order under Sales orders' ($r.StatusCode -eq 200 -and ($j.groups | Where-Object { $_.label -eq 'Sales orders' }) -and $r.Content -match "/orders/$A")
  $r = Get-Page $s '/search/suggest?q=x'; $j = $r.Content | ConvertFrom-Json
  Ok 'suggest: one character gives no matches and the reason' ($j.message -match 'at least 2 characters' -and @($j.groups).Count -eq 0)
  $r = Get-Page $s "/search/suggest?q=Cust+$tag"; $j = $r.Content | ConvertFrom-Json
  Ok 'suggest: a customer name finds the customer, with at most 4 rows a kind' ($r.Content -match "/customers/$cust" -and (@($j.groups | ForEach-Object { @($_.items).Count } | Where-Object { $_ -gt 4 }).Count -eq 0))
  $r = Get-Page $s "/search/suggest?q=op+$tag"
  Ok 'suggest: admin finds staff by name' ($r.Content -match '"label":"Staff"' -and $r.Content -match "op\.$t@test\.local")
  $r = Get-Page $op "/search/suggest?q=op+$tag"
  Ok 'suggest: an operator does not see staff (no Users right)' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Staff' -and $r.Content -notmatch "op\.$t@test\.local")
  $r = Get-Page $op "/search/suggest?q=Cust+$tag"
  Ok 'suggest: an operator does not see customers (no Customers right)' ($r.Content -notmatch "/customers/$cust")
  $r = Get-Page (New-Session) '/search/suggest?q=admin'
  Ok 'suggest: signed out goes to sign-in, not JSON' ($r.StatusCode -eq 303 -and $r.Location -match '/login')
  $r = Get-Page $s '/search?q=admin'
  Ok 'search page: staff appear under Staff for an admin' ($r.Content -match 'Staff' -and $r.Content -match '/users/[0-9a-f-]{36}/edit')
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $sales "/search?q=$orderNo"
  Ok 'Sales: finds the order, and has the dashboard' ($r.Content -match "/orders/$A" -and (Get-Page $sales '/').Content -match 'class="tiles"')
  $r = Get-Page $sales "/search?q=INV-"
  Ok 'Sales: invoices are not searchable (no Accounts right)' ($r.Content -notmatch "/invoices/$inv")
  Ok 'Sales: can read stock' ((Get-Page $sales '/stock').StatusCode -eq 200)
  $acct = (Sign-In "acct.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Accounts: receivables and payables tiles link through' ((Get-Page $acct '/').Content -match 'href="[^"]*/receivables"')
  # who is told what (the order confirmed earlier raised one notification)
  Ok 'event: Sales sees the order confirmation' ((Get-Page $sales "/notifications?show=all").Content -match "Order $evNo confirmed")
  Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust), @('sales_invoice_id', $inv), @('mode', 'UPI'), @('amount', '10'), @('payment_date', (Get-Date).ToString('yyyy-MM-dd')), @('post_now', '1')) | Out-Null
  Ok 'event: a posted customer payment is shown to Accounts' ((Get-Page $acct '/notifications?show=all').Content -match 'Payment received: Rs 10.00')
  Ok 'event: it is not shown to Sales or an Operator (they have no Accounts right)' ((Get-Page $sales '/notifications?show=all').Content -notmatch 'Payment received' -and (Get-Page $op '/notifications?show=all').Content -notmatch 'Payment received')
  Ok 'event: the bell counts only what a person may see' ((((Get-Page $op '/notifications/poll').Content | ConvertFrom-Json).unread) -ne (((Get-Page $s '/notifications/poll').Content | ConvertFrom-Json).unread))
  $r = Post $s '/notifications' '/notifications/read-all' @()
  Ok 'event: the administrator reading everything does not read it for Sales' ((Get-Page $sales '/notifications').Content -match "Order $evNo confirmed")

  $prod = (Sign-In "prod.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Production: ledger and batches readable' ((Get-Page $prod '/stock/ledger').StatusCode -eq 200 -and (Get-Page $prod '/stock/batches').StatusCode -eq 200)
  $r = Post $op '/notifications' '/notifications/read-all' @()
  Ok 'any signed-in user can manage their alerts' ($r.StatusCode -eq 303)
  $anon = New-Session
  Ok 'signed out: search and notifications redirect to the sign-in page' ((Get-Page $anon '/search?q=abc').Location -match '/login' -and (Get-Page $anon '/notifications').Location -match '/login' -and (Get-Page $anon '/stock').Location -match '/login')
} finally { Stop-Dev }
Summary
