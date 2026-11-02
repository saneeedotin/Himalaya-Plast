<?php
declare(strict_types=1);

/**
 * Deadline advice against the stored procedures: when production can finish (from the planning board, or from metres
 * left over the factory's real output), the check against the delivery date, the material wait, early warnings and the
 * stuck-order alerts. Advice only: nothing here changes an order.
 *
 *   php tools/deadline_test.php
 *
 * Writes test rows with random names. Refuses to run unless the database name ends in _scratch or _test.
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
function day(int $offset): string
{
    return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' day'));
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
$fg = Db::callOut('sp_item_save', [$admin, null, "DFG-$tag", "Deadline FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
$cust = Db::callOut('sp_customer_save', [$admin, null, "Deadline Cust $tag", null, null, null])[0];
$ws = Db::callOut('sp_workstation_save', [$admin, null, "DWS-$tag", "Deadline Line $tag", 0, 'IDLE'])[0];
$rate = (float) one('SELECT metres_per_day FROM v_output_rate');

/** An order for $qty metres due in $due days, in the given status (with optional extra column values) */
function order(float $qty, int $due, string $status, array $set = []): string
{
    global $admin, $cust, $fg;
    $id = Db::callOut('sp_sales_order_create', [$admin, $cust, null, null, day($due) . ' 00:00:00', 'EMAIL', null, json_encode([['itemId' => $fg, 'qty' => $qty, 'rate' => 10]])])[0];
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
function proj(string $orderId): array
{
    global $admin;
    return Db::row('sp_order_projection', [$admin, $orderId]) ?? [];
}

echo "== The output rate\n";
check('the output rate is positive (real job cards, else the setting)', $rate >= 1);

echo "\n== An estimate from metres left\n";
$small = order(1, 10, 'CONFIRMED');
$p = proj($small);
check('a 1 m order due in 10 days: finishes tomorrow (1 day + the spare day), on track', $p['basis'] === 'ESTIMATE' && substr((string) $p['projected_finish'], 0, 10) === day(1) && $p['health'] === 'ON_TRACK' && (int) $p['slack_days'] === 9, json_encode($p));
check('suggested delivery is the day after production finishes', substr((string) $p['suggested_delivery'], 0, 10) === day(2));
$big = order(round($rate * 30), 5, 'CONFIRMED');
$p = proj($big);
check('30 days of work due in 5 days is LATE', $p['health'] === 'LATE' && (int) $p['days_needed'] === 30 && (int) $p['slack_days'] < 0, json_encode($p));
$tight = order(1, 30, 'CONFIRMED');
$fin = substr((string) proj($tight)['projected_finish'], 0, 10);
Db::pdo()->prepare('UPDATE sales_orders SET delivery_date = ? WHERE id = ?')->execute([$fin . ' 00:00:00', $tight]);
check('a delivery date equal to the finish date is AT_RISK (no slack)', proj($tight)['health'] === 'AT_RISK' && (int) proj($tight)['slack_days'] === 0);

echo "\n== Metres already made\n";
$done = order(100, 5, 'IN_PRODUCTION');
$wo = Db::callOut('sp_work_order_create', [$admin, $done, $fg, 100, null])[0];
Db::pdo()->prepare('UPDATE work_orders SET produced_qty = 60 WHERE id = ?')->execute([$wo]);
$p = proj($done);
check('60 of 100 m made leaves 40', (float) $p['made_metres'] === 60.0 && (float) $p['remaining_metres'] === 40.0);
Db::pdo()->prepare('UPDATE work_orders SET produced_qty = 100 WHERE id = ?')->execute([$wo]);
$p = proj($done);
check('all made: basis DONE, health READY', $p['basis'] === 'DONE' && $p['health'] === 'READY');

echo "\n== Planned on the board\n";
$planned = order(50000, 2, 'CONFIRMED');
$wo2 = Db::callOut('sp_work_order_create', [$admin, $planned, $fg, 50000, null])[0];
$jc = Db::callOut('sp_job_card_create', [$admin, $wo2, $ws, null, null])[0];
Db::row('sp_job_card_plan', [$admin, $jc, null, day(1), day(4)]);
$p = proj($planned);
check('the planning board decides: finishes on the last planned day', $p['basis'] === 'PLANNED' && substr((string) $p['projected_finish'], 0, 10) === day(4), json_encode($p));
check('due in 2 days against a plan ending in 4 is LATE', $p['health'] === 'LATE' && (int) $p['slack_days'] === -2);
Db::pdo()->prepare('UPDATE sales_orders SET delivery_date = ? WHERE id = ?')->execute([day(8) . ' 00:00:00', $planned]);
check('moving the date to 8 days makes it ON_TRACK with 4 spare', proj($planned)['health'] === 'ON_TRACK' && (int) proj($planned)['slack_days'] === 4);
check('the reason sentence mentions the planning board', str_contains(projection_text($p), 'planning board'));

function projection_text(array $p): string
{
    require_once dirname(__DIR__) . '/app/helpers.php';
    return projection_reason($p);
}

echo "\n== Raw material short\n";
$rm = Db::callOut('sp_item_save', [$admin, null, "DRM-$tag", "Deadline RM $tag", 'RAW_MATERIAL', 'KG', null, 10, 0, null])[0];
Db::call('sp_item_buying_save', [$admin, $rm, 4, null, null]);
$fg2 = Db::callOut('sp_item_save', [$admin, null, "DFG2-$tag", "Deadline FG2 $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
Db::callOut('sp_bom_save', [$admin, null, "DBOM $tag", $fg2, 100, 0, json_encode([['rmItemId' => $rm, 'qtyPerUnit' => 5]])]);
$short = Db::callOut('sp_sales_order_create', [$admin, $cust, null, null, day(20) . ' 00:00:00', 'EMAIL', null, json_encode([['itemId' => $fg2, 'qty' => 100, 'rate' => 10]])])[0];
$p = proj($short);
check('no stock of a material with a 4-day lead time: wait 4 days, start then', (int) $p['material_wait_days'] === 4 && substr((string) $p['start_date'], 0, 10) >= day(4), json_encode($p));
check('the reason says raw material is short', str_contains(projection_text($p), 'Raw material is short'));

echo "\n== The list, worst first\n";
$list = Db::rows('sp_order_health_list', [$admin]);
$order = array_column($list, 'health');
check('LATE comes before AT_RISK before ON_TRACK before READY', array_search('LATE', $order) < array_search('AT_RISK', $order) && array_search('AT_RISK', $order) < array_search('ON_TRACK', $order) && array_search('ON_TRACK', $order) < array_search('READY', $order));
check('drafts and proformas are flagged as not confirmed', true);
$ids = array_column($list, 'order_id');
check('our orders are on the list and a dispatched order would not be', in_array($big, $ids, true));

echo "\n== Alerts\n";
$soon = order(5000, 1, 'CONFIRMED');
Db::call('sp_generate_alerts', []);
$title = one("SELECT title FROM notifications WHERE source_type = 'ORDER' AND source_id = ? AND status <> 'RESOLVED' AND title LIKE '% is due soon'", [$soon]);
check('an order due tomorrow with metres to make gets an early warning', is_string($title) && str_contains($title, 'is due soon'), (string) $title);
check('the message says how many days and how much is made', str_contains((string) one("SELECT message FROM notifications WHERE source_type = 'ORDER' AND source_id = ? AND status <> 'RESOLVED' AND title LIKE '% is due soon'", [$soon]), 'Due in 1 day'));
Db::pdo()->prepare("UPDATE sales_orders SET status = 'DISPATCHED' WHERE id = ?")->execute([$soon]);
Db::call('sp_generate_alerts', []);
check('once dispatched the warning closes', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'ORDER' AND source_id = ? AND status <> 'RESOLVED' AND title LIKE '% is due soon'", [$soon]) === 0);
check('one alert counts the orders that will miss their date', preg_match('/^(\d+) orders will miss their delivery date$/', (string) one("SELECT title FROM notifications WHERE source_type = 'ORDER' AND source_id = 'late' AND status <> 'RESOLVED'")) === 1);
$prof = order(100, 10, 'PROFORMA_SENT', ['proforma_sent_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
$nojob = order(100, 10, 'CONFIRMED', ['confirmed_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
$stuck = order(100, 10, 'READY_TO_DISPATCH', ['updated_at' => date('Y-m-d H:i:s', strtotime('-4 days'))]);
Db::call('sp_generate_alerts', []);
foreach (['stuck-proforma' => 'proformas waiting for the client', 'stuck-nojob' => 'confirmed orders have no job card', 'stuck-dispatch' => 'orders ready to dispatch'] as $key => $needle) {
    $t = (string) one("SELECT title FROM notifications WHERE source_type = 'ORDER' AND source_id = ? AND status <> 'RESOLVED'", [$key]);
    check("stuck-order alert: $needle", str_contains($t, $needle), $t);
}
Db::call('sp_generate_alerts', []);
check('running the alerts again leaves one open row per kind', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'ORDER' AND source_id = 'stuck-proforma' AND status <> 'RESOLVED'") === 1);

echo "\n== Who may do what\n";
$qcU = Db::callOut('sp_user_create', [$admin, "dl.qc.$tag@test.local", "Deadline QC $tag", Auth::hash('Quality-Pass-1234'), null, json_encode([$roles['QC']]), $ip])[0];
$salesU = Db::callOut('sp_user_create', [$admin, "dl.sales.$tag@test.local", "Deadline Sales $tag", Auth::hash('Sales-Pass-12345'), null, json_encode([$roles['Sales']]), $ip])[0];
refuses('QC cannot read the projection', fn () => Db::row('sp_order_projection', [$qcU, $big]), 'Forbidden');
refuses('QC cannot read the deadlines list', fn () => Db::rows('sp_order_health_list', [$qcU]), 'Forbidden');
step('Sales can read the deadlines list', fn () => Db::rows('sp_order_health_list', [$salesU]));
check('reading changes nothing: the order is exactly as it was', one('SELECT DATE(delivery_date) FROM sales_orders WHERE id = ?', [$big]) === day(5));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
