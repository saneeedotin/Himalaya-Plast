<?php
declare(strict_types=1);

/**
 * Buying advice against the stored procedures: usage per day, days of stock left, what is already on order, order
 * now / order soon / OK, the suggested quantity, a draft purchase order only when asked (and never twice for a line),
 * and the alert.
 *
 *   php tools/buying_test.php
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

$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
$admin = (string) (Db::row('sp_auth_get_user_by_email', [$devAdmin !== '' ? $devAdmin : 'admin@himalayaplast.local'])['id'] ?? '');
check('the administrator exists', $admin !== '');
if ($admin === '') {
    exit(1);
}
$tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$ip = '127.0.0.1';
$roles = array_column(Db::rows('sp_role_list', [$admin]), 'id', 'name');
$sup = Db::callOut('sp_supplier_save', [$admin, null, "Advice Supplier $tag", null, null, null, 'ACTIVE'])[0];
$sup2 = Db::callOut('sp_supplier_save', [$admin, null, "Other Supplier $tag", null, null, null, 'ACTIVE'])[0];

/** A raw material with `stock` kg in hand after having used `used` kg nine days ago (so it uses used/10 per day) */
function material(string $code, float $stock, float $used, float $min = 0, float $cost = 25): string
{
    global $admin, $ip, $tag;
    $id = Db::callOut('sp_item_save', [$admin, null, "$code-$tag", "Material $code $tag", 'RAW_MATERIAL', 'KG', null, $cost, $min, null])[0];
    if ($stock + $used > 0) {
        Db::callOut('sp_stock_adjust', [$admin, $id, 'IN', $stock + $used, 'OPENING', 'advice test', $ip]);
    }
    if ($used > 0) {
        Db::pdo()->prepare("INSERT INTO stock_ledger_entries (item_id, warehouse, quantity, movement_type, reference_id, notes, created_at) VALUES (?, 'MAIN', ?, 'CONSUMPTION', 'test', 'advice test', ?)")
            ->execute([$id, $used, date('Y-m-d H:i:s', strtotime('-9 days'))]);
    }
    return $id;
}
function advice(string $id): array
{
    global $admin;
    return array_column(Db::call('sp_buying_advice', [$admin])[0], null, 'item_id')[$id] ?? [];
}

echo "== Worked out from real usage\n";
$a = material('RMA', 50, 100);          // uses 10/day, 50 left: 5 days
$b = material('RMB', 1000, 100);        // 100 days
$c = material('RMC', 100, 100);         // 10 days
$d = material('RMD', 100, 0, 500);      // no usage, below its minimum
$e = material('RME', 0, 0);             // never used
$rA = advice($a);
check('usage is 10 a day (100 kg over the 10 days since it was first used)', (float) $rA['daily_use'] === 10.0);
check('days left is stock over usage: 50 / 10 = 5', (float) $rA['days_left'] === 5.0);
check('5 days left with the default 7-day lead time is Order now', $rA['status'] === 'ORDER_NOW');
check('suggested = 10/day x (7 lead + 14 cover) - 50 in hand = 160', (float) $rA['suggested_qty'] === 160.0, (string) $rA['suggested_qty']);
check('100 days left is OK and suggests nothing', advice($b)['status'] === 'OK' && (float) advice($b)['suggested_qty'] === 0.0);
check('10 days left (7-day lead + 7) is Order soon', advice($c)['status'] === 'ORDER_SOON');
$rD = advice($d);
check('below the minimum with no usage is Order now, suggesting the gap to the minimum', $rD['status'] === 'ORDER_NOW' && (float) $rD['suggested_qty'] === 400.0, json_encode([$rD['status'], $rD['suggested_qty']]));
check('a material never used and with no minimum is OK', advice($e)['status'] === 'OK');
check('the estimated rate is the standard cost', (float) $rA['est_rate'] === 25.0);

echo "\n== Lead time, lot size and supplier\n";
refuses('a lead time over a year is refused', fn () => Db::call('sp_item_buying_save', [$admin, $a, 400, null, null]), '0 to 365');
refuses('an unknown supplier is refused', fn () => Db::call('sp_item_buying_save', [$admin, $a, 7, null, '00000000-0000-0000-0000-000000000000']), 'Supplier not found');
step('a 3-day lead time, a 500 kg smallest order and a usual supplier are saved', fn () => Db::call('sp_item_buying_save', [$admin, $a, 3, 500, $sup]));
$rA = advice($a);
check('5 days left against a 3-day lead is now only Order soon', $rA['status'] === 'ORDER_SOON');
check('the suggestion is at least the smallest order (500)', (float) $rA['suggested_qty'] === 500.0 && $rA['supplier_name'] === "Advice Supplier $tag");
Db::call('sp_item_buying_save', [$admin, $a, null, null, $sup]);
check('clearing the lead time goes back to the assumed 7 days', advice($a)['status'] === 'ORDER_NOW');

