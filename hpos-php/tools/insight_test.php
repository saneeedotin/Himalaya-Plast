<?php
declare(strict_types=1);

/**
 * Repeat-order tracking and customer leads against the stored procedures: the usual gap is worked out from past
 * orders, customers come out as due, soon, quiet or on track, a follow-up is only created on request (and not twice),
 * alerts are raised for them, and a lead can become a customer once.
 *
 *   php tools/insight_test.php
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

$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
$admin = (string) (Db::row('sp_auth_get_user_by_email', [$devAdmin !== '' ? $devAdmin : 'admin@himalayaplast.local'])['id'] ?? '');
check('the administrator exists', $admin !== '');
if ($admin === '') {
    exit(1);
}
$tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$ip = '127.0.0.1';
$roles = array_column(Db::rows('sp_role_list', [$admin]), 'id', 'name');
$fg = Db::callOut('sp_item_save', [$admin, null, "IFG-$tag", "Insight FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];

/** A customer whose orders were placed on the given days ago (status DISPATCHED unless told otherwise) */
function customer(string $name, array $daysAgo, string $status = 'DISPATCHED'): string
{
    global $admin, $fg;
    $id = Db::callOut('sp_customer_save', [$admin, null, $name, null, null, '9000000000'])[0];
    foreach ($daysAgo as $d) {
        $o = Db::callOut('sp_sales_order_create', [$admin, $id, null, null, date('Y-m-d H:i:s', strtotime('+10 days')), 'EMAIL', null, json_encode([['itemId' => $fg, 'qty' => 100, 'rate' => 40]])])[0];
        $st = Db::pdo()->prepare('UPDATE sales_orders SET transaction_date = ?, status = ? WHERE id = ?');
        $st->execute([date('Y-m-d 10:00:00', strtotime("-$d days")), $status, $o]);
    }
    return $id;
}

echo "== Setup: customers with known order histories\n";
$cDue = customer("Due $tag", [60, 30]);          // gap 30, last 30 days ago
$cSoon = customer("Soon $tag", [55, 25]);        // gap 30, last 25 days ago: within 7 days of the date
$cOn = customer("OnTrack $tag", [35, 5]);        // gap 30, last 5 days ago
$cQuiet = customer("Quiet $tag", [60, 50]);      // gap 10, last 50 days ago: over twice the gap
$cNew = customer("New $tag", [20]);              // one order
$cNone = customer("None $tag", []);              // no orders
$cDraft = customer("Draft $tag", [60, 30], 'DRAFT');
$cCancel = customer("Cancelled $tag", [60, 30], 'CANCELLED');
check('setup: eight customers', $cDue && $cSoon && $cOn && $cQuiet && $cNew && $cNone && $cDraft && $cCancel);

echo "\n== Worked out from the orders\n";
$watch = array_column(Db::rows('sp_reorder_watch', [$admin]), null, 'customer_id');
check('Due: usual gap 30 days, 30 days since the last order', ($watch[$cDue]['reorder_status'] ?? '') === 'DUE' && (float) $watch[$cDue]['usual_gap_days'] === 30.0 && (int) $watch[$cDue]['days_since_last'] === 30);
check('Due: the expected date is today (last order + 30 days)', substr((string) $watch[$cDue]['expected_next'], 0, 10) === date('Y-m-d'));
check('Soon: 25 days into a 30-day gap', ($watch[$cSoon]['reorder_status'] ?? '') === 'SOON');
check('On track: 5 days into a 30-day gap', ($watch[$cOn]['reorder_status'] ?? '') === 'ON_TRACK');
check('Quiet: 50 days since the last order of a 10-day gap', ($watch[$cQuiet]['reorder_status'] ?? '') === 'QUIET' && (float) $watch[$cQuiet]['days_over'] === 40.0);
check('New: one order is too few to tell', ($watch[$cNew]['reorder_status'] ?? '') === 'NEW' && $watch[$cNew]['usual_gap_days'] === null);
check('a customer with no orders is not on the watch list', !isset($watch[$cNone]));
check('draft and cancelled orders do not count', !isset($watch[$cDraft]) && !isset($watch[$cCancel]));
check('the lifetime value adds up (2 x 4,000)', (float) $watch[$cDue]['lifetime_value'] === 8000.0);
$order = array_column(Db::rows('sp_reorder_watch', [$admin]), 'reorder_status');
check('the list puts Due before Quiet before Soon', array_search('DUE', $order) < array_search('QUIET', $order) && array_search('QUIET', $order) < array_search('SOON', $order));
check('a customer with no orders shows as NONE in the view', one('SELECT reorder_status FROM v_customer_reorder WHERE customer_id = ?', [$cNone]) === 'NONE');
$list = array_column(Db::rows('sp_customer_list', [$admin]), null, 'id');
check('the customer list carries the worked-out date and status', ($list[$cDue]['reorder_status'] ?? '') === 'DUE' && substr((string) $list[$cDue]['computed_next_order'], 0, 10) === date('Y-m-d'));
$sets = Db::call('sp_customer_get', [$admin, $cDue]);
check('the customer page gets the same numbers', ($sets[4][0]['reorder_status'] ?? '') === 'DUE' && (int) $sets[4][0]['order_count'] === 2);

