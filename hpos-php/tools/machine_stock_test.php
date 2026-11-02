<?php
declare(strict_types=1);

/**
 * Stock near the machines against the stored procedures: material is issued from the store to a machine (never more
 * than the store holds), returned, and used by the machine's production first; total stock does not move; a machine
 * about to run out, or already out, raises an alert.
 *
 *   php tools/machine_stock_test.php
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
$rm = Db::callOut('sp_item_save', [$admin, null, "MRM-$tag", "Machine RM $tag", 'RAW_MATERIAL', 'KG', null, 10, 0, null])[0];
$fg = Db::callOut('sp_item_save', [$admin, null, "MFG-$tag", "Machine FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
$ws1 = Db::callOut('sp_workstation_save', [$admin, null, "MW1-$tag", "Machine One $tag", 0, 'IDLE'])[0];
$ws2 = Db::callOut('sp_workstation_save', [$admin, null, "MW2-$tag", "Machine Two $tag", 0, 'IDLE'])[0];
Db::callOut('sp_bom_save', [$admin, null, "MBOM $tag", $fg, 100, 0, json_encode([['rmItemId' => $rm, 'qtyPerUnit' => 5]])]);
Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 1000, 'OPENING', 'machine stock test', $ip]);
$total = fn (): float => (float) one('SELECT physical_stock FROM v_stock_balance WHERE item_id = ?', [$rm]);
$at = fn (string $ws): float => (float) one('SELECT fn_machine_stock(?, ?)', [$ws, $rm]);
$pick = fn () => array_column(Db::call('sp_machine_stock_pick', [$admin])[1], null, 'item_id')[$rm] ?? [];

echo "== Issuing from the store\n";
check('1,000 kg in the store, none at a machine', (float) $pick()['total_stock'] === 1000.0 && (float) $pick()['at_machines'] === 0.0);
refuses('a quantity of zero is refused', fn () => Db::callOut('sp_machine_stock_issue', [$admin, $ws1, $rm, 0, null, null]), 'above zero');
refuses('more than the store holds is refused, saying how much is there', fn () => Db::callOut('sp_machine_stock_issue', [$admin, $ws1, $rm, 5000, null, null]), 'Only 1,000 KG is in the store');
refuses('an unknown machine is refused', fn () => Db::callOut('sp_machine_stock_issue', [$admin, '00000000-0000-0000-0000-000000000000', $rm, 10, null, null]), 'Choose the machine');
refuses('a finished-goods item cannot be issued', fn () => Db::callOut('sp_machine_stock_issue', [$admin, $ws1, $fg, 10, null, null]), 'raw material or packaging');
step('20 sacks of 25 kg are issued to machine one', fn () => Db::callOut('sp_machine_stock_issue', [$admin, $ws1, $rm, 500, 20, 'first lot']));
check('500 kg is at machine one, the store holds the other 500', $at($ws1) === 500.0 && (float) $pick()['at_machines'] === 500.0);
check('the total stock did not change (still 1,000)', $total() === 1000.0);
refuses('the store can no longer give 600', fn () => Db::callOut('sp_machine_stock_issue', [$admin, $ws2, $rm, 600, null, null]), 'Only 500 KG is in the store');
refuses('a movement cannot be edited', fn () => Db::pdo()->exec("UPDATE machine_stock_moves SET qty = 1 WHERE item_id = '$rm'"), 'cannot be changed');
refuses('a movement cannot be deleted', fn () => Db::pdo()->exec("DELETE FROM machine_stock_moves WHERE item_id = '$rm'"), 'cannot be deleted');

echo "\n== Returning\n";
refuses('returning more than the machine holds is refused', fn () => Db::callOut('sp_machine_stock_return', [$admin, $ws1, $rm, 900, null]), 'only has 500');
refuses('machine two has nothing to return', fn () => Db::callOut('sp_machine_stock_return', [$admin, $ws2, $rm, 1, null]), 'only has 0');
step('100 kg goes back to the store', fn () => Db::callOut('sp_machine_stock_return', [$admin, $ws1, $rm, 100, 'not needed']));
check('400 kg at machine one, total still 1,000', $at($ws1) === 400.0 && $total() === 1000.0);

echo "\n== The machine uses its own stock\n";
$wo = Db::callOut('sp_work_order_create', [$admin, null, $fg, 500, null])[0];
$jc = Db::callOut('sp_job_card_create', [$admin, $wo, $ws1, null, null])[0];
Db::call('sp_job_card_set_status', [$admin, $jc, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jc, 'METERS', 100]);
check('100 m uses 5 kg: the total fell to 995 as before', $total() === 995.0);
check('and the same 5 kg came off the machine (395 left)', $at($ws1) === 395.0);
$used = Db::pdo()->query("SELECT kind, qty, reference_id FROM machine_stock_moves WHERE workstation_id = '$ws1' AND kind = 'CONSUME'")->fetch(PDO::FETCH_ASSOC);
check('it is recorded as Used, against the job card', $used && (float) $used['qty'] === 5.0 && $used['reference_id'] === $jc);
$jc2 = Db::callOut('sp_job_card_create', [$admin, $wo, $ws2, null, null])[0];
Db::call('sp_job_card_set_status', [$admin, $jc2, 'ACTIVE']);
step('a machine with no stock of its own still produces (taken from the store)', fn () => Db::call('sp_production_log', [$admin, $jc2, 'METERS', 100]));
check('nothing was recorded against machine two and the total fell to 990', $at($ws2) === 0.0 && $total() === 990.0);

echo "\n== Running low\n";
Db::callOut('sp_machine_stock_issue', [$admin, $ws2, $rm, 6, null, 'a small lot']);
Db::call('sp_production_log', [$admin, $jc2, 'METERS', 100]);          // uses 5, leaves 1
$lv = array_column(Db::rows('sp_machine_stock_levels', [$admin]), null, 'workstation_id');
check('machine two now holds 1 kg and uses material every hour', (float) $lv[$ws2]['on_machine'] === 1.0 && (float) $lv[$ws2]['per_hour'] > 0 && $lv[$ws2]['hours_left'] !== null);
check('1 kg at about 0.2 kg an hour is about 4.8 hours: not yet under the two-hour warning', $lv[$ws2]['status'] === 'OK' && abs((float) $lv[$ws2]['hours_left'] - 4.8) < 0.2, json_encode([$lv[$ws2]['status'], $lv[$ws2]['hours_left']]));
check('machine one with 395 kg is fine', $lv[$ws1]['status'] === 'OK' && (float) $lv[$ws1]['hours_left'] > 100);
Db::call('sp_production_log', [$admin, $jc2, 'METERS', 100]);          // needs 5, only 1 left: uses 1
check('when the machine runs out it holds nothing, total still falls by the full 5', $at($ws2) === 0.0 && $total() === 980.0);
$lv = array_column(Db::rows('sp_machine_stock_levels', [$admin]), null, 'workstation_id');
check('a machine that used material and now has none is shown as LOW with 0 hours left', $lv[$ws2]['status'] === 'LOW' && (float) $lv[$ws2]['hours_left'] === 0.0);
Db::call('sp_generate_alerts', []);
$n = (string) one("SELECT title FROM notifications WHERE source_type = 'MACHINE' AND source_id = 'low-material' AND status <> 'RESOLVED'");
check('one alert counts the machine and material pairs running low', preg_match('/^(\d+) machine and material pairs are running low$/', $n) === 1, $n);
Db::callOut('sp_machine_stock_issue', [$admin, $ws2, $rm, 500, null, null]);
Db::call('sp_generate_alerts', []);
check('after more is issued, machine two is fine again', array_column(Db::rows('sp_machine_stock_levels', [$admin]), null, 'workstation_id')[$ws2]['status'] === 'OK');
$loc = array_column(Db::rows('sp_stock_locations', [$admin]), 'at_machines', 'item_id');
check('the Stock page figure: at machines = 395 + 500', (float) ($loc[$rm] ?? 0) === 895.0, (string) ($loc[$rm] ?? 'none'));
check('the recent movements are listed with who did them', count(Db::rows('sp_machine_stock_recent', [$admin, 30])) >= 5 && one("SELECT by_name FROM machine_stock_moves WHERE workstation_id = '$ws1' AND kind = 'ISSUE' LIMIT 1") !== null);

echo "\n== Who may do what\n";
$opU = Db::callOut('sp_user_create', [$admin, "ms.op.$tag@test.local", "Machine Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0];
$storeU = Db::callOut('sp_user_create', [$admin, "ms.store.$tag@test.local", "Machine Store $tag", Auth::hash('Store-Pass-1234567'), null, json_encode([$roles['Store']]), $ip])[0];
$acctU = Db::callOut('sp_user_create', [$admin, "ms.acct.$tag@test.local", "Machine Accounts $tag", Auth::hash('Accounts-Pass-123'), null, json_encode([$roles['Accounts']]), $ip])[0];
step('Store can issue to a machine', fn () => Db::callOut('sp_machine_stock_issue', [$storeU, $ws1, $rm, 10, null, null]));
refuses('an Operator cannot issue (no stock create right)', fn () => Db::callOut('sp_machine_stock_issue', [$opU, $ws1, $rm, 10, null, null]), 'Forbidden');
refuses('an Operator cannot return', fn () => Db::callOut('sp_machine_stock_return', [$opU, $ws1, $rm, 1, null]), 'Forbidden');
step('an Operator can see the levels on the shop floor (production read)', fn () => Db::rows('sp_machine_stock_levels', [$opU]));
refuses('an Operator cannot open the full screen data (stock read)', fn () => Db::call('sp_machine_stock_pick', [$opU]), 'Forbidden');
refuses('Accounts sees nothing of machine stock', fn () => Db::rows('sp_machine_stock_levels', [$acctU]), 'Forbidden');
refuses('Accounts cannot issue either', fn () => Db::callOut('sp_machine_stock_issue', [$acctU, $ws1, $rm, 1, null, null]), 'Forbidden');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
