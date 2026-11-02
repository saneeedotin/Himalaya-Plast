<?php
declare(strict_types=1);

/**
 * The books against the stored procedures: every document posts balanced vouchers, cancellations reverse them,
 * manual vouchers are validated, nothing can be edited after posting, and the reports agree with each other.
 *
 *   php tools/books_test.php
 *
 * Writes test rows with random codes. Refuses to run unless the database name ends in _scratch or _test.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;

$dbName = (string) Config::get('db.name');
if (!preg_match('/_(scratch|test)$/', $dbName)) {
    fwrite(STDERR, "Refusing to run against '$dbName'. Use a database ending in _scratch or _test.\n");
    exit(2);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, $cond || $detail === '' ? '' : "  ($detail)");
}
function step(string $label, callable $fn): mixed
{
    try {
        $v = $fn();
        check($label, true);
        return $v;
    } catch (Throwable $e) {
        check($label, false, $e->getMessage());
        return null;
    }
}
function refuses(string $label, callable $fn, string $needle): void
{
    try {
        $fn();
        check($label, false, 'expected an error, call succeeded');
    } catch (Throwable $e) {
        $hit = stripos($e->getMessage(), $needle) !== false;
        check($label, $hit, $hit ? '' : 'got: ' . $e->getMessage());
    }
}
function one(string $sql, array $args = []): mixed
{
    $st = Db::pdo()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}
function money(mixed $v): float
{
    return round((float) $v, 2);
}
function acct(string $code): string
{
    return (string) one('SELECT id FROM gl_accounts WHERE code = ?', [$code]);
}
/** Debit minus credit on an account (optionally for one party) */
function bal(string $code, ?string $party = null): float
{
    return money(one('SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.code = ?' . ($party ? ' AND e.party_id = ?' : ''), $party ? [$code, $party] : [$code]));
}
/** Debit minus credit across every account of a kind (cash, bank...) */
function balKind(string $subtype): float
{
    return money(one('SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = ?', [$subtype]));
}
function voucherOf(string $type, string $id): ?array
{
    $st = Db::pdo()->prepare("SELECT * FROM gl_vouchers WHERE source_type = ? AND source_id = ? AND reverses_id IS NULL ORDER BY created_at DESC LIMIT 1");
    $st->execute([$type, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** The lines of a voucher as "code:debit:credit" */
function lines(string $voucherId): array
{
    $st = Db::pdo()->prepare('SELECT a.code, e.debit, e.credit FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE e.voucher_id = ? ORDER BY e.line_no');
    $st->execute([$voucherId]);
    return array_map(static fn (array $r): string => $r['code'] . ':' . (float) $r['debit'] . ':' . (float) $r['credit'], $st->fetchAll(PDO::FETCH_ASSOC));
}
function allBalanced(): bool
{
    return money(one('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM gl_entries')) === 0.0;
}

$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
$admin = (string) (Db::row('sp_auth_get_user_by_email', [$devAdmin !== '' ? $devAdmin : 'admin@himalayaplast.local'])['id'] ?? '');
check('the administrator exists', $admin !== '');
if ($admin === '') {
    exit(1);
}
$tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$ip = '127.0.0.1';
$today = date('Y-m-d');
$roles = array_column(Db::rows('sp_role_list', [$admin]), 'id', 'name');

echo "== Chart of accounts\n";
check('the standard chart is there (system accounts for every posting)', (int) one("SELECT COUNT(*) FROM gl_accounts WHERE is_system = 1") >= 14 && (int) one('SELECT COUNT(*) FROM gl_accounts') >= 35);
$list = Db::rows('sp_gl_account_list', [$admin, null]);
check('the account list loads, ordered by code, all at zero on an empty ledger or with real balances', count($list) >= 35 && $list[0]['code'] <= $list[1]['code']);
$bank2 = step('add a bank account (HDFC)', fn () => Db::callOut('sp_gl_account_save', [$admin, null, "HD$tag", "HDFC Current $tag", 'ASSET', 'BANK', 'main account'])[0]);
refuses('a duplicate code is refused', fn () => Db::callOut('sp_gl_account_save', [$admin, null, "HD$tag", 'Other', 'ASSET', 'BANK', null]), 'already used');
refuses('a made-up kind of account is refused', fn () => Db::callOut('sp_gl_account_save', [$admin, null, "XX$tag", 'Odd', 'ASSET', 'DEBTORS', null]), 'not allowed');
refuses('a bad code is refused', fn () => Db::callOut('sp_gl_account_save', [$admin, null, 'a b', 'Odd', 'ASSET', 'GENERAL', null]), 'code is');
$sysId = acct('1200');
step('a system account can be renamed', fn () => Db::callOut('sp_gl_account_save', [$admin, $sysId, '9999', 'Trade Debtors', 'LIABILITY', 'GENERAL', null]));
check('but it keeps its code, type and role', one('SELECT CONCAT(code, acct_type, subtype) FROM gl_accounts WHERE id = ?', [$sysId]) === '1200ASSETDEBTORS');
Db::callOut('sp_gl_account_save', [$admin, $sysId, '1200', 'Sundry Debtors', 'ASSET', 'DEBTORS', null]);
refuses('a system account cannot be switched off', fn () => Db::call('sp_gl_account_set_active', [$admin, $sysId, 0]), 'system account');

echo "\n== Parties, items\n";
$gstSame = '24' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 13));
$gstOther = '07' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 13));
$custA = Db::callOut('sp_customer_save', [$admin, null, "Books Cust A $tag", $gstSame, null, null])[0];
$custB = Db::callOut('sp_customer_save', [$admin, null, "Books Cust B $tag", $gstOther, null, null])[0];
$sup = Db::callOut('sp_supplier_save', [$admin, null, "Books Supplier $tag", null, null, null, 'ACTIVE'])[0];
$fg = Db::callOut('sp_item_save', [$admin, null, "BFG-$tag", "Books FG $tag", 'FINISHED_GOODS', 'M', '3926', 40, 0, null])[0];
$rm = Db::callOut('sp_item_save', [$admin, null, "BRM-$tag", "Books RM $tag", 'RAW_MATERIAL', 'KG', '3904', 100, 0, null])[0];
check('setup: two customers (one in another state), a supplier and two items', $custA && $custB && $sup && $fg && $rm);

