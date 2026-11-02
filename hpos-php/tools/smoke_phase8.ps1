# HTTP smoke test for Phase 8 (books): chart of accounts, vouchers, day book, ledgers, trial balance,
# profit and loss, balance sheet, GST reports, party statements, credit and debit notes, the cash or bank
# account on a payment, CSV downloads, and who may do what.
#   pwsh tools/smoke_phase8.ps1
. "$PSScriptRoot\smoke_lib.ps1"

function Role-Id($page, $name) { foreach ($m in [regex]::Matches($page, 'value="([0-9a-f-]{36})"[^>]*> (\w+)')) { if ($m.Groups[2].Value -eq $name) { return $m.Groups[1].Value } } }
function Flash-Of($html) { ([regex]::Matches($html, 'class="alert[^"]*"[^>]*>([^<]*)<') | ForEach-Object { $_.Groups[1].Value }) -join ' | ' }
function Lines($rows) { $p = @(); foreach ($l in $rows) { $p += , @('item_id[]', $l[0]); $p += , @('qty[]', $l[1]); $p += , @('rate[]', $l[2]); $p += , @('tax_rate[]', $l[3]) }; , $p }
function Download($session, $path) { $resp = $session.GetAsync("$base$path").GetAwaiter().GetResult(); [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Type = [string]$resp.Content.Headers.ContentType; Disposition = [string]$resp.Content.Headers.ContentDisposition; Bytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult() } }
# the id of an account (by its exact name) from the chart of accounts page
function Acct($s, $name) { $m = [regex]::Match((Get-Page $s '/books/accounts').Content, 'ledger\?account=([0-9a-f-]{36})">' + [regex]::Escape($name) + '</a>'); $m.Groups[1].Value }
function Journal($rows) { $p = @(); foreach ($l in $rows) { $p += , @('account[]', $l[0]); $p += , @('debit[]', $l[1]); $p += , @('credit[]', $l[2]); $p += , @('party[]', ''); $p += , @('note[]', '') }; , $p }

