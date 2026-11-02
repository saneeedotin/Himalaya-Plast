<?php
declare(strict_types=1);

/**
 * End-to-end scenario against the stored procedures:
 *   buy raw material -> order -> confirm (hard gate) -> produce -> QC -> pack and scan -> invoice -> pay
 * plus the failure cases (oversell, negative stock, double scan, posted-invoice edits, append-only
 * ledger, permissions).
 *
 *   php tools/scenario_test.php
 *
 * Writes test rows with random codes. Refuses to run unless the database name ends in _scratch or _test.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AppError;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Push;

$dbName = (string) Config::get('db.name');
if (!preg_match('/_(scratch|test)$/', $dbName)) {
    fwrite(STDERR, "Refusing to run against '$dbName'. Use a database ending in _scratch or _test.\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function report(bool $good, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $good ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

function check(string $label, bool $cond, string $detail = ''): void
{
    report($cond, $label, $cond ? '' : $detail);
}

/** Runs $fn; returns its value, or null after reporting a FAIL when it throws. */
function step(string $label, callable $fn): mixed
{
    try {
        $v = $fn();
        report(true, $label);
        return $v;
    } catch (Throwable $e) {
        report(false, $label, $e->getMessage());
        return null;
    }
}

/** Expects $fn to fail with a message containing $needle. */
function refuses(string $label, callable $fn, string $needle): void
{
    try {
        $fn();
        report(false, $label, 'expected an error, call succeeded');
    } catch (Throwable $e) {
        $hit = stripos($e->getMessage(), $needle) !== false;
        report($hit, $label, $hit ? '' : 'got: ' . $e->getMessage());
    }
}

function one(string $sql, array $args = []): mixed
{
    $st = Db::pdo()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

function num(mixed $v): float
{
    return round((float) $v, 3);
}

function stock(string $itemId): float
{
    return num(one('SELECT COALESCE(physical_stock, 0) FROM v_stock_balance WHERE item_id = ?', [$itemId]) ?: 0);
}

$tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$ip = '127.0.0.1';

echo "== Setup\n";
$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
$admin = one('SELECT id FROM users WHERE email = ?', [$devAdmin !== '' ? $devAdmin : 'admin@himalayaplast.local']);
check('seeded admin exists', is_string($admin) && $admin !== '');
if (!$admin) {
    exit(1);
}

$supplier = step('create supplier', fn () => Db::callOut('sp_supplier_save', [$admin, null, "Test Supplier $tag", null, null, null, 'ACTIVE'])[0]);
$gstin = '24' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 13));
$customer = step('create customer (GSTIN state 24)', fn () => Db::callOut('sp_customer_save', [$admin, null, "Test Customer $tag", $gstin, null, null])[0]);
$rm = step('create raw material', fn () => Db::callOut('sp_item_save', [$admin, null, "RM-$tag", "Compound $tag", 'RAW_MATERIAL', 'KG', null, 100, 0, null])[0]);
$fg = step('create finished good', fn () => Db::callOut('sp_item_save', [$admin, null, "FG-$tag", "Dripper $tag", 'FINISHED_GOODS', 'M', null, 30, 0, null])[0]);
$ws = step('create workstation', fn () => Db::callOut('sp_workstation_save', [$admin, null, "WS-$tag", "Line $tag", 500, 'IDLE'])[0]);
$bom = step('create BOM (5 kg per 100 m, 2.5% scrap)', fn () => Db::callOut('sp_bom_save', [
    $admin, null, "BOM $tag", $fg, 100, 0.025, json_encode([['rmItemId' => $rm, 'qtyPerUnit' => 5]]),
])[0]);
if (!$supplier || !$customer || !$rm || !$fg || !$ws || !$bom) {
    echo "Setup failed, stopping.\n";
    exit(1);
}

echo "\n== Buy 30 kg raw material\n";
$po = step('create PO', fn () => Db::callOut('sp_po_create', [$admin, $supplier, 'scenario', json_encode([['itemId' => $rm, 'qty' => 30, 'rate' => 100]])])[0]);
step('receive PO', fn () => Db::call('sp_po_receive', [$admin, $po]));
check('raw material stock is 30', stock($rm) === 30.0, 'got ' . stock($rm));