echo "\n== Sales invoice posts to the books\n";
$tbBefore = allBalanced();
$invA = Db::callOut('sp_sales_invoice_create', [$admin, $custA, null, null, null, null])[0];
Db::call('sp_sales_invoice_set_items', [$admin, $invA, json_encode([['itemId' => $fg, 'qty' => 100, 'rate' => 50, 'taxRate' => 18]])]);
check('a draft invoice is not in the books yet', voucherOf('SALES_INVOICE', $invA) === null);
Db::call('sp_sales_invoice_submit', [$admin, $invA, null, null]);
$v = voucherOf('SALES_INVOICE', $invA);
check('posting the invoice made one SALES voucher', $v !== null && $v['voucher_type'] === 'SALES' && str_contains((string) $v['narration'], 'Sales invoice'));
check('same-state: Dr Debtors 5900, Cr Sales 5000, Cr CGST 450, Cr SGST 450', $v !== null && lines($v['id']) === ['1200:5900:0', '4000:0:5000', '2100:0:450', '2110:0:450'], $v ? implode(' ', lines($v['id'])) : 'no voucher');
check('the debtors line names the customer', (int) one("SELECT COUNT(*) FROM gl_entries WHERE voucher_id = ? AND party_type = 'CUSTOMER' AND party_id = ?", [$v['id'], $custA]) === 1);
check('the whole ledger still balances', allBalanced());

$invB = Db::callOut('sp_sales_invoice_create', [$admin, $custB, null, null, null, null])[0];
Db::call('sp_sales_invoice_set_items', [$admin, $invB, json_encode([['itemId' => $fg, 'qty' => 10, 'rate' => 100, 'taxRate' => 12]])]);
Db::call('sp_sales_invoice_submit', [$admin, $invB, null, null]);
$vB = voucherOf('SALES_INVOICE', $invB);
check('other state: the tax goes to IGST (Dr Debtors 1120, Cr Sales 1000, Cr IGST 120)', $vB !== null && lines($vB['id']) === ['1200:1120:0', '4000:0:1000', '2120:0:120'], $vB ? implode(' ', lines($vB['id'])) : '');
refuses('an invoice cannot be posted into the books twice', fn () => Db::call('sp_gl_post_sales_invoice', [$invA]), 'already in the books');