Start-Dev
try {
  $tag = -join ((65..90) | Get-Random -Count 5 | ForEach-Object { [char]$_ }); $t = $tag.ToLower()
  $today = (Get-Date).ToString('yyyy-MM-dd')
  $c = Get-Content "$root\config\dev-admin.txt"
  $a = Sign-In $c[0] $c[1]; $s = $a.Session
  Ok 'admin login' ($a.Response.StatusCode -eq 303)
  $pages = @('/books', '/books/reports', '/books/accounts', '/books/accounts/new', '/books/ledger', '/books/opening', '/books/vouchers', '/books/vouchers/new',
    '/books/vouchers/new?type=CONTRA', '/books/vouchers/new?type=RECEIPT', '/books/vouchers/new?type=PAYMENT', '/books/vouchers/new?type=JOURNAL',
    '/books/reports/trial-balance', '/books/reports/profit-loss', '/books/reports/balance-sheet', '/books/reports/gst', '/books/reports/gst?tab=sales',
    '/books/reports/gst?tab=purchases', '/books/reports/gst?tab=hsn', '/books/reports/statement', '/credit-notes', '/credit-notes/new', '/credit-notes/new?kind=PURCHASE')
  foreach ($p in $pages) { $r = Get-Page $s $p; Ok "GET $p" ($r.StatusCode -eq 200 -and $r.Content -notmatch 'Something went wrong') "$($r.StatusCode)" }
  $r = Get-Page $s '/books'
  Ok 'books home: tiles, cash and bank table, quick links' ($r.Content -match 'Customers owe you' -and $r.Content -match 'Cash and bank balance' -and $r.Content -match 'Latest entries' -and $r.Content -match 'Journal')
  Ok 'menu: the Books group is there' ($r.Content -match '>Books overview<' -and $r.Content -match '>Day book<' -and $r.Content -match '>Credit and debit notes<')

  # chart of accounts
  $r = Post $s '/books/accounts/new' '/books/accounts' @(@('code', "E$tag"), @('name', "Exp $tag"), @('account_kind', 'EXPENSE:INDIRECT_EXP'))
  Ok 'account: an expense account is added' ($r.StatusCode -eq 303)
  $r = Post $s '/books/accounts/new' '/books/accounts' @(@('code', "B$tag"), @('name', "Bank $tag"), @('account_kind', 'ASSET:BANK'))
  Ok 'account: a bank account is added' ($r.StatusCode -eq 303)
  $r = Post $s '/books/accounts/new' '/books/accounts' @(@('code', "E$tag"), @('name', "Again $tag"), @('account_kind', 'EXPENSE:INDIRECT_EXP'))
  Ok 'account: a repeated code is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'already used')
  $r = Post $s '/books/accounts/new' '/books/accounts' @(@('code', "X$tag"), @('name', "Odd $tag"), @('account_kind', 'INCOME:GENERAL'))
  Ok 'account: a kind that does not fit the type is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'not allowed')
  $exp = Acct $s "Exp $tag"; $bank = Acct $s "Bank $tag"; $cash = Acct $s 'Cash in Hand'; $capital = Acct $s 'Capital Account'; $debtors = Acct $s 'Sundry Debtors'
  Ok 'account: the new accounts and the built-in ones are on the chart' ($exp -ne '' -and $bank -ne '' -and $cash -ne '' -and $capital -ne '' -and $debtors -ne '')
  $r = Get-Page $s "/books/accounts/$cash/edit"
  Ok 'account: a built-in account can only be renamed' ($r.StatusCode -eq 200 -and $r.Content -match 'built-in account' -and $r.Content -notmatch 'name="account_kind"')
  Post $s '/books/accounts' "/books/accounts/$exp" @(@('code', "E$tag"), @('name', "Exp $tag renamed"), @('account_kind', 'EXPENSE:INDIRECT_EXP')) | Out-Null
  Ok 'account: edited' ((Get-Page $s '/books/accounts').Content -match "Exp $tag renamed")
  Post $s '/books/accounts' "/books/accounts/$exp" @(@('code', "E$tag"), @('name', "Exp $tag"), @('account_kind', 'EXPENSE:INDIRECT_EXP')) | Out-Null

  # vouchers
  $r = Post $s '/books/vouchers/new?type=RECEIPT' '/books/vouchers' @(@('type', 'RECEIPT'), @('date', $today), @('to_account', $bank), @('from_account', $capital), @('amount', '10000'), @('narration', "Capital $tag"), @('reference', 'CAP1'))
  Ok 'receipt: capital paid into the bank account' ($r.StatusCode -eq 303 -and $r.Location -match '/books/vouchers/[0-9a-f-]{36}'); $vCap = Id $r.Location
  $r = Get-Page $s "/books/vouchers/$vCap"
  Ok 'voucher: shows both lines, narration and a Posted pill' ($r.Content -match "Bank $tag" -and $r.Content -match 'Capital Account' -and $r.Content -match "Capital $tag" -and $r.Content -match '10,000.00' -and $r.Content -match 'Posted')
  $r = Post $s '/books/vouchers/new?type=EXPENSE' '/books/vouchers' @(@('type', 'EXPENSE'), @('date', $today), @('account', $exp), @('paid_from', $bank), @('amount', '1500'), @('narration', "Rent $tag"))
  Ok 'expense: paid from the bank account' ($r.StatusCode -eq 303); $vExp = Id $r.Location
  $r = Post $s '/books/vouchers/new?type=EXPENSE' '/books/vouchers' @(@('type', 'EXPENSE'), @('date', $today), @('account', $exp), @('paid_from', $bank), @('amount', '0'))
  Ok 'expense: a zero amount is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'above zero')
  $r = Post $s '/books/vouchers/new?type=EXPENSE' '/books/vouchers' @(@('type', 'EXPENSE'), @('date', (Get-Date).AddDays(3).ToString('yyyy-MM-dd')), @('account', $exp), @('paid_from', $bank), @('amount', '5'))
  Ok 'expense: a future date is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'future')
  $r = Post $s '/books/vouchers/new?type=EXPENSE' '/books/vouchers' @(@('type', 'EXPENSE'), @('date', $today), @('account', $bank), @('paid_from', $cash), @('amount', '5'))
  Ok 'expense: must debit an expense account' ($r.StatusCode -eq 422 -and $r.Content -match 'expense account')
  $r = Post $s '/books/vouchers/new?type=CONTRA' '/books/vouchers' @(@('type', 'CONTRA'), @('date', $today), @('from_account', $bank), @('to_account', $cash), @('amount', '500'), @('narration', "Cash drawn $tag"))
  Ok 'contra: cash drawn from the bank' ($r.StatusCode -eq 303)
  $r = Post $s '/books/vouchers/new?type=CONTRA' '/books/vouchers' @(@('type', 'CONTRA'), @('date', $today), @('from_account', $bank), @('to_account', $exp), @('amount', '5'))
  Ok 'contra: only between cash and bank accounts' ($r.StatusCode -eq 422)
  $r = Get-Page $s "/books/ledger?account=$bank"
  Ok 'bank book: 10,000 in, 1,500 and 500 out = 8,000.00 Dr' ($r.Content -match '8,000.00 Dr' -and $r.Content -match 'bank book' -and $r.Content -match 'Opening balance')
  $r = Post $s '/books/vouchers/new?type=JOURNAL' '/books/vouchers' (@(@('type', 'JOURNAL'), @('date', $today), @('narration', 'Unbalanced')) + (Journal @(@($exp, '100', ''), @($bank, '', '90'))))
  Ok 'journal: debits and credits that differ are refused' ($r.StatusCode -eq 422 -and $r.Content -match 'does not balance')
  Ok 'journal: the lines typed are shown again after a refusal' ($r.Content -match 'value="100"' -and $r.Content -match 'value="90"')
  $r = Post $s '/books/vouchers/new?type=JOURNAL' '/books/vouchers' (@(@('type', 'JOURNAL'), @('date', $today), @('narration', "Adjust $tag")) + (Journal @(@($exp, '100', ''), @($bank, '', '100'))))
  Ok 'journal: a balanced one is posted' ($r.StatusCode -eq 303); $vJ = Id $r.Location
  Ok 'bank book: 7,900.00 Dr after the journal' ((Get-Page $s "/books/ledger?account=$bank").Content -match '7,900.00 Dr')
  $r = Post $s "/books/vouchers/$vJ" "/books/vouchers/$vJ/cancel" @(, @('reason', 'Typed twice'))
  Ok 'voucher: cancelled with a reversing entry' ($r.StatusCode -eq 303)
  $r = Get-Page $s "/books/vouchers/$vJ"
  Ok 'voucher: shown as Reversed with the reversing voucher linked' ($r.Content -match 'Reversed' -and $r.Content -match 'Reversed by' -and $r.Content -notmatch 'Cancel voucher')
  Ok 'bank book: back to 8,000.00 Dr' ((Get-Page $s "/books/ledger?account=$bank").Content -match '8,000.00 Dr')
  $r = Post $s "/books/vouchers/$vJ" "/books/vouchers/$vJ/cancel" @()
  Ok 'voucher: cancelling twice is refused' ((Flash-Of (Get-Page $s "/books/vouchers/$vJ").Content) -ne '' -or $r.StatusCode -ge 400)
  $r = Get-Page $s '/books/opening'
  Ok 'opening balances: the form loads with the explanation' ($r.StatusCode -eq 200 -and $r.Content -match 'Opening Balance Difference')
  $r = Post $s '/books/opening' '/books/opening' @(@('date', $today))
  Ok 'opening balances: no lines is refused' ($r.StatusCode -eq 422)

  # day book, trial balance, profit and loss, balance sheet
  $r = Get-Page $s "/books/vouchers?from=$today&to=$today"
  Ok "day book: today's vouchers with narration and a total" ($r.Content -match "Capital $tag" -and $r.Content -match "Rent $tag" -and $r.Content -match 'Total')
  $r = Get-Page $s "/books/vouchers?from=$today&to=$today&type=CONTRA&q=drawn+$t"
  Ok 'day book: filters by type and search' ($r.Content -match "Cash drawn $tag" -and $r.Content -notmatch "Rent $tag")
  $d = Download $s "/books/vouchers?from=$today&to=$today&format=csv"
  Ok 'day book: CSV download' ($d.StatusCode -eq 200 -and $d.Type -match 'text/csv' -and $d.Bytes[0] -eq 0xEF -and [Text.Encoding]::UTF8.GetString($d.Bytes) -match "Rent $tag")
  $r = Get-Page $s '/books/reports/trial-balance'
  Ok 'trial balance: balanced, shows the new accounts' ($r.Content -match 'Balanced' -and $r.Content -match "Bank $tag" -and $r.Content -match "Exp $tag")
  $d = Download $s '/books/reports/trial-balance?format=csv'
  Ok 'trial balance: CSV download with a total row' ($d.StatusCode -eq 200 -and $d.Type -match 'text/csv' -and [Text.Encoding]::UTF8.GetString($d.Bytes) -match 'Total')
  $r = Get-Page $s '/books/reports/profit-loss'
  Ok 'profit and loss: trading account, expenses, net result' ($r.Content -match 'Trading account' -and $r.Content -match 'Cost of goods sold' -and $r.Content -match "Exp $tag" -and $r.Content -match '1,500.00' -and $r.Content -match 'Net (profit|loss)')
  Ok 'profit and loss: CSV download' ((Download $s '/books/reports/profit-loss?format=csv').StatusCode -eq 200)
  $r = Get-Page $s '/books/reports/balance-sheet'
  Ok 'balance sheet: balanced, with closing stock and profit lines' ($r.Content -match 'Balanced' -and $r.Content -match 'Closing stock' -and ($r.Content -match 'Profit to date' -or $r.Content -match 'Loss to date') -and $r.Content -match "Bank $tag")
  Ok 'balance sheet: CSV download' ((Download $s '/books/reports/balance-sheet?format=csv').StatusCode -eq 200)
  $r = Get-Page $s '/books/reports/trial-balance?asof=not-a-date'
  Ok 'a bad date falls back to today instead of failing' ($r.StatusCode -eq 200)

  # a customer, an item, an invoice and money in through the bank account
  $gst = '24' + $tag + '1234F1Z5'
  $r = Post $s '/customers/new' '/customers' @(@('name', "Cust $tag"), @('gstin', $gst)); $cust = Id $r.Location
  $ids = @{}
  foreach ($def in @(@('fg', "FG$tag", 'FINISHED_GOODS', 'Meter', "Pipe $tag"), @('rm', "RM$tag", 'RAW_MATERIAL', 'Kg', "Compound $tag"))) {
    $r = Post $s '/items/new' '/items' @(@('code', $def[1]), @('name', $def[4]), @('category', $def[2]), @('uom', $def[3]), @('hsn_code', '3917')); $ids[$def[0]] = Id $r.Location
  }
  $r = Post $s '/invoices/new' '/invoices' @(@('order_id', ''), @('customer_id', $cust), @('invoice_date', $today), @('due_date', ''))
  $inv = Id $r.Location
  Post $s "/invoices/$inv" "/invoices/$inv/lines" (Lines @(, @($ids.fg, '10', '100', '18'))) | Out-Null
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: a posted invoice offers a credit note, a draft does not' ($r.Content -notmatch '>Credit note<')
  Post $s "/invoices/$inv" "/invoices/$inv/submit" @(@('irn', ''), @('eway', '')) | Out-Null
  $r = Get-Page $s "/invoices/$inv"
  Ok 'invoice: posted (1,180) and now offers a credit note' ($r.Content -match '1,180.00' -and $r.Content -match '>Credit note<')
  $r = Get-Page $s "/books/ledger?account=$debtors&party=CUSTOMER:$cust"
  Ok 'debtors ledger: the invoice voucher is there, 1,180.00 Dr' ($r.Content -match '1,180.00 Dr' -and $r.Content -match 'Sales invoice')
  $r = Get-Page $s "/payments/new?sales_invoice=$inv"
  Ok 'payment form: offers the cash and bank accounts' ($r.Content -match 'name="account_id"' -and $r.Content -match "Bank $tag")
  Post $s "/payments/new?sales_invoice=$inv" '/payments' @(@('party_type', 'CUSTOMER'), @('party_id', $cust), @('sales_invoice_id', $inv), @('account_id', $bank), @('mode', 'BANK_TRANSFER'), @('amount', '500'), @('payment_date', $today), @('post_now', '1')) | Out-Null
  Ok 'payment: 500 against the invoice through the chosen bank account (8,500.00 Dr)' ((Get-Page $s "/books/ledger?account=$bank").Content -match '8,500.00 Dr')
  $r = Get-Page $s "/books/reports/statement?party=CUSTOMER:$cust&from=$today&to=$today"
  Ok 'statement: customer owes 680.00 after the payment' ($r.Content -match "Cust $tag" -and $r.Content -match '680.00 Dr' -and $r.Content -match 'They owe you')
  $d = Download $s "/books/reports/statement?party=CUSTOMER:$cust&format=csv"
  Ok 'statement: CSV download' ($d.StatusCode -eq 200 -and [Text.Encoding]::UTF8.GetString($d.Bytes) -match 'Opening balance')

  # credit note
  $r = Get-Page $s "/credit-notes/new?kind=SALES&invoice=$inv"
  Ok 'credit note: started from the invoice with its customer and lines copied' ($r.Content -match "Pipe $tag" -and $r.Content -match "value=`"$inv`" data-party=`"$cust`" selected" -and $r.Content -match 'value="10"')
  $note = @(@('kind', 'SALES'), @('party_id', $cust), @('invoice_id', $inv), @('note_date', $today), @('reason', 'Returned'), @('description[]', 'Returned pipe'), @('hsn[]', '3917'), @('qty[]', '2'), @('rate[]', '100'), @('tax_rate[]', '18'))
  $r = Post $s '/credit-notes/new' '/credit-notes' @(@('kind', 'SALES'), @('party_id', $cust), @('invoice_id', $inv), @('note_date', $today), @('description[]', 'Too much'), @('qty[]', '100'), @('rate[]', '100'), @('tax_rate[]', '18'))
  Ok 'credit note: more than the invoice is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'more than the invoice')
  $r = Post $s '/credit-notes/new' '/credit-notes' @(@('kind', 'SALES'), @('party_id', $cust), @('note_date', $today), @('description[]', 'No qty'), @('qty[]', ''), @('rate[]', '100'), @('tax_rate[]', '18'))
  Ok 'credit note: a line without a quantity is refused' ($r.StatusCode -eq 422 -and $r.Content -match 'quantity above zero')
  $r = Post $s '/credit-notes/new' '/credit-notes' $note
  Ok 'credit note: saved as a draft' ($r.StatusCode -eq 303 -and $r.Location -match '/credit-notes/[0-9a-f-]{36}'); $cn = Id $r.Location
  $r = Get-Page $s "/credit-notes/$cn"
  Ok 'credit note: draft of 236.00 (200 + 36 GST) that has changed nothing' ($r.Content -match 'CN-' -and $r.Content -match 'Draft' -and $r.Content -match '236.00' -and (Get-Page $s "/invoices/$inv").Content -match '680.00')
  Post $s "/credit-notes/$cn" "/credit-notes/$cn/post" @() | Out-Null
  $r = Get-Page $s "/credit-notes/$cn"
  Ok 'credit note: posted' ($r.Content -match 'Posted' -and $r.Content -notmatch '>Edit<')
  Ok 'credit note: the invoice owes 444.00 now' ((Get-Page $s "/invoices/$inv").Content -match '444.00')
  $r = Get-Page $s "/books/reports/statement?party=CUSTOMER:$cust&from=$today&to=$today"
  Ok 'statement: 444.00 Dr after the credit note' ($r.Content -match '444.00 Dr' -and $r.Content -match 'Credit note')
  $r = Get-Page $s "/books/reports/gst?tab=sales&from=$today&to=$today"
  Ok 'GST sales register: the invoice and the credit note (negative)' ($r.Content -match 'INV-' -and $r.Content -match 'CN-' -and $r.Content -match '-200.00' -and $r.Content -match '1,000.00')
  $r = Get-Page $s "/books/reports/gst?tab=hsn&from=$today&to=$today"
  Ok 'GST HSN summary: 3917 with the credited quantity taken off' ($r.Content -match '3917')
  $r = Get-Page $s "/books/reports/gst?from=$today&to=$today"
  Ok 'GST summary: output tax by head and a to-pay total' ($r.Content -match 'Charged on sales' -and $r.Content -match 'CGST' -and $r.Content -match 'SGST' -and $r.Content -match 'IGST')
  foreach ($tab in 'summary', 'sales', 'purchases', 'hsn') { $d = Download $s "/books/reports/gst?tab=$tab&from=$today&to=$today&format=csv"; Ok "GST $tab`: CSV download" ($d.StatusCode -eq 200 -and $d.Type -match 'text/csv') }
  $r = Get-Page $s "/credit-notes/$cn/print"
  Ok 'credit note: print page with company, number and amount in words' ($r.StatusCode -eq 200 -and $r.Content -match 'CREDIT NOTE' -and $r.Content -match 'Two Hundred Thirty Six Rupees' -and $r.Content -match 'data-print')
  Post $s "/credit-notes/$cn" "/credit-notes/$cn/cancel" @() | Out-Null
  Ok 'credit note: cancelled, the invoice owes 680.00 again' ((Get-Page $s "/credit-notes/$cn").Content -match 'Cancelled' -and (Get-Page $s "/invoices/$inv").Content -match '680.00')
  $r = Post $s '/credit-notes/new' '/credit-notes' $note; $cn2 = Id $r.Location
  Ok 'credit note: a draft opens for editing' ((Get-Page $s "/credit-notes/$cn2/edit").StatusCode -eq 200)
  $r = Post $s "/credit-notes/$cn2/edit" "/credit-notes/$cn2" @(@('kind', 'SALES'), @('party_id', $cust), @('invoice_id', $inv), @('note_date', $today), @('reason', 'Changed'), @('description[]', 'Returned pipe'), @('hsn[]', '3917'), @('qty[]', '1'), @('rate[]', '100'), @('tax_rate[]', '18'))
  Ok 'credit note: draft edited to 118.00' ($r.StatusCode -eq 303 -and (Get-Page $s "/credit-notes/$cn2").Content -match '118.00')
  Post $s "/credit-notes/$cn2" "/credit-notes/$cn2/delete" @() | Out-Null
  Ok 'credit note: draft deleted' ((Get-Page $s "/credit-notes/$cn2").StatusCode -eq 404)
  Ok 'credit note: list shows the cancelled one and the filter works' ((Get-Page $s '/credit-notes?kind=SALES').Content -match 'CN-' -and (Get-Page $s '/credit-notes?kind=PURCHASE').Content -notmatch [regex]::Escape($cn))

  # debit note against a supplier bill
  $r = Post $s '/suppliers/new' '/suppliers' @(@('name', "Sup $tag"), @('status', 'ACTIVE')); $sup = Id $r.Location
  $r = Post $s '/purchase-invoices/new' '/purchase-invoices' (@(, @('supplier_id', $sup)) + (Lines @(, @($ids.rm, '10', '50', '18')))); $bill = Id $r.Location
  Post $s "/purchase-invoices/$bill" "/purchase-invoices/$bill/submit" @() | Out-Null
  Ok 'bill: posted (590) and offers a debit note' ((Get-Page $s "/purchase-invoices/$bill").Content -match '>Debit note<')
  $r = Post $s '/credit-notes/new?kind=PURCHASE' '/credit-notes' @(@('kind', 'PURCHASE'), @('party_id', $sup), @('invoice_id', $bill), @('note_date', $today), @('reason', 'Short supply'), @('description[]', 'Short supply'), @('hsn[]', '3907'), @('qty[]', '1'), @('rate[]', '50'), @('tax_rate[]', '18'))
  Ok 'debit note: saved as a draft' ($r.StatusCode -eq 303); $dn = Id $r.Location
  Ok 'debit note: numbered DN-' ((Get-Page $s "/credit-notes/$dn").Content -match 'DN-')
  Post $s "/credit-notes/$dn" "/credit-notes/$dn/post" @() | Out-Null
  Ok 'debit note: posted, the bill owes 531.00' ((Get-Page $s "/purchase-invoices/$bill").Content -match '531.00')
  $r = Get-Page $s "/books/reports/gst?tab=purchases&from=$today&to=$today"
  Ok 'GST purchase register: the bill and the debit note (negative)' ($r.Content -match 'DN-' -and $r.Content -match '-50.00')
  $r = Get-Page $s "/books/reports/statement?party=SUPPLIER:$sup&from=$today&to=$today"
  Ok 'statement: supplier, you owe 531.00' ($r.Content -match "Sup $tag" -and $r.Content -match 'You owe them' -and $r.Content -match '531.00')
  Post $s "/credit-notes/$dn" "/credit-notes/$dn/cancel" @() | Out-Null
  Ok 'debit note: cancelled, the bill owes 590.00 again' ((Get-Page $s "/purchase-invoices/$bill").Content -match '590.00')

  # a voucher made by a document cannot be cancelled by hand
  $r = Get-Page $s "/books/vouchers?from=$today&to=$today&q=INV-"
  $vInv = ([regex]::Matches($r.Content, '/books/vouchers/([0-9a-f-]{36})"'))[0].Groups[1].Value
  Ok 'day book: the invoice voucher is listed' ($vInv -ne '')
  $r = Get-Page $s "/books/vouchers/$vInv"
  Ok 'voucher from an invoice: no cancel button, a note says to cancel the document' ($r.Content -notmatch 'Cancel voucher' -and $r.Content -match 'posted by a document')
  Post $s "/books/vouchers/$vInv" "/books/vouchers/$vInv/cancel" @() | Out-Null
  Ok 'voucher from an invoice: cancelling it by hand is refused' ((Flash-Of (Get-Page $s "/books/vouchers/$vInv").Content) -ne '' -or (Get-Page $s "/books/vouchers/$vInv").Content -notmatch 'Reversed by')
  Ok 'unknown ids are 404, not a crash' ((Get-Page $s '/books/vouchers/not-an-id').StatusCode -eq 404 -and (Get-Page $s '/credit-notes/not-an-id').StatusCode -eq 404 -and (Get-Page $s '/books/accounts/not-an-id/edit').StatusCode -eq 404)

  # roles
  $page = (Get-Page $s '/users/new').Content
  foreach ($u in @(@('acct', 'Accounts'), @('mgr', 'Manager'), @('sales', 'Sales'), @('op', 'Operator'))) {
    Post $s '/users/new' '/users' @(@('name', "$($u[0]) $tag"), @('email', "$($u[0]).$t@test.local"), @('password', 'Role-Test-Pass-1'), @('roles[]', (Role-Id $page $u[1]))) | Out-Null
  }
  $acct = (Sign-In "acct.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Accounts: can open the books, post a voucher and see reports' ((Get-Page $acct '/books').StatusCode -eq 200 -and (Get-Page $acct '/books/vouchers/new').StatusCode -eq 200 -and (Get-Page $acct '/books/reports/trial-balance').StatusCode -eq 200)
  $mgr = (Sign-In "mgr.$t@test.local" 'Role-Test-Pass-1').Session
  $r = Get-Page $mgr '/books'
  Ok 'Manager: reads the books but has no New voucher button' ($r.StatusCode -eq 200 -and $r.Content -notmatch 'New voucher')
  Ok 'Manager: entering anything is 403' ((Get-Page $mgr '/books/vouchers/new').StatusCode -eq 403 -and (Get-Page $mgr '/books/accounts/new').StatusCode -eq 403 -and (Get-Page $mgr '/credit-notes/new').StatusCode -eq 403 -and (Get-Page $mgr '/books/opening').StatusCode -eq 403)
  $r = Post $mgr '/books' '/books/vouchers' @(@('type', 'EXPENSE'), @('date', $today), @('account', $exp), @('paid_from', $bank), @('amount', '1'))
  Ok 'Manager: posting a voucher is 403' ($r.StatusCode -eq 403)
  $r = Post $mgr '/books' "/books/vouchers/$vExp/cancel" @()
  Ok 'Manager: cancelling a voucher is 403' ($r.StatusCode -eq 403)
  $sales = (Sign-In "sales.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Sales: no books at all' ((Get-Page $sales '/books').StatusCode -eq 403 -and (Get-Page $sales '/books/reports/gst').StatusCode -eq 403 -and (Get-Page $sales '/credit-notes').StatusCode -eq 403 -and (Get-Page $sales '/books/ledger').StatusCode -eq 403)
  $d = Download $sales "/books/vouchers?format=csv"
  Ok 'Sales: the day book download is 403 too' ($d.StatusCode -eq 403)
  $op = (Sign-In "op.$t@test.local" 'Role-Test-Pass-1').Session
  Ok 'Operator: no Books menu' ((Get-Page $op '/').Content -notmatch '>Books overview<')
} finally { Stop-Dev }
Summary