echo "\n== A setting changes the answer\n";
refuses('a setting that is not a whole number is refused', fn () => Db::call('sp_settings_set', [$admin, 'reorder_min_orders', 'abc']), 'whole number');
step('needing 3 orders before judging', fn () => Db::call('sp_settings_set', [$admin, 'reorder_min_orders', '3']));
check('the Due customer (2 orders) is now too new to judge', one('SELECT reorder_status FROM v_customer_reorder WHERE customer_id = ?', [$cDue]) === 'NEW');
Db::call('sp_settings_set', [$admin, 'reorder_min_orders', '2']);
check('and back to Due at 2', one('SELECT reorder_status FROM v_customer_reorder WHERE customer_id = ?', [$cDue]) === 'DUE');

echo "\n== A person confirms before anything is created\n";
$before = (int) one('SELECT COUNT(*) FROM customer_follow_ups WHERE customer_id = ?', [$cDue]);
check('looking at the watch list created no follow-up', $before === 0);
$fid = step('creating the follow-up for the Due customer', fn () => Db::callOut('sp_reorder_follow_up', [$admin, $cDue])[0]);
$f = Db::pdo()->query("SELECT * FROM customer_follow_ups WHERE id = '$fid'")->fetch(PDO::FETCH_ASSOC);
check('it carries the numbers in its note and a reminder for tomorrow', str_starts_with((string) $f['notes'], 'Reorder watch: usually orders every 30 days') && substr((string) $f['reminder_date'], 0, 10) === date('Y-m-d', strtotime('+1 day')) && $f['reminder_status'] === 'PENDING');
refuses('pressing it twice is refused', fn () => Db::callOut('sp_reorder_follow_up', [$admin, $cDue]), 'already open');
refuses('a customer with no orders has nothing to work from', fn () => Db::callOut('sp_reorder_follow_up', [$admin, $cNone]), 'no orders');
check('the watch list shows the open follow-up', (int) (array_column(Db::rows('sp_reorder_watch', [$admin]), null, 'customer_id')[$cDue]['open_follow_ups'] ?? 0) === 1);