echo "\n== Orders and the hard gate\n";
$due = date('Y-m-d H:i:s', strtotime('+7 days'));
// 500 m needs 5 * (500/100) / (1 - 0.025) = 25.641 kg
$orderA = step('create order A (500 m @ 40)', fn () => Db::callOut('sp_sales_order_create', [
    $admin, $customer, null, $gstin, $due, 'WHATSAPP', null, json_encode([['itemId' => $fg, 'qty' => 500, 'rate' => 40]]),
])[0]);
$orderB = step('create order B (500 m @ 40)', fn () => Db::callOut('sp_sales_order_create', [
    $admin, $customer, null, $gstin, $due, 'WHATSAPP', null, json_encode([['itemId' => $fg, 'qty' => 500, 'rate' => 40]]),
])[0]);

$check = Db::rows('sp_check_material_availability', [$admin, $orderA]);
check('material check says A is covered', ($check[0]['status'] ?? '') !== 'DEFICIT', json_encode($check[0] ?? null));

$conf = step('confirm order A', fn () => Db::row('sp_sales_order_confirm', [$admin, $orderA, 'WHATSAPP', null]));
check('order A is CONFIRMED', ($conf['status'] ?? '') === 'CONFIRMED', (string) ($conf['status'] ?? 'no row'));
$wo = one('SELECT id FROM work_orders WHERE sales_order_id = ?', [$orderA]);
check('confirm created one work order', is_string($wo) && $wo !== '');
check('confirm reserved 25.64 kg', abs(num(one("SELECT SUM(quantity) FROM stock_reservations WHERE sales_order_id = ? AND status = 'RESERVED'", [$orderA])) - 25.641) < 0.01);

refuses('OVERSELL: confirming order B is refused', fn () => Db::call('sp_sales_order_confirm', [$admin, $orderB, 'WHATSAPP', null]), 'Strict Hard Gate');
check('order B stayed DRAFT', one('SELECT status FROM sales_orders WHERE id = ?', [$orderB]) === 'DRAFT');
$shortPo = step('shortage PO for order B', fn () => Db::callOut('sp_po_create_for_shortage', [$admin, $orderB])[0]);
check('shortage PO is DRAFT', $shortPo && one('SELECT status FROM purchase_orders WHERE id = ?', [$shortPo]) === 'DRAFT');

echo "\n== Production\n";
$jc = step('create job card', fn () => Db::callOut('sp_job_card_create', [$admin, $wo, $ws, null, null])[0]);
step('activate job card', fn () => Db::call('sp_job_card_set_status', [$admin, $jc, 'ACTIVE']));
step('log 200 m', fn () => Db::call('sp_production_log', [$admin, $jc, 'METERS', 200]));
step('log 300 m', fn () => Db::call('sp_production_log', [$admin, $jc, 'METERS', 300]));
check('finished goods stock is 500 m', stock($fg) === 500.0, 'got ' . stock($fg));
check('raw material left is about 4.36 kg', abs(stock($rm) - 4.36) < 0.02, 'got ' . stock($rm));
check('order A is IN_PRODUCTION', one('SELECT status FROM sales_orders WHERE id = ?', [$orderA]) === 'IN_PRODUCTION');
check('work order produced 500 m', num(one('SELECT produced_qty FROM work_orders WHERE id = ?', [$wo])) === 500.0);
refuses('NEGATIVE STOCK: 1000 m more is refused', fn () => Db::call('sp_production_log', [$admin, $jc, 'METERS', 1000]), 'Negative Stock');
check('refused log changed nothing', stock($rm) > 4.3 && stock($fg) === 500.0);
step('log 3 kg of scrap', fn () => Db::call('sp_production_log', [$admin, $jc, 'SCRAP', 3]));
check('scrap in kg does not take finished-goods metres out of stock', stock($fg) === 500.0, 'got ' . stock($fg));
check('the scrap is kept on the job card', num(one('SELECT scrap_qty FROM job_cards WHERE id = ?', [$jc])) === 3.0);