echo "\n== Payments\n";
$payBank = Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $custA, 'BANK_TRANSFER', 'UTR-' . $tag, 2000, $invA, null, null])[0];
check('a draft payment is not in the books', voucherOf('PAYMENT', $payBank) === null);
Db::call('sp_payment_submit', [$admin, $payBank]);
$vp = voucherOf('PAYMENT', $payBank);
check('a bank-transfer receipt: Dr Bank 2000, Cr Debtors 2000', $vp !== null && $vp['voucher_type'] === 'RECEIPT' && lines($vp['id']) === ['1100:2000:0', '1200:0:2000'], $vp ? implode(' ', lines($vp['id'])) : '');
$payCash = Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $custA, 'CASH', null, 500, $invA, null, null])[0];
Db::call('sp_payment_submit', [$admin, $payCash]);
check('a cash receipt goes to Cash in Hand', in_array('1000:500:0', lines(voucherOf('PAYMENT', $payCash)['id']), true));
$payChq = Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $custA, 'CHEQUE', 'CHQ1', 300, $invA, null, null])[0];
Db::call('sp_payment_submit', [$admin, $payChq]);
check('a cheque receipt goes to Cheques in Hand', in_array('1110:300:0', lines(voucherOf('PAYMENT', $payChq)['id']), true));
$payAcct = Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $custA, 'BANK_TRANSFER', 'UTR2', 200, $invA, null, null])[0];
Db::call('sp_payment_set_account', [$admin, $payAcct, $bank2]);
Db::call('sp_payment_submit', [$admin, $payAcct]);
check('a payment can name the bank account it went through', in_array("HD$tag:200:0", lines(voucherOf('PAYMENT', $payAcct)['id']), true), implode(' ', lines(voucherOf('PAYMENT', $payAcct)['id'])));
refuses('the account cannot change after posting', fn () => Db::call('sp_payment_set_account', [$admin, $payAcct, $bank2]), 'posted');
refuses('only a cash or bank account can be chosen', fn () => Db::call('sp_payment_set_account', [$admin, Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $custA, 'CASH', null, 1, null, null, null])[0], acct('4000')]), 'cash, bank');
check('the customer account is what is left: 5900 - 2000 - 500 - 300 - 200 = 2900', bal('1200', $custA) === 2900.0, (string) bal('1200', $custA));
$chequeBefore = bal('1110');
step('cancel the cheque payment', fn () => Db::call('sp_payment_cancel', [$admin, $payChq]));
check('cancelling a payment marks its voucher REVERSED (nothing is deleted)', voucherOf('PAYMENT', $payChq)['status'] === 'REVERSED');
check('the cheques account lost the 300 it had received', bal('1110') === $chequeBefore - 300.0, bal('1110') . ' vs ' . $chequeBefore);
check('the customer balance is 3200 again', bal('1200', $custA) === 3200.0, (string) bal('1200', $custA));
check('both vouchers are kept: 1 reversed + 1 cancellation', (int) one("SELECT COUNT(*) FROM gl_vouchers WHERE source_type = 'PAYMENT' AND source_id = ?", [$payChq]) === 2);

echo "\n== Cancelling an invoice\n";
refuses('an invoice with payments cannot be cancelled (existing rule)', fn () => Db::call('sp_sales_invoice_cancel', [$admin, $invA]), 'payments');
step('cancel the other-state invoice', fn () => Db::call('sp_sales_invoice_cancel', [$admin, $invB]));
check('its customer balance is back to nothing', bal('1200', $custB) === 0.0, (string) bal('1200', $custB));
check('the ledger still balances', allBalanced());