echo "\n== What is already on order\n";
$po = Db::callOut('sp_po_create', [$admin, $sup, 'advice test', json_encode([['itemId' => $a, 'qty' => 200, 'rate' => 25]])])[0];
check('a draft order does not count as incoming', (float) advice($a)['incoming_qty'] === 0.0 && advice($a)['draft_po'] !== null);
Db::pdo()->prepare("UPDATE purchase_orders SET status = 'SUBMITTED' WHERE id = ?")->execute([$po]);
$rA = advice($a);
check('a submitted order counts as incoming (200)', (float) $rA['incoming_qty'] === 200.0);
check('50 in hand + 200 coming is 25 days: back to OK', $rA['status'] === 'OK');
$inc = array_column(Db::rows('sp_incoming_by_item', [$admin]), null, 'item_id');
check('the order page can see it: 200 on that purchase order', (float) ($inc[$a]['incoming_qty'] ?? 0) === 200.0 && !empty($inc[$a]['po_number']));
Db::pdo()->prepare("UPDATE purchase_orders SET status = 'RECEIVED' WHERE id = ?")->execute([$po]);
check('a received order no longer counts', (float) advice($a)['incoming_qty'] === 0.0);

echo "\n== Nothing is created until a person confirms\n";
$before = (int) one('SELECT COUNT(*) FROM purchase_orders');
Db::call('sp_buying_advice', [$admin]);
check('looking at the advice created no purchase order', (int) one('SELECT COUNT(*) FROM purchase_orders') === $before);
refuses('nothing ticked is refused', fn () => Db::callOut('sp_buying_create_po', [$admin, $sup, json_encode([])]), 'Tick at least one');
refuses('no supplier is refused', fn () => Db::callOut('sp_buying_create_po', [$admin, null, json_encode([['itemId' => $a, 'qty' => 160]])]), 'active supplier');
refuses('a zero quantity is refused', fn () => Db::callOut('sp_buying_create_po', [$admin, $sup, json_encode([['itemId' => $a, 'qty' => 0]])]), 'above zero');
$fg = Db::callOut('sp_item_save', [$admin, null, "AFG-$tag", "Advice FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
refuses('a finished-goods item is refused', fn () => Db::callOut('sp_buying_create_po', [$admin, $sup, json_encode([['itemId' => $fg, 'qty' => 5]])]), 'raw material or packaging');
$newPo = step('a draft purchase order is created from the ticked lines', fn () => Db::callOut('sp_buying_create_po', [$admin, $sup2, json_encode([['itemId' => $a, 'qty' => 160], ['itemId' => $c, 'qty' => 80]])])[0]);
$lines = Db::pdo()->query("SELECT item_id, qty, rate FROM purchase_order_items WHERE purchase_order_id = '$newPo'")->fetchAll(PDO::FETCH_ASSOC);
check('it is a draft for the chosen supplier with both lines at the standard cost', one('SELECT status FROM purchase_orders WHERE id = ?', [$newPo]) === 'DRAFT'
    && one('SELECT supplier_id FROM purchase_orders WHERE id = ?', [$newPo]) === $sup2 && count($lines) === 2 && (float) $lines[0]['rate'] === 25.0);
refuses('a line already on a draft order is refused', fn () => Db::callOut('sp_buying_create_po', [$admin, $sup, json_encode([['itemId' => $a, 'qty' => 10]])]), 'already on a draft');
check('the advice now says which draft order it is on', !empty(advice($a)['draft_po']));

echo "\n== Alert\n";
Db::call('sp_generate_alerts', []);
$n = Db::pdo()->query("SELECT title, audience FROM notifications WHERE source_type = 'MATERIAL' AND source_id = 'advice' AND status <> 'RESOLVED'")->fetch(PDO::FETCH_ASSOC);
check('one alert counts the materials to reorder', is_array($n) && preg_match('/^(\d+) materials to reorder$/', (string) $n['title'], $m) === 1 && (int) $m[1] >= 2 && $n['audience'] === 'Stock,PurchaseOrders', json_encode($n));

echo "\n== Who may do what\n";
$qcU = Db::callOut('sp_user_create', [$admin, "buy.qc.$tag@test.local", "Buying QC $tag", Auth::hash('Quality-Pass-1234'), null, json_encode([$roles['QC']]), $ip])[0];
$storeU = Db::callOut('sp_user_create', [$admin, "buy.store.$tag@test.local", "Buying Store $tag", Auth::hash('Store-Pass-1234567'), null, json_encode([$roles['Store']]), $ip])[0];
refuses('QC cannot read the advice', fn () => Db::call('sp_buying_advice', [$qcU]), 'Forbidden');
refuses('QC cannot create an order from it', fn () => Db::callOut('sp_buying_create_po', [$qcU, $sup, json_encode([['itemId' => $d, 'qty' => 5]])]), 'Forbidden');
refuses('QC cannot read incoming stock', fn () => Db::rows('sp_incoming_by_item', [$qcU]), 'Forbidden');
refuses('QC cannot change buying details', fn () => Db::call('sp_item_buying_save', [$qcU, $a, 1, null, null]), 'Forbidden');
step('Store can read the advice', fn () => Db::call('sp_buying_advice', [$storeU]));
refuses('Store cannot create a purchase order (read only)', fn () => Db::callOut('sp_buying_create_po', [$storeU, $sup, json_encode([['itemId' => $d, 'qty' => 5]])]), 'Forbidden');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
