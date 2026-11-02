<?php
declare(strict_types=1);

/**
 * The job-card checklist against the stored procedures: eight steps, two of them optional, signed by whoever
 * ticks them, undoable until the job is complete, and visible only to those who may read or run production.
 *
 *   php tools/checklist_test.php
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

$fg = Db::callOut('sp_item_save', [$admin, null, "CFG-$tag", "Checklist FG $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
$ws = Db::callOut('sp_workstation_save', [$admin, null, "CWS-$tag", "Checklist Line $tag", 500, 'IDLE'])[0];
$wo = Db::callOut('sp_work_order_create', [$admin, null, $fg, 100, null])[0];
$jc = Db::callOut('sp_job_card_create', [$admin, $wo, $ws, null, null])[0];
check('setup: a queued job card', (string) one('SELECT status FROM job_cards WHERE id = ?', [$jc]) === 'QUEUED');

echo "== Ticking steps\n";
$sets = Db::call('sp_job_steps_get', [$admin, $jc]);
check('a new job has no steps done, and the job comes back for the page header', count($sets[1] ?? []) === 0 && ($sets[0][0]['fg_code'] ?? '') === "CFG-$tag");
step('material verification is done', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'MATERIAL_VERIFY', 'DONE', null, 'Lot A12 black']));
step('the die change is marked not needed', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'DIE_CHANGE', 'NOT_NEEDED', null, null]));
step('the air pressure is recorded as 6.5 bar', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'AIR_PRESSURE', 'DONE', 6.5, null]));
$rows = array_column(Db::call('sp_job_steps_get', [$admin, $jc])[1], null, 'step_key');
check('each step keeps its status, who and when', ($rows['MATERIAL_VERIFY']['status'] ?? '') === 'DONE' && ($rows['DIE_CHANGE']['status'] ?? '') === 'NOT_NEEDED'
    && $rows['MATERIAL_VERIFY']['done_by_name'] === (string) one('SELECT name FROM users WHERE id = ?', [$admin]) && abs(strtotime((string) $rows['MATERIAL_VERIFY']['done_at']) - time()) < 120);
check('the note and the pressure are stored', $rows['MATERIAL_VERIFY']['note'] === 'Lot A12 black' && (float) $rows['AIR_PRESSURE']['reading'] === 6.5);
step('ticking again updates the step instead of adding one', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'AIR_PRESSURE', 'DONE', 6.8, 'rechecked']));
check('still three steps, pressure now 6.8', (int) one('SELECT COUNT(*) FROM job_card_steps WHERE job_card_id = ?', [$jc]) === 3 && (float) one("SELECT reading FROM job_card_steps WHERE job_card_id = ? AND step_key = 'AIR_PRESSURE'", [$jc]) === 6.8);
step('a reading on a step that has none is ignored', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'HOPPER_LOADING', 'DONE', 9.9, null]));
check('hopper loading has no pressure stored', one("SELECT reading FROM job_card_steps WHERE job_card_id = ? AND step_key = 'HOPPER_LOADING'", [$jc]) === null);
step('a step can be undone', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'HOPPER_LOADING', 'RESET', null, null]));
check('it is gone, and three steps are left', (int) one("SELECT COUNT(*) FROM job_card_steps WHERE job_card_id = ? AND step_key = 'HOPPER_LOADING'", [$jc]) === 0 && (int) one('SELECT COUNT(*) FROM job_card_steps WHERE job_card_id = ?', [$jc]) === 3);
refuses('only the two optional steps can be marked not needed', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'AIR_PRESSURE', 'NOT_NEEDED', null, null]), 'not needed');
refuses('an unknown step is refused', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'DANCE', 'DONE', null, null]), 'Unknown');
refuses('an unknown action is refused', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'MATERIAL_VERIFY', 'MAYBE', null, null]), 'done, not needed or undo');
refuses('a negative pressure is refused', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'AIR_PRESSURE', 'DONE', -1, null]), 'negative');
refuses('an unknown job card is refused', fn () => Db::call('sp_job_step_set', [$admin, '00000000-0000-0000-0000-000000000000', 'MATERIAL_VERIFY', 'DONE', null, null]), 'not found');
$sum = array_column(Db::rows('sp_job_steps_summary', [$admin]), 'steps_done', 'job_card_id');
check('the summary counts the steps for the shop floor', (int) ($sum[$jc] ?? 0) === 3, json_encode($sum[$jc] ?? null));

echo "\n== A finished job is final\n";
Db::call('sp_job_card_set_status', [$admin, $jc, 'ACTIVE']);
step('steps can still be ticked while the job runs', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'FIRST_QUALITY', 'DONE', null, null]));
Db::call('sp_job_card_set_status', [$admin, $jc, 'COMPLETED']);
refuses('a completed job cannot be changed', fn () => Db::call('sp_job_step_set', [$admin, $jc, 'PRODUCTION_STARTED', 'DONE', null, null]), 'complete');
check('and it drops out of the shop-floor summary', !isset(array_column(Db::rows('sp_job_steps_summary', [$admin]), 'steps_done', 'job_card_id')[$jc]));

echo "\n== Who may do what\n";
$opU = Db::callOut('sp_user_create', [$admin, "chk.op.$tag@test.local", "Checklist Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0];
$mgrU = Db::callOut('sp_user_create', [$admin, "chk.mgr.$tag@test.local", "Checklist Manager $tag", Auth::hash('Manager-Pass-1234'), null, json_encode([$roles['Manager']]), $ip])[0];
$salesU = Db::callOut('sp_user_create', [$admin, "chk.sales.$tag@test.local", "Checklist Sales $tag", Auth::hash('Sales-Pass-12345'), null, json_encode([$roles['Sales']]), $ip])[0];
$jc2 = Db::callOut('sp_job_card_create', [$admin, $wo, $ws, null, null])[0];
step('an Operator can tick a step', fn () => Db::call('sp_job_step_set', [$opU, $jc2, 'TRIAL_PRODUCTION', 'DONE', null, null]));
check('and it is signed with the operator\'s name', one("SELECT done_by_name FROM job_card_steps WHERE job_card_id = ? AND step_key = 'TRIAL_PRODUCTION'", [$jc2]) === "Checklist Operator $tag");
step('a Manager can read the checklist', fn () => Db::call('sp_job_steps_get', [$mgrU, $jc2]));
refuses('a Manager cannot tick a step (read only)', fn () => Db::call('sp_job_step_set', [$mgrU, $jc2, 'MATERIAL_VERIFY', 'DONE', null, null]), 'Forbidden');
refuses('Sales cannot read the checklist', fn () => Db::call('sp_job_steps_get', [$salesU, $jc2]), 'Forbidden');
refuses('Sales cannot read the summary', fn () => Db::rows('sp_job_steps_summary', [$salesU]), 'Forbidden');
check('the audit log has the step events', (int) one("SELECT COUNT(*) FROM audit_logs WHERE event = 'JOB_STEP' AND user_id = ?", [$admin]) >= 6);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