echo "\n== Purchase bill and supplier payment\n";
$bill = Db::callOut('sp_purchase_invoice_create', [$admin, $sup, null, null, null, json_encode([['itemId' => $rm, 'qty' => 10, 'rate' => 100, 'taxRate' => 18]])])[0];
check('a draft bill is not in the books', voucherOf('PURCHASE_INVOICE', $bill) === null);
Db::call('sp_purchase_invoice_submit', [$admin, $bill]);
$vb = voucherOf('PURCHASE_INVOICE', $bill);
check('Dr Purchases 1000, Dr Input CGST 90, Dr Input SGST 90, Cr Creditors 1180', $vb !== null && lines($vb['id']) === ['5000:1000:0', '1300:90:0', '1310:90:0', '2000:0:1180'], $vb ? implode(' ', lines($vb['id'])) : '');
$paySup = Db::callOut('sp_payment_create', [$admin, 'SUPPLIER', $sup, 'BANK_TRANSFER', 'UTR3', 400, null, $bill, null])[0];
Db::call('sp_payment_submit', [$admin, $paySup]);
check('a supplier payment: Dr Creditors 400, Cr Bank 400', lines(voucherOf('PAYMENT', $paySup)['id']) === ['2000:400:0', '1100:0:400']);
check('we owe the supplier 780', bal('2000', $sup) === -780.0, (string) bal('2000', $sup));

echo "\n== Credit and debit notes\n";
$cn = step('draft a credit note for 10 m at 50 + 18%', fn () => Db::callOut('sp_credit_note_save', [$admin, null, 'SALES', $custA, $invA, $today, 'damaged in transit', json_encode([['itemId' => $fg, 'description' => 'Door gasket returned', 'hsn' => '3926', 'qty' => 10, 'rate' => 50, 'taxRate' => 18]])])[0]);
$cnRow = Db::row('sp_credit_note_get', [$admin, $cn]);
check('totals: 500 + CGST 45 + SGST 45 = 590; numbered CN-', money($cnRow['total_amount']) === 590.0 && money($cnRow['cgst_amount']) === 45.0 && str_starts_with($cnRow['note_number'], 'CN-'));
check('a draft is not in the books', voucherOf('CREDIT_NOTE', $cn) === null);
refuses('a note larger than its invoice is refused', fn () => Db::callOut('sp_credit_note_save', [$admin, null, 'SALES', $custA, $invA, $today, null, json_encode([['description' => 'x', 'qty' => 1, 'rate' => 99999, 'taxRate' => 0]])]), 'more than the invoice');
refuses('an invoice of another customer is refused', fn () => Db::callOut('sp_credit_note_save', [$admin, null, 'SALES', $custB, $invA, $today, null, json_encode([['description' => 'x', 'qty' => 1, 'rate' => 1, 'taxRate' => 0]])]), 'does not belong');
refuses('a line without a description is refused', fn () => Db::callOut('sp_credit_note_save', [$admin, null, 'SALES', $custA, null, $today, null, json_encode([['description' => '', 'qty' => 1, 'rate' => 1, 'taxRate' => 0]])]), 'description');
$out0 = money(one('SELECT outstanding FROM sales_invoices WHERE id = ?', [$invA]));
step('post it', fn () => Db::call('sp_credit_note_post', [$admin, $cn]));
$vc = voucherOf('CREDIT_NOTE', $cn);
check('Dr Sales Returns 500, Dr Output CGST 45, Dr Output SGST 45, Cr Debtors 590', $vc !== null && lines($vc['id']) === ['4200:500:0', '2100:45:0', '2110:45:0', '1200:0:590'], $vc ? implode(' ', lines($vc['id'])) : '');
check('the invoice owes 590 less', money(one('SELECT outstanding FROM sales_invoices WHERE id = ?', [$invA])) === $out0 - 590.0, (string) one('SELECT outstanding FROM sales_invoices WHERE id = ?', [$invA]));
refuses('a posted note cannot be edited', fn () => Db::callOut('sp_credit_note_save', [$admin, $cn, 'SALES', $custA, $invA, $today, null, json_encode([['description' => 'x', 'qty' => 1, 'rate' => 1, 'taxRate' => 0]])]), 'cannot be edited');
step('cancel the credit note', fn () => Db::call('sp_credit_note_cancel', [$admin, $cn]));
check('the invoice owes the 590 again, and the customer balance is back', money(one('SELECT outstanding FROM sales_invoices WHERE id = ?', [$invA])) === $out0 && bal('1200', $custA) === 3200.0);
$dn = Db::callOut('sp_credit_note_save', [$admin, null, 'PURCHASE', $sup, $bill, $today, 'short delivery', json_encode([['description' => 'Short delivery', 'qty' => 2, 'rate' => 100, 'taxRate' => 18]])])[0];
Db::call('sp_credit_note_post', [$admin, $dn]);
check('a debit note: Dr Creditors 236, Cr Purchase Returns 200, Cr Input CGST 18, Cr Input SGST 18', lines(voucherOf('CREDIT_NOTE', $dn)['id']) === ['2000:236:0', '5100:0:200', '1300:0:18', '1310:0:18']);
check('numbered DN-', str_starts_with((string) Db::row('sp_credit_note_get', [$admin, $dn])['note_number'], 'DN-'));
$draft = Db::callOut('sp_credit_note_save', [$admin, null, 'SALES', $custA, null, $today, null, json_encode([['description' => 'x', 'qty' => 1, 'rate' => 10, 'taxRate' => 0]])])[0];
step('a draft note can be deleted', fn () => Db::call('sp_credit_note_delete', [$admin, $draft]));
refuses('a posted note cannot be deleted', fn () => Db::call('sp_credit_note_delete', [$admin, $dn]), 'draft');

