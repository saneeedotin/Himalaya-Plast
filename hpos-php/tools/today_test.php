<?php
declare(strict_types=1);

/**
 * "What to do today" against the stored procedures: one list built from the repeat-order, leads, buying, deadline,
 * machine-stock and QC logic. Each line is counted before and after a change, so the test is not thrown by rows other
 * tests left in the scratch database. A person sees only the lines for screens they may open.
 *
 *   php tools/today_test.php
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
function one(string $sql, array $args = []): mixed
{
    $st = Db::pdo()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}
/** The list for a person, by kind. */
function today(string $actor): array
{
    return array_column(Db::rows('sp_today_suggestions', [$actor]), null, 'kind');
}
function qty(array $t, string $kind): int
{
    return (int) ($t[$kind]['qty'] ?? 0);
}

$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
$admin = (string) (Db::row('sp_auth_get_user_by_email', [$devAdmin !== '' ? $devAdmin : 'admin@himalayaplast.local'])['id'] ?? '');
check('the administrator exists', $admin !== '');
if ($admin === '') {
    exit(1);
}
$tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$ip = '127.0.0.1';
$roles = array_column(Db::rows('sp_role_list', [$admin]), 'id', 'name');
$fg = Db::callOut('sp_item_save', [$admin, null, "TFG-$tag", "Today FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
$cust = Db::callOut('sp_customer_save', [$admin, null, "Today Cust $tag", null, null, '9000000000'])[0];
$rate = (float) one('SELECT metres_per_day FROM v_output_rate');

/** An order in the given status; $set adds column values */
function order(string $customer, float $qty, int $dueDays, string $status, array $set = []): string
{
    global $admin, $fg;
    $id = Db::callOut('sp_sales_order_create', [$admin, $customer, null, null, date('Y-m-d', strtotime("+$dueDays days")) . ' 00:00:00', 'EMAIL', null, json_encode([['itemId' => $fg, 'qty' => $qty, 'rate' => 10]])])[0];
    $cols = ['status = ?'];
    $args = [$status];
    foreach ($set as $k => $v) {
        $cols[] = "$k = ?";
        $args[] = $v;
    }
    $args[] = $id;
    Db::pdo()->prepare('UPDATE sales_orders SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($args);
    return $id;
}
/** A customer whose orders were placed on the given days ago */
function customer(string $name, array $daysAgo): string
{
    global $admin, $fg;
    $id = Db::callOut('sp_customer_save', [$admin, null, $name, null, null, '9000000000'])[0];
    foreach ($daysAgo as $d) {
        $o = Db::callOut('sp_sales_order_create', [$admin, $id, null, null, date('Y-m-d H:i:s', strtotime('+10 days')), 'EMAIL', null, json_encode([['itemId' => $fg, 'qty' => 100, 'rate' => 40]])])[0];
        Db::pdo()->prepare("UPDATE sales_orders SET transaction_date = ?, status = 'DISPATCHED' WHERE id = ?")->execute([date('Y-m-d 10:00:00', strtotime("-$d days")), $o]);
    }
    return $id;
}
function material(string $code, float $stock, float $used, float $min = 0): string
{
    global $admin, $ip, $tag;
    $id = Db::callOut('sp_item_save', [$admin, null, "$code-$tag", "Material $code $tag", 'RAW_MATERIAL', 'KG', null, 25, $min, null])[0];
    if ($stock + $used > 0) {
        Db::callOut('sp_stock_adjust', [$admin, $id, 'IN', $stock + $used, 'OPENING', 'today test', $ip]);
    }
    if ($used > 0) {
        Db::pdo()->prepare("INSERT INTO stock_ledger_entries (item_id, warehouse, quantity, movement_type, reference_id, notes, created_at) VALUES (?, 'MAIN', ?, 'CONSUMPTION', 'test', 'today test', ?)")
            ->execute([$id, $used, date('Y-m-d H:i:s', strtotime('-9 days'))]);
    }
    return $id;
}

$base = today($admin);
check('the list comes back as lines with a tone, a headline and a link', array_reduce($base, static fn (bool $ok, array $r): bool => $ok && in_array($r['tone'], ['bad', 'warn', 'info'], true) && $r['headline'] !== '' && str_starts_with($r['url'], '/'), true));
check('the lines are in order of urgency: the bad ones come first', (static function (array $t): bool {
    $order = ['bad' => 0, 'warn' => 1, 'info' => 2];
    $prev = 0;
    foreach (Db::rows('sp_today_suggestions', [$GLOBALS['admin']]) as $r) {
        if ($order[$r['tone']] < $prev) {
            return false;
        }
        $prev = $order[$r['tone']];
    }
    return true;
})($base));

echo "\n== Customers and leads\n";
$d = random_int(500, 4000);
$cDue = customer("Due Today $tag", [$d + 100, $d]);       // gap 100, last $d days ago: far over, so it tops the list
$cSoon = customer("Soon Today $tag", [55, 25]);           // gap 30, last 25 days ago
$t = today($admin);
check('a customer past the usual gap is one more line item', qty($t, 'REORDER_DUE') === qty($base, 'REORDER_DUE') + 1);
check('and is named first, with how far over', str_contains((string) $t['REORDER_DUE']['detail'], "Due Today $tag (" . ($d - 100) . ' days over)'), (string) ($t['REORDER_DUE']['detail'] ?? ''));
check('a customer who will reorder this week is the info line', qty($t, 'REORDER_SOON') === qty($base, 'REORDER_SOON') + 1 && $t['REORDER_SOON']['tone'] === 'info');
check('the reorder line links to the watch list', $t['REORDER_DUE']['url'] === '/reorders');
Db::callOut('sp_customer_follow_up_log', [$admin, $cust, 'call back', null, date('Y-m-d H:i:s', strtotime('-1 hour'))]);
check('a reminder that has come due is a line', qty(today($admin), 'REMINDERS') === qty($base, 'REMINDERS') + 1);
$oldest = date('Y-m-d H:i:s', mktime(0, 0, random_int(0, 80000), 1, 1, 2001));
Db::callOut('sp_customer_lead_save', [$admin, null, "Lead Today $tag", 'Ravi', '9811111111', null, 'Trade fair', 'NEW', $oldest, null]);
Db::callOut('sp_customer_lead_save', [$admin, null, "Later Lead $tag", null, null, null, null, 'NEW', date('Y-m-d H:i:s', strtotime('+5 days')), null]);
$t = today($admin);
check('a lead whose follow-up date has come is a line, named; one for next week is not counted', qty($t, 'LEADS') === qty($base, 'LEADS') + 1 && str_contains((string) $t['LEADS']['detail'], "Lead Today $tag"));

echo "\n== Orders\n";
$days = random_int(1000, 4000);
$late = order($cust, round($rate * $days), 1, 'CONFIRMED');
$lateNo = (string) one('SELECT order_number FROM sales_orders WHERE id = ?', [$late]);
$tight = order($cust, 1, 30, 'CONFIRMED');
$fin = substr((string) Db::row('sp_order_projection', [$admin, $tight])['projected_finish'], 0, 10);
Db::pdo()->prepare('UPDATE sales_orders SET delivery_date = ? WHERE id = ?')->execute([$fin . ' 00:00:00', $tight]);
$t = today($admin);
check('an order that cannot be finished in time is one more bad line, and it heads the names', qty($t, 'ORDERS_LATE') === qty($base, 'ORDERS_LATE') + 1 && $t['ORDERS_LATE']['tone'] === 'bad'
    && str_contains((string) $t['ORDERS_LATE']['detail'], $lateNo) && str_contains((string) $t['ORDERS_LATE']['detail'], 'late)'), (string) ($t['ORDERS_LATE']['detail'] ?? ''));
check('an order with no day to spare is the warn line', qty($t, 'ORDERS_RISK') === qty($base, 'ORDERS_RISK') + 1 && $t['ORDERS_RISK']['tone'] === 'warn');
check('both link to Deadlines', $t['ORDERS_LATE']['url'] === '/deadlines' && $t['ORDERS_RISK']['url'] === '/deadlines');
order($cust, 10, 20, 'PROFORMA_SENT', ['proforma_sent_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
order($cust, 10, 20, 'PROFORMA_SENT', ['proforma_sent_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
check('a proforma the client has not answered for over 3 days is a line; one from yesterday is not', qty(today($admin), 'PROFORMA') === qty($base, 'PROFORMA') + 1);
order($cust, 10, 20, 'CONFIRMED', ['confirmed_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
check('a confirmed order with no job card for a day is a line', qty(today($admin), 'NO_JOB') === qty($base, 'NO_JOB') + 1);
order($cust, 10, 20, 'READY_TO_DISPATCH');
check('goods ready to go are a line', qty(today($admin), 'DISPATCH') === qty($base, 'DISPATCH') + 1);

echo "\n== Raw material\n";
$a = material('RMA', 50, 100);          // 10 a day, 5 days left: order now
$c = material('RMC', 100, 100);         // 10 days left: order soon
$t = today($admin);
check('a material running out inside its lead time is one more "order now"', qty($t, 'MATERIAL_NOW') === qty($base, 'MATERIAL_NOW') + 1 && $t['MATERIAL_NOW']['tone'] === 'bad');
check('one that has a little longer is "order soon"', qty($t, 'MATERIAL_SOON') === qty($base, 'MATERIAL_SOON') + 1 && $t['MATERIAL_SOON']['tone'] === 'info');
check('both link to the buying advice', $t['MATERIAL_NOW']['url'] === '/buying-advice' && $t['MATERIAL_SOON']['url'] === '/buying-advice');
$sup = Db::callOut('sp_supplier_save', [$admin, null, "Today Supplier $tag", null, null, null, 'ACTIVE'])[0];
Db::callOut('sp_buying_create_po', [$admin, $sup, json_encode([['itemId' => $a, 'qty' => 160]])]);
check('once a draft purchase order exists for it, it is no longer a thing to do', qty(today($admin), 'MATERIAL_NOW') === qty($base, 'MATERIAL_NOW'));

echo "\n== Machines and quality\n";
$rm = Db::callOut('sp_item_save', [$admin, null, "TRM-$tag", "Today RM $tag", 'RAW_MATERIAL', 'KG', null, 10, 0, null])[0];
$ws1 = Db::callOut('sp_workstation_save', [$admin, null, "TW1-$tag", "Today One $tag", 0, 'IDLE'])[0];
$ws2 = Db::callOut('sp_workstation_save', [$admin, null, "TW2-$tag", "Today Two $tag", 0, 'IDLE'])[0];
Db::callOut('sp_bom_save', [$admin, null, "TBOM $tag", $fg, 100, 0, json_encode([['rmItemId' => $rm, 'qtyPerUnit' => 5]])]);
Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 1000, 'OPENING', 'today test', $ip]);
$wo1 = Db::callOut('sp_work_order_create', [$admin, null, $fg, 500, null])[0];
$wo2 = Db::callOut('sp_work_order_create', [$admin, null, $fg, 300, null])[0];
$jc1 = Db::callOut('sp_job_card_create', [$admin, $wo1, $ws1, null, null])[0];
$jc2 = Db::callOut('sp_job_card_create', [$admin, $wo2, $ws2, null, null])[0];
Db::callOut('sp_machine_stock_issue', [$admin, $ws1, $rm, 6, null, 'a small lot']);
Db::call('sp_job_card_set_status', [$admin, $jc1, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jc1, 'METERS', 100]);   // uses 5, leaves 1
check('a machine with hours of material left is not a line', qty(today($admin), 'MACHINE_LOW') === qty($base, 'MACHINE_LOW'));
Db::call('sp_production_log', [$admin, $jc1, 'METERS', 100]);   // uses the last 1
$t = today($admin);
check('a machine that has run out of the material beside it is one more bad line', qty($t, 'MACHINE_LOW') === qty($base, 'MACHINE_LOW') + 1 && $t['MACHINE_LOW']['tone'] === 'bad' && $t['MACHINE_LOW']['url'] === '/machine-stock');
Db::call('sp_job_card_set_status', [$admin, $jc2, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jc2, 'METERS', 10]);
Db::pdo()->prepare('UPDATE job_cards SET started_at = ? WHERE id IN (?, ?)')->execute([date('Y-m-d H:i:s', strtotime('-3 hours')), $jc1, $jc2]);
$t = today($admin);
check('two machines running for hours with no QC reading are two more lines', qty($t, 'QC_DUE') === qty($base, 'QC_DUE') + 2 && $t['QC_DUE']['url'] === '/quality/machines');
Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([['parameter' => 'PIN', 'min' => 4.9, 'max' => 5.1]])]);
$sheet = Db::callOut('sp_qc_sheet_open', [$admin, $jc1, null, null, null, null, null, null, null, null])[0];
check('a QC sheet opened on a machine takes it off the due list', qty(today($admin), 'QC_DUE') === qty($base, 'QC_DUE') + 1);
Db::callOut('sp_qc_sheet_add_row', [$admin, $sheet, null, json_encode([['code' => 'PIN', 'value' => '5.0']])]);
check('a reading inside the limits adds no bad-reading line', qty(today($admin), 'QC_BAD') === qty($base, 'QC_BAD'));
Db::callOut('sp_qc_sheet_add_row', [$admin, $sheet, null, json_encode([['code' => 'PIN', 'value' => '9.0']])]);
$t = today($admin);
check('a reading outside the limits is one more bad line, naming the machine, batch and size', qty($t, 'QC_BAD') === qty($base, 'QC_BAD') + 1 && $t['QC_BAD']['url'] === '/quality/sheets?status=OPEN'
    && str_contains((string) $t['QC_BAD']['detail'], "TW1-$tag") && str_contains((string) $t['QC_BAD']['detail'], '(PIN)'), (string) ($t['QC_BAD']['detail'] ?? ''));
Db::callOut('sp_qc_sheet_add_row', [$admin, $sheet, null, json_encode([['code' => 'PIN', 'value' => '5.0']])]);
Db::call('sp_qc_sheet_close', [$admin, $sheet]);
check('closing the sheet takes its bad reading off the list', qty(today($admin), 'QC_BAD') === qty($base, 'QC_BAD'));

echo "\n== Each person sees their own list\n";
$qcU = Db::callOut('sp_user_create', [$admin, "today.qc.$tag@test.local", "Quality $tag", Auth::hash('Quality-Pass-1234'), null, json_encode([$roles['QC']]), $ip])[0];
$opU = Db::callOut('sp_user_create', [$admin, "today.op.$tag@test.local", "Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0];
$salesU = Db::callOut('sp_user_create', [$admin, "today.sales.$tag@test.local", "Sales $tag", Auth::hash('Sales-Pass-12345'), null, json_encode([$roles['Sales']]), $ip])[0];
$storeU = Db::callOut('sp_user_create', [$admin, "today.store.$tag@test.local", "Store $tag", Auth::hash('Store-Pass-1234567'), null, json_encode([$roles['Store']]), $ip])[0];
$acctU = Db::callOut('sp_user_create', [$admin, "today.acct.$tag@test.local", "Accounts $tag", Auth::hash('Accounts-Pass-12345'), null, json_encode([$roles['Accounts']]), $ip])[0];

$kinds = static fn (string $u): array => array_keys(today($u));
$q = $kinds($qcU);
check('QC sees the quality lines and the machine stock (it can read stock), nothing else', count(array_diff($q, ['QC_BAD', 'QC_DUE', 'MACHINE_LOW'])) === 0);
$o = $kinds($opU);
check('an Operator sees the machines and unplanned orders, not customers, money, buying or quality', count(array_diff($o, ['MACHINE_LOW', 'NO_JOB'])) === 0);
$s = $kinds($salesU);
check('Sales sees customers, leads and orders, not buying or quality', count(array_diff($s, ['REORDER_DUE', 'REORDER_SOON', 'REMINDERS', 'LEADS', 'ORDERS_LATE', 'ORDERS_RISK', 'PROFORMA', 'MACHINE_LOW', 'NO_JOB', 'DISPATCH'])) === 0
    && in_array('REORDER_DUE', $s, true) && in_array('LEADS', $s, true) && in_array('ORDERS_LATE', $s, true));
$st = $kinds($storeU);
check('Store sees material to buy and material at the machines, not customers', in_array('MATERIAL_SOON', $st, true) && in_array('MACHINE_LOW', $st, true) && !in_array('QC_DUE', $st, true) && !in_array('REORDER_DUE', $st, true) && !in_array('LEADS', $st, true) && !in_array('QC_BAD', $st, true));
check('Accounts sees money, orders and buying lines but nothing from the shop floor or quality', count(array_intersect($kinds($acctU), ['MACHINE_LOW', 'QC_BAD', 'QC_DUE', 'NO_JOB'])) === 0 && in_array('MATERIAL_NOW', $kinds($acctU), true), implode(',', $kinds($acctU)));
check('the administrator sees every line this test made', count(array_diff(['REORDER_DUE', 'REORDER_SOON', 'REMINDERS', 'LEADS', 'ORDERS_LATE', 'ORDERS_RISK', 'PROFORMA', 'NO_JOB', 'DISPATCH', 'MATERIAL_SOON', 'MACHINE_LOW', 'QC_DUE'], array_keys(today($admin)))) === 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
