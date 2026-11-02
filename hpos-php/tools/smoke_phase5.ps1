# HTTP smoke test for Phase 5 (accounts): settings, sales and purchase invoices with GST, payments,
# receivables and payables, prints, Book Keeper CSV and Tally XML, and who may do what.
#   pwsh tools/smoke_phase5.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Lines($rows) { $p = @(); foreach ($l in $rows) { $p += , @('item_id[]', $l[0]); $p += , @('qty[]', $l[1]); $p += , @('rate[]', $l[2]); $p += , @('tax_rate[]', $l[3]) }; , $p }
# raw GET that keeps headers (for downloads)
function Download($session, $path) { $resp = $session.GetAsync("$base$path").GetAwaiter().GetResult(); [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Type = [string]$resp.Content.Headers.ContentType; Disposition = [string]$resp.Content.Headers.ContentDisposition; Bytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult() } }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  foreach ($p in '/invoices', '/invoices/new', '/purchase-invoices', '/purchase-invoices/new', '/payments', '/payments/new', '/receivables', '/payables', '/exports', '/settings') {
    $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)"
  }

  # settings (restored at the end)
  $orig = @{}
  foreach ($m in [regex]::Matches((Get-Page $s '/settings').Content, 'name="setting\[(\w+)\]" type="text" value="([^"]*)"')) { $orig[$m.Groups[1].Value] = [System.Net.WebUtility]::HtmlDecode($m.Groups[2].Value) }
  Ok 'settings: the defaults are listed' ($orig.ContainsKey('company_name') -and $orig.ContainsKey('company_gstin') -and $orig.ContainsKey('default_credit_days'))
  Post $s '/settings' '/settings' @(, @('setting[company_gstin]', 'NOT-A-GSTIN')) | Out-Null
  Ok 'settings: a bad GSTIN is refused' ((Flash-Of (Get-Page $s '/settings').Content) -match 'GSTIN is not in the 15-character')
  Post $s '/settings' '/settings' @(@('setting[company_name]', "Test Co $tag"), @('setting[company_gstin]', '24aaaaa0000a1z5'), @('setting[default_credit_days]', '45')) | Out-Null
  $r = Get-Page $s '/settings'
  Ok 'settings: saved, GSTIN upper-cased' ($r.Content -match "Test Co $tag" -and $r.Content -match '24AAAAA0000A1Z5' -and $r.Content -match 'value="45"')
  Post $s '/settings' '/settings' @(, @('setting[default_credit_days]', 'abc')) | Out-Null
  Ok 'settings: credit days must be a number' ((Flash-Of (Get-Page $s '/settings').Content) -match 'whole number')

  # master data and one order through production so it is invoiceable
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE')); $sup = Id $r.Location
  $gst1 = '24' + $tag + '1234F1Z5'; $gst2 = '27' + $tag + '5678G1Z9'
  $r = Post $s '/customers/new' '/customers' @(@('name', "Local & Sons <$tag>"), @('gstin', $gst1)); $cust1 = Id $r.Location
  $r = Post $s '/customers/new' '/customers' @(@('name', "Mumbai Pipes $tag"), @('gstin', $gst2)); $cust2 = Id $r.Location
  $ids = @{}
  foreach ($def in @(@('rm', "RM$tag", 'RAW_MATERIAL', 'Kg', 'Compound'), @('fg', "FG$tag", 'FINISHED_GOODS', 'Meter', "=Drip $tag"))) {
    $r = Post $s '/items/new' '/items' @(@('code', $def[1]), @('name', $def[4]), @('category', $def[2]), @('uom', $def[3]), @('hsn_code', '3917')); $ids[$def[0]] = Id $r.Location
  }
  Post $s '/boms/new' '/boms' @(@('name', "BOM $tag"), @('fg_item_id', $ids.fg), @('output_qty', '100'), @('scrap_percent', '2.5'), @('rm_item_id[]', $ids.rm), @('qty_per_unit[]', '5')) | Out-Null
  $r = Post $s '/workstations/new' '/workstations' @(@('code', "WS$tag"), @('name', "Line $tag"), @('hourly_rate', '400'), @('status', 'IDLE')); $ws = Id $r.Location
  Post $s '/scan/receive' '/scan/receive' @(@('item_code', "RM$tag"), @('qty', '30'), @('barcode', "BAG-$tag-1")) | Out-Null
  $future = (Get-Date).AddDays(10).ToString('yyyy-MM-dd')
  $r = Post $s '/orders/new' '/orders' @(@('customer_id', $cust1), @('delivery_date', $future), @('approval_method', 'EMAIL'), @('notes', ''), @('item_id[]', $ids.fg), @('qty[]', '100'), @('rate[]', '40'))
  $A = Id $r.Location
  Post $s "/orders/$A" "/orders/$A/confirm" @(, @('approval_method', 'EMAIL')) | Out-Null
  $orderNo = [regex]::Match((Get-Page $s "/orders/$A").Content, 'SO-\d{4}-\d{5}').Value
  $woId = ([regex]::Matches((Get-Page $s '/work-orders').Content, "(?s)/work-orders/([0-9a-f-]{36})`"[^<]*</a></td>(?:(?!</tr>).)*$orderNo"))[0].Groups[1].Value
  Post $s "/work-orders/$woId" "/work-orders/$woId/job-cards" @(@('workstation_id', $ws), @('assign_me', '1')) | Out-Null
  $card = ((Get-Page $s '/shop-floor').Content -split '<section class="card floor-card">') | Where-Object { $_ -match "WS$tag" } | Select-Object -First 1
  $jc = [regex]::Match($card, '/job-cards/([0-9a-f-]{36})/status').Groups[1].Value
  Post $s '/shop-floor' "/job-cards/$jc/status" @(@('status', 'ACTIVE'), @('back', '/shop-floor')) | Out-Null
  Post $s '/shop-floor' '/production/log' @(@('job_card_id', $jc), @('type', 'METERS'), @('amount', '10'), @('back', '/shop-floor')) | Out-Null

  # sales invoice from the order
  $r = Get-Page $s '/invoices/new'
  Ok 'invoice form: the in-production order is offered' ($r.Content -match $orderNo)
  $r = Post $s '/invoices/new' '/invoices' @(@('order_id', ''), @('customer_id', ''))
  Ok 'invoice: no order and no customer is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'sales order or a customer')
  $today = (Get-Date).ToString('yyyy-MM-dd')
  $r = Post $s '/invoices/new' '/invoices' @(@('order_id', $A), @('customer_id', ''), @('invoice_date', $today), @('due_date', ''))
  Ok 'invoice: drafted from the order' ($r.StatusCode -eq 303 -and $r.Location -match '/invoices/[0-9a-f-]{36}'); $inv = Id $r.Location
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: lines copied, CGST+SGST split for a same-state customer' ($r.Content -match 'Draft' -and $r.Content -match '4,000.00' -and $r.Content -match '360.00' -and $r.Content -match '4,720.00' -and $r.Content -match '<dt>CGST</dt>' -and $r.Content -notmatch '<dt>IGST</dt>')
  $due = (Get-Date).AddDays(45).ToString('yyyy-MM-dd')
  Ok 'invoice: due date follows the 45-day credit setting' ($r.Content -match "<dt>Due</dt><dd>$due")
  Post $s "/invoices/$inv" "/invoices/$inv/lines" (Lines @(, @($ids.fg, '0', '50', '12'))) | Out-Null
  Ok 'invoice: a zero-quantity line is refused' ((Flash-Of (Get-Page $s "/invoices/$inv").Content) -match 'quantity above zero')
  Post $s "/invoices/$inv" "/invoices/$inv/lines" (Lines @(, @($ids.fg, '100', '50', '12'))) | Out-Null
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: edited to 100 @ 50 with 12% GST (5,000 + 600)' ($r.Content -match '5,000.00' -and $r.Content -match '5,600.00' -and $r.Content -match '300.00')
  $r = Get-Page $s "/invoices/$inv/print"
  Ok 'print (draft): company, GSTIN, DRAFT marking and amount in words' ($r.Content -match "Test Co $tag" -and $r.Content -match '24AAAAA0000A1Z5' -and $r.Content -match 'DRAFT INVOICE' -and $r.Content -match 'Five Thousand Six Hundred Rupees Only' -and $r.Content -match 'data-print')
  $r = Post $s "/invoices/$inv" "/invoices/$inv/submit" @(@('irn', 'IRN-TEST-123'), @('eway', ''))
  Ok 'invoice: posted' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: Unpaid, IRN shown, outstanding is the total, no line editor' ($r.Content -match 'Unpaid' -and $r.Content -match 'IRN-TEST-123' -and $r.Content -notmatch 'Save lines')
  Post $s "/invoices/$inv" "/invoices/$inv/lines" (Lines @(, @($ids.fg, '1', '1', '18'))) | Out-Null
  Ok 'invoice: a posted invoice cannot be edited' ((Flash-Of (Get-Page $s "/invoices/$inv").Content) -match 'Only DRAFT')
  $r = Get-Page $s "/invoices/$inv/print"
  Ok 'print (posted): TAX INVOICE with the IRN' ($r.Content -match 'TAX INVOICE' -and $r.Content -match 'IRN-TEST-123')
  Post $s "/invoices/$inv" "/invoices/$inv/einvoice" @(@('irn', ''), @('eway', '123456789012'), @('qr', '')) | Out-Null
  Ok 'invoice: e-way bill can be added later, IRN kept' ((Get-Page $s "/invoices/$inv").Content -match '123456789012' -and (Get-Page $s "/invoices/$inv").Content -match 'IRN-TEST-123')

  # payments
  $r = Get-Page $s "/payments/new?sales_invoice=$inv"
  Ok 'payment form: prefilled with the invoice outstanding' ($r.Content -match 'value="5600"' -and $r.Content -match 'Settling an invoice')
  $r = Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust1), @('sales_invoice_id', $inv), @('mode', 'UPI'), @('reference', 'UTR1'), @('amount', '2000'), @('payment_date', $today), @('post_now', '1'))
  Ok 'payment: 2000 recorded and posted' ($r.StatusCode -eq 303 -and (Flash-Of (Get-Page $s '/payments').Content) -match 'posted')
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: Partial, outstanding 3,600' ($r.Content -match 'Partial' -and $r.Content -match '3,600.00' -and $r.Content -match 'UTR1')
  Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust1), @('sales_invoice_id', $inv), @('mode', 'CASH'), @('amount', '9999'), @('payment_date', $today), @('post_now', '1')) | Out-Null
  $r = Get-Page $s '/payments'
  Ok 'OVERPAYMENT: kept as a draft with a clear message, invoice untouched' ((Flash-Of $r.Content) -match 'not posted: Payment exceeds' -and $r.Content -match '9,999.00' -and (Get-Page $s "/invoices/$inv").Content -match '3,600.00')
  $payDraft = ([regex]::Matches($r.Content, "(?s)<tr>(?:(?!</tr>).)*9,999.00(?:(?!</tr>).)*?/payments/([0-9a-f-]{36})/cancel"))[0].Groups[1].Value
  $r = Post $s '/payments' "/payments/$payDraft/cancel" @()
  Ok 'payment: the over-sized draft is cancelled' ($r.StatusCode -eq 303 -and (Get-Page $s '/payments').Content -match 'Cancelled')
  Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust1), @('sales_invoice_id', $inv), @('mode', 'CASH'), @('amount', '100'), @('payment_date', $today)) | Out-Null
  $r = Get-Page $s '/payments'
  Ok 'payment: saved as a draft when not posted now' ($r.Content -match 'Draft' -and $r.Content -match '100.00')
  $p100 = ([regex]::Matches($r.Content, "(?s)<tr>(?:(?!</tr>).)*>100.00<(?:(?!</tr>).)*?/payments/([0-9a-f-]{36})/submit"))[0].Groups[1].Value
  Post $s '/payments' "/payments/$p100/submit" @() | Out-Null
  Ok 'payment: post the draft later, outstanding 3,500' ((Get-Page $s "/invoices/$inv").Content -match '3,500.00')
  Post $s '/payments' "/payments/$p100/cancel" @() | Out-Null
  Ok 'payment: cancelling a posted payment restores the balance (3,600)' ((Get-Page $s "/invoices/$inv").Content -match '3,600.00')
  $r = Get-Page $s '/receivables'
  Ok 'receivables: the invoice is listed as not yet due' ($r.Content -match 'Not yet due' -and $r.Content -match '3,600.00')
  $r = Post $s '/invoices' "/invoices/$inv/cancel" @()
  Ok 'invoice: cannot be cancelled while it has posted payments' ((Flash-Of (Get-Page $s "/invoices/$inv").Content) -match 'cancel the payments first')
  Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust1), @('sales_invoice_id', $inv), @('mode', 'BANK_TRANSFER'), @('amount', '3600'), @('payment_date', $today), @('post_now', '1')) | Out-Null
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: Paid once the rest is paid' ($r.Content -match '>Paid<' -or $r.Content -match 'pill-ok">Paid')
  Ok 'receivables: a paid invoice drops off' ((Get-Page $s '/receivables').Content -notmatch $inv)

  # an overdue invoice to another-state customer: IGST and ageing
  $old1 = (Get-Date).AddDays(-50).ToString('yyyy-MM-dd'); $old2 = (Get-Date).AddDays(-40).ToString('yyyy-MM-dd')
  $r = Post $s '/invoices/new' '/invoices' @(@('order_id', ''), @('customer_id', $cust2), @('invoice_date', $old1), @('due_date', $old2))
  $inv2 = Id $r.Location
  Post $s "/invoices/$inv2" "/invoices/$inv2/lines" (Lines @(, @($ids.fg, '10', '100', '18'))) | Out-Null
  $r = Get-Page $s "/invoices/$inv2"
  Ok 'invoice (other state): IGST only' ($r.Content -match '<dt>IGST</dt><dd>180.00' -and $r.Content -notmatch '<dt>CGST</dt>' -and $r.Content -match '1,180.00')
  Post $s "/invoices/$inv2" "/invoices/$inv2/submit" @() | Out-Null
  $r = Get-Page $s '/receivables'
  Ok 'receivables: 40 days late falls in the 31-60 bucket' ($r.Content -match 'Mumbai Pipes' -and $r.Content -match '31-60 days late' -and $r.Content -match '>40<')
  $r = Get-Page $s "/invoices?status=UNPAID"
  Ok 'invoice list: status filter' ($r.Content -match 'INV-\d{4}-\d{5}')

  # purchase invoices
  $r = Post $s '/purchase-invoices/new' '/purchase-invoices' @(@('supplier_id', ''))
  Ok 'bill: no supplier is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'Choose a supplier')
  $r = Post $s '/purchase-invoices/new' '/purchase-invoices' @(@('supplier_id', $sup), @('total_amount', ''))
  Ok 'bill: no lines and no total is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'lines from the bill')
  $r = Post $s '/purchase-invoices/new' '/purchase-invoices' @(@('supplier_id', $sup), @('total_amount', '1500'))
  Ok 'bill: total-only bill saved as a draft' ($r.StatusCode -eq 303); $pb1 = Id $r.Location
  $r = Get-Page $s "/purchase-invoices/$pb1"
  Ok 'bill: shows the 1,500 total' ($r.Content -match 'Draft' -and $r.Content -match '1,500.00')
  $r = Post $s '/purchase-invoices/new' '/purchase-invoices' (@(, @('supplier_id', $sup)) + (Lines @(, @($ids.rm, '10', '50', '18'))))
  Ok 'bill: with a line' ($r.StatusCode -eq 303); $pb2 = Id $r.Location
  $r = Get-Page $s "/purchase-invoices/$pb2"
  Ok 'bill: 10 x 50 + 18% = 590' ($r.Content -match '500.00' -and $r.Content -match '590.00')
  Post $s "/purchase-invoices/$pb2" "/purchase-invoices/$pb2/submit" @() | Out-Null
  Ok 'bill: posted as Unpaid' ((Get-Page $s "/purchase-invoices/$pb2").Content -match 'Unpaid')
  Ok 'payables: lists the posted bill' ((Get-Page $s '/payables').Content -match '590.00')
  $r = Get-Page $s "/payments/new?purchase_invoice=$pb2"
  Ok 'payment form (supplier): prefilled' ($r.Content -match 'value="590"' -and $r.Content -match 'name="party_type" value="SUPPLIER"')
  Post $s "/payments/new?purchase_invoice=$pb2" '/payments' @(@('party_type', 'SUPPLIER'), @('party_id', $sup), @('purchase_invoice_id', $pb2), @('mode', 'BANK_TRANSFER'), @('amount', '200'), @('payment_date', $today), @('post_now', '1')) | Out-Null
  Ok 'bill: Partial, outstanding 390' ((Get-Page $s "/purchase-invoices/$pb2").Content -match 'Partial' -and (Get-Page $s "/purchase-invoices/$pb2").Content -match '390.00')
  $r = Get-Page $s '/payments/new'
  Ok 'payment form: unpaid invoices of the chosen party can be picked' ($r.Content -match 'name="invoice_ref"' -and $r.Content -match "value=`"P:$pb2`"" -and $r.Content -match 'owing 390')
  Post $s '/payments/new' '/payments' @(@('party_type', 'SUPPLIER'), @('party_id', $sup), @('invoice_ref', "P:$pb2"), @('mode', 'CASH'), @('amount', '90'), @('payment_date', $today), @('post_now', '1')) | Out-Null
  Ok 'payment form: a payment tied to an invoice through the picker settles it (390 -> 300)' ((Get-Page $s "/purchase-invoices/$pb2").Content -match '300.00')
  Post $s '/payments/new' '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust1), @('invoice_ref', "P:$pb2"), @('mode', 'CASH'), @('amount', '1'), @('payment_date', $today), @('post_now', '1')) | Out-Null
  Ok 'payment form: a bill picked for a customer payment is ignored, not applied' ((Get-Page $s "/purchase-invoices/$pb2").Content -match '300.00')
  Post $s '/purchase-invoices' "/purchase-invoices/$pb2/cancel" @() | Out-Null
  Ok 'bill: cannot be cancelled while it has posted payments' ((Flash-Of (Get-Page $s "/purchase-invoices/$pb2").Content) -match 'cancel the payments first')
  Post $s '/purchase-invoices' "/purchase-invoices/$pb1/cancel" @() | Out-Null
  Ok 'bill: a draft can be cancelled' ((Get-Page $s "/purchase-invoices/$pb1").Content -match 'Cancelled')
  $r = Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $sup), @('sales_invoice_id', $inv), @('mode', 'CASH'), @('amount', '1'), @('payment_date', $today), @('post_now', '1'))
  Ok 'payment: a customer payment against the wrong party is refused' ($r.StatusCode -eq 422 -or (Flash-Of (Get-Page $s '/payments').Content) -match 'not')
  $r = Post $s '/payments' '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust1), @('mode', 'CASH'), @('amount', '0'))
  Ok 'payment: zero amount is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'above zero')

  # exports
  $r = Get-Page $s '/exports'
  Ok 'exports: the order is listed with its three downloads' ($r.Content -match $orderNo -and $r.Content -match 'Sales CSV' -and $r.Content -match 'Tally XML')
  $d = Download $s "/exports/bookkeeper?order=$A&type=SALES_INVOICE"
  $csv = [Text.Encoding]::UTF8.GetString($d.Bytes)
  Ok 'CSV: a download with a byte-order mark and a header row' ($d.StatusCode -eq 200 -and $d.Type -match 'text/csv' -and $d.Disposition -match 'attachment' -and $d.Bytes[0] -eq 0xEF -and $csv -match 'voucher_type')
  Ok 'CSV: the item name that starts with "=" is neutralised' ($csv -match "'=Drip $tag" -and $csv -notmatch ",=Drip $tag")
  $d = Download $s "/exports/bookkeeper?order=$A&type=MATERIAL_CONSUMPTION"
  Ok 'CSV: raw material consumed is exported' ($d.StatusCode -eq 200 -and [Text.Encoding]::UTF8.GetString($d.Bytes) -match "RM$tag")
  $d = Download $s "/exports/tally?order=$A"
  $xmlText = [Text.Encoding]::UTF8.GetString($d.Bytes)
  $xml = $null; try { $xml = [xml]$xmlText } catch {}
  Ok 'Tally: a download that is well-formed XML even with & and < in the party name' ($d.StatusCode -eq 200 -and $d.Type -match 'xml' -and $xml -ne $null -and $xml.ENVELOPE.BODY.IMPORTDATA.REQUESTDATA.TALLYMESSAGE.VOUCHER.PARTYLEDGERNAME -eq "Local & Sons <$tag>")
  $v = $xml.ENVELOPE.BODY.IMPORTDATA.REQUESTDATA.TALLYMESSAGE.VOUCHER
  Ok 'Tally: Sales voucher, party debited and sales credited by 4,000' ($v.VCHTYPE -eq 'Sales' -and $v.VOUCHERNUMBER -match '^HP-INV-' -and $v.'ALLLEDGERENTRIES.LIST'.AMOUNT -eq '-4000.00' -and $v.'ALLINVENTORYENTRIES.LIST'.AMOUNT -eq '4000.00')
  Ok 'Tally: company name comes from the settings' ($xml.ENVELOPE.BODY.IMPORTDATA.REQUESTDESC.STATICVARIABLES.SVCURRENTCOMPANY -eq "Test Co $tag")
  $r = (Download $s '/exports/tally?order=not-an-id')
  Ok 'export: a bad order id is refused, not a crash' ($r.StatusCode -eq 400)

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('acct', 'Accounts'), @('mgr', 'Manager'), @('sales', 'Sales'), @('op', 'Operator'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $acct = (Sign-In "acct.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Accounts: full invoice access, print works with company details' ((Get-Page $acct '/invoices').StatusCode -eq 200 -and (Get-Page $acct '/invoices/new').StatusCode -eq 200 -and (Get-Page $acct "/invoices/$inv/print").Content -match "Test Co $tag")
  Ok 'Accounts: settings page is 403 (needs user admin)' ((Get-Page $acct '/settings').StatusCode -eq 403)
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $mgr '/invoices'
  Ok 'Manager: can read invoices but has no New invoice button' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'New invoice')
  Ok 'Manager: creating or paying is 403' ((Get-Page $mgr '/invoices/new').StatusCode -eq 403 -and (Get-Page $mgr '/payments/new').StatusCode -eq 403)
  $r = Post $mgr '/invoices' "/invoices/$inv2/cancel" @()
  Ok 'Manager: cancel invoice is 403' ($r.StatusCode -eq 403)
  $r = Post $mgr '/settings' '/settings' @(, @('setting[company_name]', 'Hacked'))
  Ok 'Manager: changing settings is 403' ($r.StatusCode -eq 403)
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: no accounts pages at all' ((Get-Page $sales '/invoices').StatusCode -eq 403 -and (Get-Page $sales '/payments').StatusCode -eq 403 -and (Get-Page $sales '/exports').StatusCode -eq 403)
  Ok 'Sales: the export download is 403 too' ((Download $sales "/exports/tally?order=$A").StatusCode -eq 403)
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Operator: no accounts menu' ((Get-Page $op '/').Content -notmatch '>Sales invoices<')
  Ok 'unknown invoice id is a 404' ((Get-Page $s '/invoices/not-an-id').StatusCode -eq 404 -and (Get-Page $s '/purchase-invoices/not-an-id').StatusCode -eq 404)

  # put the settings back
  Post $s '/settings' '/settings' @(@('setting[company_name]', $orig['company_name']), @('setting[company_gstin]', $orig['company_gstin']), @('setting[default_credit_days]', $orig['default_credit_days'])) | Out-Null
  $back = Get-Page $s '/settings'
  Ok 'settings restored' ($back.Content -match [regex]::Escape($orig['company_name']) -and $back.Content -match "value=`"$($orig['default_credit_days'])`"")
} finally { Stop-Dev }
Summary