echo "== Stock adjustment\n";
$rmBefore = stock($rm);
$adj = step('adjust IN: 40 kg opening stock', fn () => Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 40, 'OPENING', 'opening count', $ip]));
check('adjusting IN adds the stock in a BATCH-ADJ lot', is_array($adj) && str_starts_with((string) $adj[0], 'BATCH-ADJ-') && abs(stock($rm) - ($rmBefore + 40)) < 0.001, 'got ' . stock($rm));
step('adjust OUT: 2 kg damaged', fn () => Db::callOut('sp_stock_adjust', [$admin, $rm, 'OUT', 2, 'DAMAGE', 'wet bag', $ip]));
check('adjusting OUT takes the stock out', abs(stock($rm) - ($rmBefore + 38)) < 0.001, 'got ' . stock($rm));
check('the ledger has one ADJUSTMENT_IN and one ADJUSTMENT_OUT', (int) one("SELECT COUNT(*) FROM stock_ledger_entries WHERE item_id = ? AND movement_type IN ('ADJUSTMENT_IN','ADJUSTMENT_OUT')", [$rm]) === 2);
refuses('cannot remove more than the free stock', fn () => Db::callOut('sp_stock_adjust', [$admin, $rm, 'OUT', 100000, 'COUNT', null, $ip]), 'free to remove');
refuses('a quantity of zero is refused', fn () => Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 0, 'OPENING', null, $ip]), 'above zero');
refuses('reason Other needs a note', fn () => Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 1, 'OTHER', '', $ip]), 'Describe the reason');
refuses('an unknown reason is refused', fn () => Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 1, 'WHIM', null, $ip]), 'reason');
refuses('an unknown item is refused', fn () => Db::callOut('sp_stock_adjust', [$admin, '00000000-0000-0000-0000-000000000000', 'IN', 1, 'OPENING', null, $ip]), 'Item not found');

echo "\n== Quality\n";
$fgBatch = (string) one('SELECT fg_batch_number FROM work_orders WHERE id = ?', [$wo]);
check('production named an FG batch on the work order', $fgBatch !== '', 'empty');
check('FG batch holds 500 m', num(one('SELECT quantity FROM batches WHERE batch_number = ?', [$fgBatch])) === 500.0);
step('QC pass', fn () => Db::callOut('sp_qc_quick_submit', [$admin, $orderA, 'PASS', json_encode(['pinSize' => 4.02])])[0]);
check('QC report stored for the batch', (int) one('SELECT COUNT(*) FROM quality_inspections WHERE batch_number = ? AND status = ?', [$fgBatch, 'PASS']) === 1);

echo "\n== Pack and dispatch\n";
$c1 = step('serialise carton 1 (250 m)', fn () => Db::row('sp_carton_quick_serialize', [$admin, $orderA, 250, null]));
$c2 = step('serialise carton 2 (250 m)', fn () => Db::row('sp_carton_quick_serialize', [$admin, $orderA, 250, null]));
if ($c1 && $c2) {
    $s1 = step('scan carton 1', fn () => Db::row('sp_dispatch_scan', [$admin, $c1['carton_code'], 0, null]));
    check('not complete after first scan', (int) ($s1['is_complete'] ?? 1) === 0);
    refuses('DOUBLE SCAN is refused', fn () => Db::call('sp_dispatch_scan', [$admin, $c1['carton_code'], 0, null]), 'ALREADY_SCANNED');
    check('stock fell by one carton only', stock($fg) === 250.0, 'got ' . stock($fg));
    $s2 = step('scan carton 2', fn () => Db::row('sp_dispatch_scan', [$admin, $c2['carton_code'], 0, null]));
    check('complete after last scan', (int) ($s2['is_complete'] ?? 0) === 1);
    check('order A is DISPATCHED', one('SELECT status FROM sales_orders WHERE id = ?', [$orderA]) === 'DISPATCHED');
    check('finished goods stock is 0', stock($fg) === 0.0, 'got ' . stock($fg));
}