echo "\n== Alerts\n";
Db::call('sp_generate_alerts', []);
$n = Db::pdo()->query("SELECT title, audience FROM notifications WHERE source_type = 'CUSTOMER' AND source_id = 'reorder' AND status <> 'RESOLVED'")->fetch(PDO::FETCH_ASSOC);
check('one alert counts the customers due or quiet', is_array($n) && preg_match('/^(\d+) customers due to reorder$/', (string) $n['title'], $m) === 1 && (int) $m[1] >= 2 && $n['audience'] === 'Customers,SalesOrders', json_encode($n));
Db::call('sp_generate_alerts', []);
check('running it again does not add a second open alert', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'CUSTOMER' AND source_id = 'reorder' AND status <> 'RESOLVED'") === 1);
Db::callOut('sp_customer_follow_up_log', [$admin, $cOn, 'call back', null, date('Y-m-d H:i:s', strtotime('-1 hour'))]);
Db::call('sp_generate_alerts', []);
check('a reminder that has come due raises an alert (it never did before)', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'CUSTOMER' AND source_id = 'reminders' AND status <> 'RESOLVED'") === 1);

echo "\n== Customer leads\n";
$lead = step('a lead is added', fn () => Db::callOut('sp_customer_lead_save', [$admin, null, "Lead Co $tag", 'Ravi', '9811111111', 'ravi@example.com', 'Trade fair', 'NEW', date('Y-m-d H:i:s', strtotime('-1 hour')), 'asked for samples'])[0]);
check('it is in the list with its owner', in_array($lead, array_column(Db::rows('sp_customer_lead_list', [$admin]), 'id'), true));
refuses('a company name is required', fn () => Db::callOut('sp_customer_lead_save', [$admin, null, '  ', null, null, null, null, 'NEW', null, null]), 'company name');
refuses('a lead cannot be marked Won by editing', fn () => Db::callOut('sp_customer_lead_save', [$admin, $lead, "Lead Co $tag", null, null, null, null, 'WON', null, null]), 'Convert to customer');
step('a lead can be moved to Contacted', fn () => Db::callOut('sp_customer_lead_save', [$admin, $lead, "Lead Co $tag", 'Ravi', '9811111111', 'ravi@example.com', 'Trade fair', 'CONTACTED', date('Y-m-d H:i:s', strtotime('-1 hour')), 'called']));
Db::call('sp_generate_alerts', []);
check('a lead whose follow-up time has come raises an alert', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'CUSTOMER' AND source_id = 'leads' AND status <> 'RESOLVED'") === 1);
$custId = step('converting the lead creates the customer', fn () => Db::callOut('sp_customer_lead_convert', [$admin, $lead])[0]);
check('the customer has the lead\'s company, email and phone, and the lead is Won', one('SELECT name FROM customers WHERE id = ?', [$custId]) === "Lead Co $tag" && one('SELECT email FROM customers WHERE id = ?', [$custId]) === 'ravi@example.com'
    && one('SELECT status FROM customer_leads WHERE id = ?', [$lead]) === 'WON' && one('SELECT converted_customer_id FROM customer_leads WHERE id = ?', [$lead]) === $custId);
refuses('a lead cannot be converted twice', fn () => Db::callOut('sp_customer_lead_convert', [$admin, $lead]), 'already a customer');
refuses('a converted lead cannot be edited', fn () => Db::callOut('sp_customer_lead_save', [$admin, $lead, 'X', null, null, null, null, 'NEW', null, null]), 'already a customer');
$lost = Db::callOut('sp_customer_lead_save', [$admin, null, "Lost Co $tag", null, null, null, null, 'LOST', null, null])[0];
refuses('a lost lead cannot be converted', fn () => Db::callOut('sp_customer_lead_convert', [$admin, $lost]), 'lost lead');
Db::call('sp_generate_alerts', []);
check('with the follow-up handled the lead alert closes', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'CUSTOMER' AND source_id = 'leads' AND status <> 'RESOLVED'") === 0);

echo "\n== Who may do what\n";
$qcU = Db::callOut('sp_user_create', [$admin, "ins.qc.$tag@test.local", "Insight QC $tag", Auth::hash('Quality-Pass-1234'), null, json_encode([$roles['QC']]), $ip])[0];
refuses('QC cannot read the watch list', fn () => Db::rows('sp_reorder_watch', [$qcU]), 'Forbidden');
refuses('QC cannot create a follow-up', fn () => Db::callOut('sp_reorder_follow_up', [$qcU, $cQuiet]), 'Forbidden');
refuses('QC cannot read leads', fn () => Db::rows('sp_customer_lead_list', [$qcU]), 'Forbidden');
refuses('QC cannot add a lead', fn () => Db::callOut('sp_customer_lead_save', [$qcU, null, 'X', null, null, null, null, 'NEW', null, null]), 'Forbidden');
refuses('QC cannot convert a lead', fn () => Db::callOut('sp_customer_lead_convert', [$qcU, $lost]), 'Forbidden');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
