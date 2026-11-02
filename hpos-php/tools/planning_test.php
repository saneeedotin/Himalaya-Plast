<?php
declare(strict_types=1);

/**
 * The planning board against the stored procedures: planned dates on a job card, overlaps reported (never refused),
 * a queued job can change machine and a running one cannot, completed jobs are final, and who may plan.
 *
 *   php tools/planning_test.php
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

$fg = Db::callOut('sp_item_save', [$admin, null, "PFG-$tag", "Plan FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
$ws1 = Db::callOut('sp_workstation_save', [$admin, null, "PW1-$tag", "Plan Machine 1 $tag", 0, 'IDLE'])[0];
$ws2 = Db::callOut('sp_workstation_save', [$admin, null, "PW2-$tag", "Plan Machine 2 $tag", 0, 'IDLE'])[0];
$wo = [];
$jc = [];
foreach ([1, 2, 3] as $n) {
    $wo[$n] = Db::callOut('sp_work_order_create', [$admin, null, $fg, 100 * $n, null])[0];
    $jc[$n] = Db::callOut('sp_job_card_create', [$admin, $wo[$n], $n < 3 ? $ws1 : $ws2, null, null])[0];
}
$no = static fn (int $n): string => (string) one('SELECT work_order_number FROM work_orders WHERE id = ?', [$wo[$n]]);

echo "== The board\n";
$sets = Db::call('sp_plan_board', [$admin, day(0), 14]);
check('every machine is listed, including the new ones', count(array_filter($sets[0], static fn (array $w): bool => in_array($w['id'], [$ws1, $ws2], true))) === 2);
check('new job cards are in the "not planned" list', count(array_filter($sets[2], static fn (array $u): bool => in_array($u['job_card_id'], $jc, true))) === 3);
$plan = fn (int $n, ?string $ws, int $from, int $to) => Db::row('sp_job_card_plan', [$admin, $jc[$n], $ws, day($from), day($to)]);

echo "\n== Planning\n";
$r = step('a job is planned for days 1 to 3', fn () => $plan(1, null, 1, 3));
check('nothing overlaps yet', (int) ($r['clashes'] ?? -1) === 0);
$sets = Db::call('sp_plan_board', [$admin, day(0), 14]);
$onBoard = array_values(array_filter($sets[1], static fn (array $j): bool => $j['job_card_id'] === $jc[1]));
check('it is on the board with its dates and work order', count($onBoard) === 1 && substr((string) $onBoard[0]['planned_start'], 0, 10) === day(1) && $onBoard[0]['work_order_number'] === $no(1));
check('and no longer in the "not planned" list', count(array_filter($sets[2], static fn (array $u): bool => $u['job_card_id'] === $jc[1])) === 0);
$r = step('a second job overlapping it on the same machine is saved', fn () => $plan(2, null, 3, 5));
check('and the overlap is reported with the other work order', (int) ($r['clashes'] ?? 0) === 1 && str_contains((string) ($r['clash_list'] ?? ''), $no(1)), json_encode($r));
check('the overlapping plan was saved anyway', substr((string) one('SELECT planned_start FROM job_cards WHERE id = ?', [$jc[2]]), 0, 10) === day(3));
$r = step('a job right after (day 4) does not count as an overlap', fn () => $plan(3, $ws1, 4, 6));
check('moving job 3 onto machine 1 for days 4 to 6 overlaps job 2 only', (int) ($r['clashes'] ?? 0) === 1 && str_contains((string) $r['clash_list'], $no(2)) && !str_contains((string) $r['clash_list'], $no(1)));
step('a queued job can change machine', fn () => $plan(3, $ws2, 4, 6));
check('job 3 is on machine 2 now', one('SELECT workstation_id FROM job_cards WHERE id = ?', [$jc[3]]) === $ws2);
refuses('an end date before the start is refused', fn () => Db::row('sp_job_card_plan', [$admin, $jc[1], null, day(5), day(2)]), 'before the start');
refuses('a missing date is refused', fn () => Db::row('sp_job_card_plan', [$admin, $jc[1], null, day(5), null]), 'both');
refuses('more than 120 days is refused', fn () => Db::row('sp_job_card_plan', [$admin, $jc[1], null, day(0), day(200)]), '120 days');
refuses('an unknown job card is refused', fn () => Db::row('sp_job_card_plan', [$admin, '00000000-0000-0000-0000-000000000000', null, day(1), day(2)]), 'not found');
refuses('an unknown machine is refused', fn () => Db::row('sp_job_card_plan', [$admin, $jc[1], '00000000-0000-0000-0000-000000000000', day(1), day(2)]), 'Machine not found');
refuses('the table itself refuses an end before a start', fn () => Db::pdo()->exec("UPDATE job_cards SET planned_end = '2000-01-01' WHERE id = '{$jc[1]}'"), 'ck_jc_plan');
$far = Db::row('sp_job_card_plan', [$admin, $jc[3], null, day(50), day(52)]);
check('a job 50 days away is not on a 14-day board but is on a 62-day one', count(array_filter(Db::call('sp_plan_board', [$admin, day(0), 14])[1], static fn (array $j): bool => $j['job_card_id'] === $jc[3])) === 0
    && count(array_filter(Db::call('sp_plan_board', [$admin, day(0), 62])[1], static fn (array $j): bool => $j['job_card_id'] === $jc[3])) === 1);
$detail = Db::call('sp_job_card_plan_get', [$admin, $jc[1]]);
check('the plan form data comes back with the job and the machines', ($detail[0][0]['work_order_number'] ?? '') === $no(1) && count($detail[1] ?? []) >= 2);

echo "\n== Running and finished jobs\n";
Db::call('sp_job_card_set_status', [$admin, $jc[1], 'ACTIVE']);
refuses('a running job cannot move to another machine', fn () => $plan(1, $ws2, 1, 3), 'running job');
step('but its dates can change on its own machine', fn () => $plan(1, $ws1, 1, 4));
Db::call('sp_job_card_set_status', [$admin, $jc[1], 'COMPLETED']);
refuses('a completed job cannot be planned', fn () => $plan(1, null, 1, 3), 'complete');
step('a plan is removed', fn () => Db::call('sp_job_card_unplan', [$admin, $jc[2]]));
check('the job is unplanned again', one('SELECT planned_start FROM job_cards WHERE id = ?', [$jc[2]]) === null);
$sets = Db::call('sp_plan_board', [$admin, day(0), 14]);
check('and back in the "not planned" list', count(array_filter($sets[2], static fn (array $u): bool => $u['job_card_id'] === $jc[2])) === 1);
check('a completed job stays on the board', count(array_filter($sets[1], static fn (array $j): bool => $j['job_card_id'] === $jc[1])) === 1);

echo "\n== Who may do what\n";
$opU = Db::callOut('sp_user_create', [$admin, "pl.op.$tag@test.local", "Plan Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0];
$mgrU = Db::callOut('sp_user_create', [$admin, "pl.mgr.$tag@test.local", "Plan Manager $tag", Auth::hash('Manager-Pass-1234'), null, json_encode([$roles['Manager']]), $ip])[0];
$qcU = Db::callOut('sp_user_create', [$admin, "pl.qc.$tag@test.local", "Plan QC $tag", Auth::hash('Quality-Pass-1234'), null, json_encode([$roles['QC']]), $ip])[0];
step('an Operator can read the board', fn () => Db::call('sp_plan_board', [$opU, day(0), 14]));
refuses('an Operator cannot plan', fn () => Db::row('sp_job_card_plan', [$opU, $jc[2], null, day(1), day(2)]), 'Forbidden');
step('a Manager can plan', fn () => Db::row('sp_job_card_plan', [$mgrU, $jc[2], null, day(1), day(2)]));
refuses('QC has no access to the board', fn () => Db::call('sp_plan_board', [$qcU, day(0), 14]), 'Forbidden');
refuses('QC cannot remove a plan', fn () => Db::call('sp_job_card_unplan', [$qcU, $jc[2]]), 'Forbidden');
check('the audit log has the planning events', (int) one("SELECT COUNT(*) FROM audit_logs WHERE event IN ('JOB_PLANNED','JOB_UNPLANNED') AND user_id = ?", [$admin]) >= 5);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