echo "\n== Invoice and payment\n";
$inv = step('create invoice from order A', fn () => Db::callOut('sp_sales_invoice_create', [$admin, null, $orderA, null, null, null])[0]);
if ($inv) {
    $row = Db::pdo()->query("SELECT * FROM sales_invoices WHERE id = '$inv'")->fetch();
    check('subtotal 20000.00', num($row['subtotal_amount']) === 20000.0, (string) $row['subtotal_amount']);
    check('tax 3600.00 split CGST/SGST 1800 each', num($row['cgst_amount']) === 1800.0 && num($row['sgst_amount']) === 1800.0 && num($row['igst_amount']) === 0.0);
    check('total 23600.00', num($row['total_amount']) === 23600.0);
    refuses('payment before submit is refused', function () use ($admin, $customer, $inv) {
        $p = Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $customer, 'BANK_TRANSFER', 'X1', 1000, $inv, null, null])[0];
        Db::call('sp_payment_submit', [$admin, $p]);
    }, 'not open for payment');
    step('submit invoice', fn () => Db::call('sp_sales_invoice_submit', [$admin, $inv, null, null]));
    refuses('posted invoice lines are locked', fn () => Db::call('sp_sales_invoice_set_items', [$admin, $inv, '[]']), 'DRAFT');
    $p1 = step('part payment 10000', fn () => Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $customer, 'BANK_TRANSFER', 'UTR1', 10000, $inv, null, null])[0]);
    step('submit part payment', fn () => Db::call('sp_payment_submit', [$admin, $p1]));
    $row = Db::pdo()->query("SELECT status, outstanding FROM sales_invoices WHERE id = '$inv'")->fetch();
    check('invoice PARTIAL, outstanding 13600', $row['status'] === 'PARTIAL' && num($row['outstanding']) === 13600.0, json_encode($row));
    refuses('OVERPAYMENT is refused', function () use ($admin, $customer, $inv) {
        $p = Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $customer, 'CASH', null, 20000, $inv, null, null])[0];
        Db::call('sp_payment_submit', [$admin, $p]);
    }, 'exceeds');
    $p2 = step('final payment 13600', fn () => Db::callOut('sp_payment_create', [$admin, 'CUSTOMER', $customer, 'CASH', null, 13600, $inv, null, null])[0]);
    step('submit final payment', fn () => Db::call('sp_payment_submit', [$admin, $p2]));
    check('invoice PAID, outstanding 0', Db::pdo()->query("SELECT status FROM sales_invoices WHERE id = '$inv'")->fetchColumn() === 'PAID');
}

echo "\n== Integrity\n";
refuses('stock ledger rejects UPDATE', fn () => Db::pdo()->exec('UPDATE stock_ledger_entries SET quantity = 1 LIMIT 1'), 'append-only');
refuses('stock ledger rejects DELETE', fn () => Db::pdo()->exec('DELETE FROM stock_ledger_entries LIMIT 1'), 'append-only');
refuses('audit log rejects DELETE', fn () => Db::pdo()->exec('DELETE FROM audit_logs LIMIT 1'), 'append-only');

echo "\n== Permissions\n";
$roles = array_column(Db::rows('sp_role_list', [$admin]), 'id', 'name');
$op = step('create an Operator user', fn () => Db::callOut('sp_user_create', [
    $admin, "operator.$tag@test.local", "Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip,
])[0]);
if ($op) {
    refuses('Operator cannot confirm an order', fn () => Db::call('sp_sales_order_confirm', [$op, $orderB, 'WHATSAPP', null]), 'Forbidden');
    refuses('Operator cannot create a customer', fn () => Db::callOut('sp_customer_save', [$op, null, 'X', null, null, null]), 'Forbidden');
    refuses('Operator cannot list users', fn () => Db::call('sp_user_list', [$op]), 'Forbidden');
    refuses('Operator cannot adjust stock', fn () => Db::callOut('sp_stock_adjust', [$op, $rm, 'IN', 1, 'OPENING', null, $ip]), 'Forbidden');
    check('Operator can read work orders', Auth::class !== '' && (int) one('SELECT fn_can(?, ?, ?)', [$op, 'WorkOrders', 'read']) === 1);
    step('deactivate the Operator', fn () => Db::call('sp_user_update', [$admin, $op, 0, null, $ip]));
    refuses('inactive user is refused', fn () => Db::call('sp_sales_order_confirm', [$op, $orderB, 'WHATSAPP', null]), 'Forbidden');
}
refuses('unknown actor is refused', fn () => Db::call('sp_user_list', [null]), 'Forbidden');