echo "\n== Manual vouchers\n";
$cash = acct('1000'); $bank = acct('1100'); $capital = acct('3000'); $rent = acct('5310'); $loans = acct('2200'); $debtors = acct('1200'); $sales = acct('4000');
$line = static fn (string $a, float $dr, float $cr, ?array $p = null): array => ['account' => $a, 'debit' => $dr, 'credit' => $cr] + ($p ? ['partyType' => $p[0], 'partyId' => $p[1]] : []);
$vj = step('a journal that balances', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, 'adjustment', 'J1', json_encode([$line($rent, 100, 0), $line($loans, 0, 100)]), $ip])[0]);
refuses('an unbalanced voucher is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, null, null, json_encode([$line($rent, 100, 0), $line($loans, 0, 90)]), $ip]), 'does not balance');
refuses('a line with both debit and credit is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, null, null, json_encode([$line($rent, 100, 100), $line($loans, 0, 0)]), $ip]), 'either Debit or Credit');
refuses('a single line is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, null, null, json_encode([$line($rent, 100, 0)]), $ip]), 'at least two');
refuses('a future date is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', date('Y-m-d', strtotime('+3 days')), null, null, json_encode([$line($rent, 10, 0), $line($loans, 0, 10)]), $ip]), 'future');
refuses('an unknown account is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, null, null, json_encode([$line('00000000-0000-0000-0000-000000000000', 10, 0), $line($loans, 0, 10)]), $ip]), 'not found');
refuses('a debtors line without a customer is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, null, null, json_encode([$line($debtors, 10, 0), $line($loans, 0, 10)]), $ip]), 'customer or supplier');
step('a debtors line with its customer is accepted', fn () => Db::callOut('sp_voucher_create', [$admin, 'JOURNAL', $today, 'bad debt written off', null, json_encode([$line(acct('5900'), 25, 0), $line($debtors, 0, 25, ['CUSTOMER', $custA])]), $ip]));
check('and the customer balance moved by it (3200 - 25)', bal('1200', $custA) === 3175.0);
step('a contra: bank to cash', fn () => Db::callOut('sp_voucher_create', [$admin, 'CONTRA', $today, 'cash withdrawn', null, json_encode([$line($cash, 1000, 0), $line($bank, 0, 1000)]), $ip]));
refuses('a contra with a non-cash account is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'CONTRA', $today, null, null, json_encode([$line($cash, 10, 0), $line($rent, 0, 10)]), $ip]), 'between two cash');
step('an expense: rent paid in cash', fn () => Db::callOut('sp_voucher_create', [$admin, 'EXPENSE', $today, 'office rent', "RENT-$tag", json_encode([$line($rent, 700, 0), $line($cash, 0, 700)]), $ip]));
refuses('an expense that debits no expense account is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'EXPENSE', $today, null, null, json_encode([$line($loans, 10, 0), $line($cash, 0, 10)]), $ip]), 'expense account');
step('a receipt: capital put into the bank', fn () => Db::callOut('sp_voucher_create', [$admin, 'RECEIPT', $today, 'capital', null, json_encode([$line($bank, 5000, 0), $line($capital, 0, 5000)]), $ip]));
refuses('a receipt that does not debit cash or bank is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'RECEIPT', $today, null, null, json_encode([$line($rent, 10, 0), $line($capital, 0, 10)]), $ip]), 'cash or bank');
step('a payment: loan repaid from the bank', fn () => Db::callOut('sp_voucher_create', [$admin, 'PAYMENT', $today, 'loan instalment', null, json_encode([$line($loans, 200, 0), $line($bank, 0, 200)]), $ip]));
refuses('an unknown voucher type is refused', fn () => Db::callOut('sp_voucher_create', [$admin, 'SALES', $today, null, null, json_encode([$line($rent, 10, 0), $line($loans, 0, 10)]), $ip]), 'voucher type');
step('cancel the first journal', fn () => Db::call('sp_voucher_cancel', [$admin, $vj, 'entered twice', $ip]));
check('cancelling reversed it: the original is REVERSED and a cancellation voucher exists', one('SELECT status FROM gl_vouchers WHERE id = ?', [$vj]) === 'REVERSED' && (int) one('SELECT COUNT(*) FROM gl_vouchers WHERE reverses_id = ?', [$vj]) === 1);
refuses('a voucher cannot be cancelled twice', fn () => Db::call('sp_voucher_cancel', [$admin, $vj, null, $ip]), 'already cancelled');
refuses('a voucher made by an invoice must be cancelled through the invoice', fn () => Db::call('sp_voucher_cancel', [$admin, voucherOf('SALES_INVOICE', $invA)['id'], null, $ip]), 'Cancel that document');

echo "\n== The books cannot be edited\n";
refuses('an entry cannot be updated', fn () => Db::pdo()->exec('UPDATE gl_entries SET debit = 1 LIMIT 1'), 'cannot be changed');
refuses('an entry cannot be deleted', fn () => Db::pdo()->exec('DELETE FROM gl_entries LIMIT 1'), 'cannot be deleted');
refuses('a voucher cannot be deleted', fn () => Db::pdo()->exec('DELETE FROM gl_vouchers LIMIT 1'), 'cannot be deleted');
refuses('a voucher narration cannot be changed', fn () => Db::pdo()->exec("UPDATE gl_vouchers SET narration = 'x' LIMIT 1"), 'cannot be changed');
refuses('a one-sided entry is refused by the table itself', fn () => Db::pdo()->exec("INSERT INTO gl_entries (voucher_id, line_no, account_id, debit, credit) SELECT id, 99, '$cash', 5, 5 FROM gl_vouchers LIMIT 1"), 'check');
check('after all of this the ledger balances to the paisa', allBalanced());

echo "\n== Opening balances\n";
// a repeat run on the same database: cancel the opening balances an earlier run entered
foreach (Db::pdo()->query("SELECT id FROM gl_vouchers WHERE voucher_type = 'OPENING' AND status = 'POSTED' AND reverses_id IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $oldOpening) {
    Db::call('sp_voucher_cancel', [$admin, $oldOpening, 'test re-run', $ip]);
}
$other = Db::callOut('sp_customer_save', [$admin, null, "Opening Cust $tag", null, null, null])[0];
$ob = step('enter opening balances: cash 1000, loans 400 owed, a customer owes 250', fn () => Db::callOut('sp_opening_balances_save', [$admin, $today, json_encode([$line($cash, 1000, 0), $line($loans, 0, 400), $line($debtors, 250, 0, ['CUSTOMER', $other])]), $ip])[0]);
check('they balance against Opening Balance Difference (1000 + 250 - 400 = 850 credit)', $ob !== null && in_array('3900:0:850', lines($ob), true), $ob ? implode(' ', lines($ob)) : '');
check('the customer opening balance shows on their account', bal('1200', $other) === 250.0);
refuses('opening balances cannot be entered twice', fn () => Db::callOut('sp_opening_balances_save', [$admin, $today, json_encode([$line($cash, 1, 0), $line($loans, 0, 1)]), $ip]), 'already entered');

echo "\n== Reports\n";
$tb = Db::rows('sp_gl_trial_balance', [$admin, null]);
$dr = array_sum(array_column($tb, 'debit_total'));
$cr = array_sum(array_column($tb, 'credit_total'));
check('trial balance: total debits equal total credits', money($dr) === money($cr) && $dr > 0, "$dr vs $cr");
check('trial balance: only accounts with entries, by code', count($tb) >= 8 && $tb[0]['code'] <= $tb[1]['code']);
$led = Db::call('sp_gl_ledger', [$admin, acct('1200'), null, null, 'CUSTOMER', $custA]);
check('party ledger: the running balance ends at the customer balance', money(end($led[1])['balance']) === bal('1200', $custA), (string) end($led[1])['balance']);
$ledFrom = Db::call('sp_gl_ledger', [$admin, $bank, $today, $today, null, null]);
check('ledger from a date: the opening balance is what came before', money($ledFrom[0][0]['opening']) === money(one("SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id WHERE e.account_id = ? AND v.voucher_date < ?", [$bank, $today])));
check('ledger rows say what each entry was against', ($ledFrom[1][0]['against'] ?? '') !== '' || count($ledFrom[1]) === 0);
$book = Db::rows('sp_gl_day_book', [$admin, $today, $today, null, null]);
check('day book: has entries for today, grouped by voucher', count($book) >= 10 && isset($book[0]['voucher_number']));
check('day book: filters by voucher type', count(array_filter(Db::rows('sp_gl_day_book', [$admin, null, null, 'CONTRA', null]), static fn (array $r): bool => $r['voucher_type'] !== 'CONTRA')) === 0);
check('day book: finds a voucher by its reference', count(Db::rows('sp_gl_day_book', [$admin, null, null, null, "RENT-$tag"])) === 2);
$pl = Db::call('sp_gl_profit_loss', [$admin, null, null]);
$byCode = array_column($pl[0], 'amount', 'code');
check('profit and loss: Sales is shown as income and Rent as expense', isset($byCode['4000']) && isset($byCode['5310']) && money($byCode['5310']) >= 700.0);
check('profit and loss: sales returns reduce income (a cancelled note nets to zero)', !isset($byCode['4200']) || money($byCode['4200']) === 0.0);
check('profit and loss: gives opening and closing stock', isset($pl[1][0]['opening_stock']) && isset($pl[1][0]['closing_stock']));
$bs = Db::call('sp_gl_balance_sheet', [$admin, null]);
$assets = $liab = $eq = 0.0;
foreach ($bs[0] as $r) {
    if ($r['acct_type'] === 'ASSET') { $assets += (float) $r['balance']; }
    elseif ($r['acct_type'] === 'LIABILITY') { $liab += -(float) $r['balance']; }
    else { $eq += -(float) $r['balance']; }
}
$stockV = (float) $bs[1][0]['stock_value']; $intro = (float) $bs[1][0]['stock_introduced']; $profit = (float) $bs[1][0]['books_profit'] + $stockV - $intro;
check('balance sheet: assets (with stock) = liabilities + capital + profit to date + opening stock brought in', abs(($assets + $stockV) - ($liab + $eq + $profit + $intro)) < 0.01, ($assets + $stockV) . ' vs ' . ($liab + $eq + $profit + $intro));
$stat = Db::call('sp_gl_party_statement', [$admin, 'CUSTOMER', $custA, null, null]);
check('party statement: opening and the last running balance match the ledger', money(end($stat[1])['balance']) === bal('1200', $custA));
$stS = Db::call('sp_gl_party_statement', [$admin, 'SUPPLIER', $sup, null, null]);
check('supplier statement: the last balance is what we owe (credit, so negative)', money(end($stS[1])['balance']) === bal('2000', $sup));
$dash = Db::call('sp_books_dashboard', [$admin]);
check('dashboard: cash and bank balances equal their ledgers', money($dash[0][0]['cash']) === balKind('CASH') && money($dash[0][0]['bank']) === balKind('BANK') && money($dash[0][0]['cheque']) === balKind('CHEQUE') && money($dash[0][0]['receivables']) === balKind('DEBTORS'));
check('dashboard: lists the cash and bank accounts and the recent vouchers', count($dash[1]) >= 5 && count($dash[2]) === 8);

echo "\n== GST\n";
$reg = Db::rows('sp_gst_sales_register', [$admin, null, null]);
check('sales register: has the live invoice and not the cancelled one', count(array_filter($reg, static fn (array $r): bool => $r['doc_type'] === 'INVOICE' && str_contains((string) $r['party'], "Cust B $tag"))) === 0 && count(array_filter($reg, static fn (array $r): bool => str_contains((string) $r['party'], "Cust A $tag"))) >= 1);
$preg = Db::rows('sp_gst_purchase_register', [$admin, null, null]);
check('purchase register: has the bill and the debit note as a negative row', count(array_filter($preg, static fn (array $r): bool => $r['doc_type'] === 'DEBIT NOTE' && (float) $r['total'] === -236.0 && str_contains((string) $r['party'], $tag))) === 1);
$sum = Db::row('sp_gst_summary', [$admin, $today, $today]);
$glDay = static fn (string $code, string $side): float => money(one("SELECT COALESCE(SUM(e.$side), 0) FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id WHERE a.code = ? AND v.voucher_date = ?", [$code, $today]));
check('GST summary: output CGST and SGST agree with the ledger (credits less debits on the output accounts, today)', money($sum['out_cgst']) === money($glDay('2100', 'credit') - $glDay('2100', 'debit')) && money($sum['out_sgst']) === money($glDay('2110', 'credit') - $glDay('2110', 'debit')), json_encode($sum));
check('GST summary: input tax agrees with the ledger too (the bill less the debit note)', money($sum['in_cgst']) === money($glDay('1300', 'debit') - $glDay('1300', 'credit')) && money($sum['in_sgst']) === money($glDay('1310', 'debit') - $glDay('1310', 'credit')));
$hsn = Db::rows('sp_gst_hsn_summary', [$admin, null, null]);
check('HSN summary: sales are grouped by HSN with quantity and tax', count(array_filter($hsn, static fn (array $r): bool => $r['hsn'] === '3926' && (float) $r['qty'] >= 100.0)) === 1);

echo "\n== Backfill and permissions\n";
$n = (int) one('SELECT COUNT(*) FROM gl_vouchers');
Db::call('sp_gl_backfill');
check('backfill adds nothing when everything is already in the books', (int) one('SELECT COUNT(*) FROM gl_vouchers') === $n);
$acctU = Db::callOut('sp_user_create', [$admin, "bk.acct.$tag@test.local", "Books Accounts $tag", Auth::hash('Accounts-Pass-123'), null, json_encode([$roles['Accounts']]), $ip])[0];
$opU = Db::callOut('sp_user_create', [$admin, "bk.op.$tag@test.local", "Books Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0];
$salesU = Db::callOut('sp_user_create', [$admin, "bk.sales.$tag@test.local", "Books Sales $tag", Auth::hash('Sales-Pass-12345'), null, json_encode([$roles['Sales']]), $ip])[0];
step('Accounts can read the trial balance', fn () => Db::rows('sp_gl_trial_balance', [$acctU, null]));
step('Accounts can enter a voucher', fn () => Db::callOut('sp_voucher_create', [$acctU, 'EXPENSE', $today, 'tea', null, json_encode([$line($rent, 5, 0), $line($cash, 0, 5)]), $ip]));
foreach (['Operator' => $opU, 'Sales' => $salesU] as $who => $u) {
    refuses("$who cannot read the trial balance", fn () => Db::rows('sp_gl_trial_balance', [$u, null]), 'Forbidden');
    refuses("$who cannot read the day book", fn () => Db::rows('sp_gl_day_book', [$u, null, null, null, null]), 'Forbidden');
    refuses("$who cannot enter a voucher", fn () => Db::callOut('sp_voucher_create', [$u, 'JOURNAL', $today, null, null, json_encode([$line($rent, 1, 0), $line($loans, 0, 1)]), $ip]), 'Forbidden');
    refuses("$who cannot add an account", fn () => Db::callOut('sp_gl_account_save', [$u, null, "ZZ$who$tag", 'No', 'ASSET', 'GENERAL', null]), 'Forbidden');
    refuses("$who cannot write a credit note", fn () => Db::callOut('sp_credit_note_save', [$u, null, 'SALES', $custA, null, $today, null, json_encode([['description' => 'x', 'qty' => 1, 'rate' => 1, 'taxRate' => 0]])]), 'Forbidden');
}
refuses('an unknown actor is refused', fn () => Db::rows('sp_gl_trial_balance', [null, null]), 'Forbidden');
check('the books balance at the very end', allBalanced());

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