echo "\n== Notifications\n";
putenv('HPOS_PUSH_DRY_RUN=1');   // the sender pretends the push service answered 201; nothing leaves this machine
$salesU = step('create a Sales user', fn () => Db::callOut('sp_user_create', [$admin, "sales.$tag@test.local", "Sales $tag", Auth::hash('Sales-Pass-12345'), null, json_encode([$roles['Sales']]), $ip])[0]);
$acctU = step('create an Accounts user', fn () => Db::callOut('sp_user_create', [$admin, "acct.$tag@test.local", "Accounts $tag", Auth::hash('Accounts-Pass-123'), null, json_encode([$roles['Accounts']]), $ip])[0]);
$opU = step('create a second Operator', fn () => Db::callOut('sp_user_create', [$admin, "op2.$tag@test.local", "Operator two $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0]);
$titles = static fn (string $uid): array => array_column(Db::rows('sp_notification_list', [$uid, 200]), 'status', 'title');
$unread = static fn (string $uid): int => (int) Db::row('sp_notification_unread_count', [$uid])['unread'];
$noteFor = static fn (string $src, string $like): ?array => Db::pdo()->query("SELECT * FROM notifications WHERE source_id = " . Db::pdo()->quote($src) . " AND title LIKE " . Db::pdo()->quote($like) . " ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;

if ($salesU && $acctU && $opU) {
    $confirmed = $noteFor($orderA, '% confirmed');
    check('confirming an order raised "Order ... confirmed"', $confirmed !== null && str_contains($confirmed['title'], 'confirmed'));
    $paid = $noteFor($p1, 'Payment received%');
    check('a posted customer payment raised "Payment received"', $paid !== null && $paid['audience'] === 'Accounts');
    $dispatched = $noteFor($orderA, '% dispatched');
    check('dispatching the last carton raised "Order ... dispatched"', $dispatched !== null);

    check('audience: Accounts sees the payment', isset($titles($acctU)[$paid['title']]));
    check('audience: Sales does not see the payment (no Accounts right)', !isset($titles($salesU)[$paid['title']]));
    check('audience: an Operator does not see the payment', !isset($titles($opU)[$paid['title']]));
    check('audience: Sales sees the confirmed order', isset($titles($salesU)[$confirmed['title']]));
    check('audience: the administrator sees everything', isset($titles($admin)[$paid['title']]) && isset($titles($admin)[$confirmed['title']]));
    check('the unread count only counts what a person may see', $unread($acctU) >= 1 && $unread($salesU) >= 1 && $unread($opU) !== $unread($admin));

    $before = [$unread($salesU), $unread($acctU)];
    step('Sales reads the confirmed order', fn () => Db::call('sp_notification_set_status', [$salesU, $confirmed['id'], 'READ']));
    check('reading is per person: Sales has one fewer unread, Accounts is unchanged', $unread($salesU) === $before[0] - 1 && $unread($acctU) === $before[1]);
    check('the list shows READ for Sales and UNREAD for the administrator', ($titles($salesU)[$confirmed['title']] ?? '') === 'READ' && ($titles($admin)[$confirmed['title']] ?? '') === 'UNREAD');
    step('Sales marks it unread again', fn () => Db::call('sp_notification_set_status', [$salesU, $confirmed['id'], 'UNREAD']));
    check('marking unread puts it back', $unread($salesU) === $before[0]);
    refuses('a person cannot touch a notification they may not see', fn () => Db::call('sp_notification_set_status', [$salesU, $paid['id'], 'READ']), 'not found');
    step('Accounts marks everything read', fn () => Db::call('sp_notification_set_status', [$acctU, null, 'READ']));
    check('mark all read zeroes only that person\'s count', $unread($acctU) === 0 && $unread($admin) > 0);

    $poll = Db::call('sp_notification_poll', [$admin, null]);
    check('poll with no start point returns the count and the time, and no pop-up backlog', (int) $poll[0][0]['unread'] === $unread($admin) && ($poll[1] ?? []) === []);
    $poll = Db::call('sp_notification_poll', [$admin, '2000-01-01 00:00:00.000']);
    check('poll from an old time returns the newest unread (at most 10)', count($poll[1] ?? []) > 0 && count($poll[1]) <= 10);
    $poll = Db::call('sp_notification_poll', [$acctU, '2000-01-01 00:00:00.000']);
    check('poll skips what the person already read', array_filter($poll[1] ?? [], static fn (array $r): bool => $r['id'] === $paid['id']) === []);

    // stock goes low the moment it drops, with no cron
    $low = step('create a raw material with a minimum of 50', fn () => Db::callOut('sp_item_save', [$admin, null, "RML-$tag", "Low test $tag", 'RAW_MATERIAL', 'KG', null, 10, 50, null])[0]);
    Db::callOut('sp_stock_adjust', [$admin, $low, 'IN', 60, 'OPENING', null, $ip]);
    check('stock above the minimum raises no alert', $noteFor($low, '% stock low') === null);
    Db::callOut('sp_stock_adjust', [$admin, $low, 'OUT', 20, 'DAMAGE', null, $ip]);
    $lowNote = $noteFor($low, '% stock low');
    check('stock dropping below the minimum raises "stock low" at once (no cron)', $lowNote !== null && $lowNote['status'] === 'UNREAD' && $lowNote['priority'] === 'CRITICAL');
    check('the low-stock alert goes to Stock and Purchasing people, not to an Operator', $lowNote !== null && $lowNote['audience'] === 'Stock,PurchaseOrders' && isset($titles($salesU)[$lowNote['title']]) && !isset($titles($opU)[$lowNote['title']]));
    Db::callOut('sp_stock_adjust', [$admin, $low, 'OUT', 5, 'DAMAGE', null, $ip]);
    check('a second drop does not raise a second alert', (int) one("SELECT COUNT(*) FROM notifications WHERE source_id = ? AND title LIKE '% stock low' AND status <> 'RESOLVED'", [$low]) === 1);
    Db::callOut('sp_stock_adjust', [$admin, $low, 'IN', 30, 'COUNT', null, $ip]);
    check('topping the stock back up clears the alert', $noteFor($low, '% stock low')['status'] === 'RESOLVED');

    // a machine stop and a QC reject
    $stop = step('report a machine stop', fn () => Db::callOut('sp_downtime_start', [$admin, $ws, null, 'MECHANICAL', 'belt snapped'])[0]);
    $stopNote = $stop ? $noteFor($stop, '% stopped') : null;
    check('a machine stop raises "Machine ... stopped" for Production', $stopNote !== null && $stopNote['audience'] === 'Production' && str_contains($stopNote['message'], 'mechanical breakdown'));
    check('Accounts is not told about machine stops', $stopNote !== null && !isset($titles($acctU)[$stopNote['title']]));
    if ($stop) {
        Db::call('sp_downtime_end', [$admin, $stop]);
        check('ending the stop closes the stop alert and says the machine is back', $noteFor($stop, '% stopped')['status'] === 'RESOLVED' && $noteFor($stop, '% back in service') !== null);
    }
    $rej = step('record a rejected QC inspection', fn () => Db::callOut('sp_qc_quick_submit', [$admin, $orderA, 'REJECT', json_encode(['pinSize' => 9.9])])[0]);
    $rejNote = $rej ? $noteFor($rej, 'Batch % rejected') : null;
    check('a QC reject raises a CRITICAL "Batch ... rejected" for QC and Production', $rejNote !== null && $rejNote['priority'] === 'CRITICAL' && $rejNote['audience'] === 'QC,Production');
    $cancelOrder = step('cancel order B', fn () => Db::row('sp_sales_order_set_status', [$admin, $orderB, 'CANCELLED', null, null]));
    check('cancelling an order raises "Order ... cancelled"', $noteFor($orderB, '% cancelled') !== null);

    // standing alerts get the right audience, and the app can run the check itself
    Db::call('sp_generate_alerts');
    $qcWait = Db::pdo()->query("SELECT audience FROM notifications WHERE title LIKE '% QC inspections pending' AND status <> 'RESOLVED' LIMIT 1")->fetchColumn();
    check('the periodic check still raises standing alerts, with an audience', $qcWait === false || $qcWait === 'QC');
    Db::pdo()->exec("DELETE FROM job_runs WHERE job_name = 'alerts'");
    $r1 = Db::row('sp_alerts_run_if_due', [10]);
    $r2 = Db::row('sp_alerts_run_if_due', [10]);
    check('the alert check runs by itself when due, and not twice in a row', (int) $r1['ran'] === 1 && (int) $r2['ran'] === 0);

    // Web Push: who would be pushed to, without sending anything
    echo "\n== Web Push\n";
    $fcm = static fn (string $n): string => "https://fcm.googleapis.com/fcm/send/test-$n-$tag";
    $keys = ['p' => Push::generateKeyPair()['public'], 'a' => Push::b64u(random_bytes(16))];   // a real P-256 point, as a browser would send
    foreach (['acct' => $acctU, 'sales' => $salesU, 'op' => $opU] as $k => $u) {
        step("subscribe a browser for $k", fn () => Db::call('sp_push_subscribe', [$u, $fcm($k), $keys['p'], $keys['a'], 'Test browser']));
    }
    refuses('a non-https subscription is refused', fn () => Db::call('sp_push_subscribe', [$acctU, 'http://fcm.googleapis.com/x', $keys['p'], $keys['a'], null]), 'valid push subscription');
    refuses('a subscription without keys is refused', fn () => Db::call('sp_push_subscribe', [$acctU, $fcm('nokeys'), '', '', null]), 'valid push subscription');
    check('only known push services are allowed as endpoints', Push::isAllowedEndpoint('https://fcm.googleapis.com/fcm/send/x') && Push::isAllowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/x') && Push::isAllowedEndpoint('https://wns2-par02p.notify.windows.com/x')
        && !Push::isAllowedEndpoint('https://internal.example.test/x') && !Push::isAllowedEndpoint('http://fcm.googleapis.com/x') && !Push::isAllowedEndpoint('https://127.0.0.1/x') && !Push::isAllowedEndpoint('https://fcm.googleapis.com.evil.test/x'));
    $st = Db::row('sp_push_status', [$acctU, $fcm('acct')]);
    check('status: one browser registered, and it is this one', (int) $st['browsers'] === 1 && (int) $st['this_browser'] === 1);
    step('subscribing the same browser again does not duplicate it', fn () => Db::call('sp_push_subscribe', [$acctU, $fcm('acct'), $keys['p'], $keys['a'], 'Test browser 2']));
    check('the same endpoint stays one row', (int) one('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$acctU]) === 1);

    $payN = Db::pdo()->query("SELECT id FROM notifications WHERE audience = 'Accounts' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    Db::call('sp_notification_set_status', [$acctU, $payN, 'UNREAD']);   // Accounts read everything above; make this one unread again
    $who = array_column(Db::rows('sp_push_targets', [$payN]), 'endpoint');
    check('push targets: an Accounts-only alert goes to the Accounts browser, not Sales or the Operator', in_array($fcm('acct'), $who, true) && !in_array($fcm('sales'), $who, true) && !in_array($fcm('op'), $who, true));
    Db::call('sp_notification_set_status', [$acctU, $payN, 'READ']);
    check('push targets: someone who already read it is not pushed to', !in_array($fcm('acct'), array_column(Db::rows('sp_push_targets', [$payN]), 'endpoint'), true));
    check('pending: unsent notifications are listed', (int) count(Db::rows('sp_push_pending', [200])) > 0);
    $res = Push::dispatchPending(500);
    check('dispatch (dry run): pushes go out and everything is marked sent', $res['sent'] > 0 && $res['failed'] === 0 && count(Db::rows('sp_push_pending', [200])) === 0, json_encode($res));
    check('dispatch marks a subscription as working', (int) one('SELECT COUNT(*) FROM push_subscriptions WHERE last_ok_at IS NOT NULL') >= 1);
    step('unsubscribe the Operator browser', fn () => Db::call('sp_push_unsubscribe', [$opU, $fcm('op')]));
    check('unsubscribing removes only that browser', (int) one('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$opU]) === 0 && (int) one('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$salesU]) === 1);
    step('deactivate Sales', fn () => Db::call('sp_user_update', [$admin, $salesU, 0, null, $ip]));
    $fresh = $noteFor($orderB, '% cancelled');
    Db::pdo()->exec('UPDATE notifications SET push_state = 0 WHERE id = ' . Db::pdo()->quote($fresh['id']));
    check('a deactivated person is never a push target', !in_array($fcm('sales'), array_column(Db::rows('sp_push_targets', [$fresh['id']]), 'endpoint'), true));
    Db::pdo()->exec('DELETE FROM push_subscriptions');
    Push::dispatchPending(500);

    // an encrypted message is something a browser can read
    $uaPair = Push::generateKeyPair();
    $authKey = Push::b64u(random_bytes(16));
    $wire = Push::encrypt(Push::payloadFor(['id' => 'x', 'title' => 'T', 'message' => 'M', 'priority' => 'UPDATE', 'action_url' => '/orders/1']), $uaPair['public'], $authKey);
    $back = json_decode(Push::decrypt($wire, openssl_pkey_get_private($uaPair['private_pem']), $authKey), true);
    check('the payload a browser reads has title, body and a link into the app', ($back['title'] ?? '') === 'T' && ($back['body'] ?? '') === 'M' && str_contains($back['url'] ?? '', '/orders/1'));
}
putenv('HPOS_PUSH_DRY_RUN');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
