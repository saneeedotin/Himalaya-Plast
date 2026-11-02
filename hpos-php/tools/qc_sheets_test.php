<?php
declare(strict_types=1);

/**
 * The QC sheet against the stored procedures: what is measured is set up as fields (name, kind, unit, limits) and the header
 * words are labels the client can rename; a sheet starts from a job card and knows its machine; readings are signed by the
 * person who enters them, flagged against the product's limits, never edited or deleted (only voided), and a closed sheet
 * is final; the machine board says which running machines are due a reading.
 *
 *   php tools/qc_sheets_test.php
 *
 * Writes test rows with random codes (the fields it adds are switched off again at the end, and renamed labels put back).
 * Refuses to run unless the database name ends in _scratch or _test.
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
/** The JSON the procedure takes: code => value, empty ones left out. */
function vals(array $m): string
{
    $o = [];
    foreach ($m as $code => $v) {
        if ($v !== null) {
            $o[] = ['code' => (string) $code, 'value' => (string) $v];
        }
    }
    return json_encode($o);
}
function addRow(string $actor, string $sheet, array $m, ?string $at = null): string
{
    return (string) Db::callOut('sp_qc_sheet_add_row', [$actor, $sheet, $at, vals($m)])[0];
}
function openSheet(string $actor, string $jc, array $o = []): string
{
    return (string) Db::callOut('sp_qc_sheet_open', [$actor, $jc, $o['dtd'] ?? null, $o['m'] ?? null, $o['mfg'] ?? null, $o['metres'] ?? null, $o['colour'] ?? null,
        $o['x1'] ?? null, $o['x2'] ?? null, $o['x3'] ?? null])[0];
}
function saveField(string $actor, ?string $id, string $code, string $label, string $kind, ?string $unit, ?string $choices, ?string $bad, int $limits, int $sort, int $active): string
{
    return (string) Db::callOut('sp_qc_field_save', [$actor, $id, $code, $label, $kind, $unit, $choices, $bad, $limits, $sort, $active])[0];
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
$origLabels = array_column(Db::rows('sp_qc_labels_list', [$admin]), null, 'label_key');
$added = [];

echo "== Setup: product, die, two machines, three running jobs\n";
$rm = Db::callOut('sp_item_save', [$admin, null, "QRM-$tag", "QC RM $tag", 'RAW_MATERIAL', 'KG', null, 0, 0, null])[0];
$fg = Db::callOut('sp_item_save', [$admin, null, "QFG-$tag", "QC Gasket $tag", 'FINISHED_GOODS', 'M', null, 0, 0, null])[0];
$ws = Db::callOut('sp_workstation_save', [$admin, null, "QWS-$tag", "QC Line $tag", 500, 'IDLE'])[0];
$ws2 = Db::callOut('sp_workstation_save', [$admin, null, "QW2-$tag", "QC Second Line $tag", 500, 'IDLE'])[0];
$die = Db::callOut('sp_die_save', [$admin, null, "D$tag", "QC Die $tag", 5000, 'ACTIVE'])[0];
Db::callOut('sp_bom_save', [$admin, null, "QBOM $tag", $fg, 100, 0, json_encode([['rmItemId' => $rm, 'qtyPerUnit' => 1]])]);
Db::callOut('sp_stock_adjust', [$admin, $rm, 'IN', 1000, 'OPENING', 'qc test', $ip]);
$wo1 = Db::callOut('sp_work_order_create', [$admin, null, $fg, 500, null])[0];
$wo2 = Db::callOut('sp_work_order_create', [$admin, null, $fg, 300, null])[0];
$wo3 = Db::callOut('sp_work_order_create', [$admin, null, $fg, 200, null])[0];
$jc1 = Db::callOut('sp_job_card_create', [$admin, $wo1, $ws, null, $die])[0];
$jc2 = Db::callOut('sp_job_card_create', [$admin, $wo2, $ws, null, null])[0];
$jc3 = Db::callOut('sp_job_card_create', [$admin, $wo3, $ws2, null, null])[0];
Db::call('sp_job_card_set_status', [$admin, $jc1, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jc1, 'METERS', 100]);
Db::call('sp_job_card_set_status', [$admin, $jc3, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jc3, 'METERS', 50]);
check('setup: a job with a batch and a job without one', (string) one('SELECT fg_batch_number FROM work_orders WHERE id = ?', [$wo1]) !== '' && one('SELECT fg_batch_number FROM work_orders WHERE id = ?', [$wo2]) === null);
$batch = (string) one('SELECT fg_batch_number FROM work_orders WHERE id = ?', [$wo1]);

echo "\n== What the sheet measures\n";
$fields = array_column(Db::rows('sp_qc_field_list', [$admin]), null, 'code');
check('pin, width, leg, weight and fitting are set up from the start', isset($fields['PIN'], $fields['WIDTH'], $fields['LEG'], $fields['WEIGHT'], $fields['FITTING']));
check('the first four are numbers with limits, fitting is a choice where Not OK is a fault', $fields['PIN']['kind'] === 'NUMBER' && (int) $fields['PIN']['has_limits'] === 1
    && $fields['FITTING']['kind'] === 'CHOICE' && $fields['FITTING']['bad_values'] === 'NOT_OK' && (int) $fields['FITTING']['has_limits'] === 0);
check('no reading is left without its values: earlier readings were carried over', (int) one("SELECT COUNT(*) FROM qc_sheet_rows r WHERE r.pin IS NOT NULL AND NOT EXISTS
    (SELECT 1 FROM qc_sheet_values v JOIN qc_fields f ON f.id = v.field_id AND f.code = 'PIN' WHERE v.row_id = r.id)") === 0);

echo "\n== Colour and limits on the product\n";
refuses('an unknown size is refused', fn () => Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([['parameter' => 'COLOUR', 'min' => 1]])]), 'known size');
refuses('a size with no limits switched on (fitting) is refused', fn () => Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([['parameter' => 'FITTING', 'min' => 1]])]), 'known size');
refuses('a lowest value above the highest is refused', fn () => Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([['parameter' => 'PIN', 'min' => 6, 'max' => 5]])]), 'not above the highest');
$setLimits = fn () => Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([
    ['parameter' => 'PIN', 'target' => 5, 'min' => 4.9, 'max' => 5.1], ['parameter' => 'WIDTH', 'max' => 11.8], ['parameter' => 'WEIGHT', 'min' => 28, 'max' => 30]])]);
step('limits saved: pin 4.9-5.1, width up to 11.8, weight 28-30', $setLimits);
check('the colour is on the item', one('SELECT colour FROM items WHERE id = ?', [$fg]) === 'Black');
check('three limits come back in the sheet order', implode(',', array_column(Db::rows('sp_qc_spec_list', [$admin, $fg]), 'parameter')) === 'PIN,WIDTH,WEIGHT');
step('saving again replaces the list', fn () => Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([['parameter' => 'LEG', 'min' => 5.9, 'max' => 6.1]])]));
check('only the leg limit is left', implode(',', array_column(Db::rows('sp_qc_spec_list', [$admin, $fg]), 'parameter')) === 'LEG');
$setLimits();

echo "\n== Starting a sheet\n";
$pick = Db::rows('sp_qc_job_card_pick', [$admin]);
$mine = array_values(array_filter($pick, static fn (array $r): bool => $r['job_card_id'] === $jc1));
check('the running job is offered with its batch, die and colour', count($mine) === 1 && $mine[0]['batch_number'] === $batch && $mine[0]['die_code'] === "D$tag" && $mine[0]['colour'] === 'Black');
refuses('a job without a batch cannot start a sheet', fn () => openSheet($admin, $jc2), 'no batch yet');
refuses('no job card is refused', fn () => openSheet($admin, '00000000-0000-0000-0000-000000000000'), 'job card');
refuses('a future manufacturing date is refused', fn () => openSheet($admin, $jc1, ['mfg' => date('Y-m-d', strtotime('+3 days'))]), 'future');
$sheet = step('a sheet starts from the job card', fn () => openSheet($admin, $jc1, ['dtd' => date('Y-m-d'), 'm' => 'M-04', 'x1' => 'Operator A']));
$sets = Db::call('sp_qc_sheet_get', [$admin, $sheet]);
$h = $sets[0][0] ?? [];
check('the header is filled from the job: number, die, batch, colour, metres, M number', str_starts_with((string) ($h['sheet_number'] ?? ''), 'QS-') && $h['die_code'] === "D$tag" && $h['batch_number'] === $batch
    && $h['colour'] === 'Black' && (float) $h['metres'] === 500.0 && $h['m_no'] === 'M-04' && $h['status'] === 'OPEN' && $h['item_code'] === "QFG-$tag");
check('it knows its machine, and carries the extra header text', $h['machine_code'] === "QWS-$tag" && $h['workstation_id'] === $ws && $h['x1'] === 'Operator A' && $h['x2'] === null);
check('the limits come back with the sheet', count($sets[2] ?? []) === 3);
check('the fields come back with the sheet, in order', isset($sets[4]) && count($sets[4]) >= 5 && $sets[4][0]['code'] === 'PIN');
$sheetDefault = openSheet($admin, $jc3);
check('the M number defaults to the machine code', Db::call('sp_qc_sheet_get', [$admin, $sheetDefault])[0][0]['m_no'] === "QW2-$tag");

echo "\n== Readings\n";
$notesBefore = (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'QC' AND title LIKE 'QC reading out of range%'");
$r1 = step('a reading inside the limits', fn () => addRow($admin, $sheet, ['PIN' => '5.00', 'WIDTH' => '11.50', 'LEG' => '6.02', 'WEIGHT' => '28.7', 'FITTING' => 'OK']));
$g = Db::call('sp_qc_sheet_get', [$admin, $sheet]);
$row1 = $g[1][0] ?? [];
check('it is 1, not flagged, signed with the actor\'s name and timed now', (int) $row1['sr_no'] === 1 && (int) $row1['out_of_range'] === 0 && $row1['authorised_name'] === (string) one('SELECT name FROM users WHERE id = ?', [$admin])
    && abs(strtotime((string) $row1['measured_at']) - time()) < 120);
$v1 = [];
foreach ($g[3] as $v) {
    if ($v['row_id'] === $r1) {
        $v1[$v['field_code']] = $v['num_value'] ?? $v['text_value'];
    }
}
check('each value is stored against its field', count($v1) === 5 && (float) $v1['PIN'] === 5.0 && (float) $v1['WEIGHT'] === 28.7 && $v1['FITTING'] === 'OK');
$r2 = step('a pin size of 5.20 (limit 5.10)', fn () => addRow($admin, $sheet, ['PIN' => '5.20', 'WIDTH' => '11.5', 'LEG' => '6.0', 'WEIGHT' => '29.0'], date('Y-m-d H:i:s', strtotime('-30 minutes'))));
$rows = Db::call('sp_qc_sheet_get', [$admin, $sheet])[1];
$row2 = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $r2))[0] ?? [];
check('it is flagged for the pin only', (int) $row2['sr_no'] === 2 && (int) $row2['out_of_range'] === 1 && $row2['out_params'] === 'PIN');
check('QC and Production were alerted', (int) one("SELECT COUNT(*) FROM notifications WHERE source_type = 'QC' AND title LIKE 'QC reading out of range%'") === $notesBefore + 1);
$r3 = addRow($admin, $sheet, ['PIN' => '5.0', 'WIDTH' => '11.9', 'WEIGHT' => '27.5', 'FITTING' => 'NOT_OK']);
$row3 = array_values(array_filter(Db::call('sp_qc_sheet_get', [$admin, $sheet])[1], static fn (array $r): bool => $r['id'] === $r3))[0] ?? [];
check('width over, weight under and fitting not OK are all flagged', $row3['out_params'] === 'WIDTH,WEIGHT,FITTING');
$r4 = addRow($admin, $sheet, ['LEG' => '6.0']);
check('a reading with only a leg size is fine: the leg has no limit set', (int) one('SELECT out_of_range FROM qc_sheet_rows WHERE id = ?', [$r4]) === 0);
refuses('an empty reading is refused', fn () => addRow($admin, $sheet, []), 'at least one reading');
refuses('a code that is not on the sheet is ignored, leaving nothing to save', fn () => addRow($admin, $sheet, ['NOSUCH' => '5']), 'at least one reading');
refuses('a negative reading is refused', fn () => addRow($admin, $sheet, ['PIN' => '-1']), 'must be a number');
refuses('text in a number field is refused', fn () => addRow($admin, $sheet, ['PIN' => 'five']), 'must be a number');
refuses('an unknown fitting result is refused', fn () => addRow($admin, $sheet, ['PIN' => '5', 'FITTING' => 'MAYBE']), 'listed choices');
refuses('a time in the future is refused', fn () => addRow($admin, $sheet, ['PIN' => '5'], date('Y-m-d H:i:s', strtotime('+2 hours'))), 'future');
check('serial numbers run 1 to 4', implode(',', array_column(Db::call('sp_qc_sheet_get', [$admin, $sheet])[1], 'sr_no')) === '1,2,3,4');

echo "\n== A reading is a record\n";
refuses('editing a reading is refused', fn () => Db::pdo()->exec("UPDATE qc_sheet_rows SET out_params = 'X' WHERE id = '$r2'"), 'cannot be changed');
refuses('deleting a reading is refused', fn () => Db::pdo()->exec("DELETE FROM qc_sheet_rows WHERE id = '$r2'"), 'cannot be deleted');
refuses('editing a stored value is refused', fn () => Db::pdo()->exec("UPDATE qc_sheet_values SET num_value = 1 WHERE row_id = '$r2'"), 'cannot be changed');
refuses('deleting a stored value is refused', fn () => Db::pdo()->exec("DELETE FROM qc_sheet_values WHERE row_id = '$r2'"), 'cannot be deleted');
refuses('voiding needs a reason', fn () => Db::call('sp_qc_sheet_void_row', [$admin, $r2, '  ']), 'why');
step('a reading is voided with a reason', fn () => Db::call('sp_qc_sheet_void_row', [$admin, $r2, 'Typed 5.20 instead of 5.02']));
refuses('voiding twice is refused', fn () => Db::call('sp_qc_sheet_void_row', [$admin, $r2, 'again']), 'already void');
$rows = Db::call('sp_qc_sheet_get', [$admin, $sheet])[1];
check('the voided reading stays on the sheet with its reason', count($rows) === 4 && (int) $rows[1]['is_void'] === 1 && $rows[1]['void_reason'] === 'Typed 5.20 instead of 5.02');
$r5 = addRow($admin, $sheet, ['PIN' => '5.02', 'WIDTH' => '11.5', 'WEIGHT' => '28.8', 'FITTING' => 'OK']);
check('the next reading is 5: a voided number is never reused', (int) one('SELECT sr_no FROM qc_sheet_rows WHERE id = ?', [$r5]) === 5);
$list = Db::rows('sp_qc_sheet_list', [$admin, 'OPEN', null]);
$me = array_values(array_filter($list, static fn (array $r): bool => $r['id'] === $sheet))[0] ?? [];
check('the list counts readings without the void one, and the out-of-range ones', (int) ($me['readings'] ?? 0) === 4 && (int) ($me['out_of_range'] ?? 0) === 1, json_encode($me));
check('the list shows the machine', ($me['machine_code'] ?? '') === "QWS-$tag");
$byMachine = Db::rows('sp_qc_sheet_list', [$admin, null, $ws2]);
check('the list can be narrowed to one machine', count($byMachine) >= 1 && count(array_filter($byMachine, static fn (array $r): bool => $r['machine_code'] !== "QW2-$tag")) === 0
    && in_array($sheetDefault, array_column($byMachine, 'id'), true) && !in_array($sheet, array_column($byMachine, 'id'), true));

echo "\n== Adding to what is measured\n";
$thick = step('a new number field with limits is added', fn () => saveField($admin, null, "T$tag", 'Thickness', 'NUMBER', 'mm', null, null, 1, 60, 1));
$added[] = $thick;
$look = step('a choice field with faults is added', fn () => saveField($admin, null, "C$tag", 'Look', 'CHOICE', null, 'GOOD=Good;SCRATCH=Scratched;BAD=Bad', 'SCRATCH;BAD', 0, 61, 1));
$added[] = $look;
$note = step('a text field is added', fn () => saveField($admin, null, "N$tag", 'Remark', 'TEXT', null, null, null, 0, 62, 1));
$added[] = $note;
refuses('a code with spaces or small letters is refused', fn () => saveField($admin, null, 'bad code', 'X', 'NUMBER', null, null, null, 0, 0, 1), 'capital letters');
refuses('a code already used is refused', fn () => saveField($admin, null, 'PIN', 'Another pin', 'NUMBER', null, null, null, 0, 0, 1), 'already used');
refuses('a field with no name is refused', fn () => saveField($admin, null, "E$tag", '  ', 'NUMBER', null, null, null, 0, 0, 1), 'needs a name');
refuses('an unknown kind is refused', fn () => saveField($admin, null, "E$tag", 'X', 'PHOTO', null, null, null, 0, 0, 1), 'number, a choice or text');
refuses('a choice field with no list of choices is refused', fn () => saveField($admin, null, "E$tag", 'X', 'CHOICE', null, 'OK,NOT OK', null, 0, 0, 1), 'VALUE=Name');
$fieldsNow = array_column(Db::rows('sp_qc_field_list', [$admin]), null, 'code');
check('the new fields are listed, with the limits flag only on the number', isset($fieldsNow["T$tag"], $fieldsNow["C$tag"], $fieldsNow["N$tag"]) && (int) $fieldsNow["T$tag"]['has_limits'] === 1 && (int) $fieldsNow["C$tag"]['has_limits'] === 0);
step('the product gets a limit on the new field', fn () => Db::call('sp_item_spec_save', [$admin, $fg, 'Black', json_encode([
    ['parameter' => 'PIN', 'target' => 5, 'min' => 4.9, 'max' => 5.1], ['parameter' => 'WIDTH', 'max' => 11.8], ['parameter' => 'WEIGHT', 'min' => 28, 'max' => 30],
    ['parameter' => "T$tag", 'min' => 1.5, 'max' => 2.5]])]));
$sheetN = openSheet($admin, $jc1);
$ok = addRow($admin, $sheetN, ["T$tag" => '2.0', "C$tag" => 'GOOD', "N$tag" => 'first coil']);
$thin = addRow($admin, $sheetN, ["T$tag" => '1.2', "C$tag" => 'SCRATCH', "N$tag" => 'edge']);
$gN = Db::call('sp_qc_sheet_get', [$admin, $sheetN]);
$byId = array_column($gN[1], null, 'id');
check('a good thickness and look are not flagged', (int) $byId[$ok]['out_of_range'] === 0);
check('a thickness under its limit and a scratched look are both flagged', (int) $byId[$thin]['out_of_range'] === 1 && $byId[$thin]['out_params'] === "T$tag,C$tag", (string) $byId[$thin]['out_params']);
$texts = [];
foreach ($gN[3] as $v) {
    if ($v['row_id'] === $ok) {
        $texts[$v['field_code']] = $v['num_value'] ?? $v['text_value'];
    }
}
check('the text and the choice are stored as typed', $texts["N$tag"] === 'first coil' && $texts["C$tag"] === 'GOOD' && (float) $texts["T$tag"] === 2.0);
refuses('a field that has readings cannot change its kind', fn () => saveField($admin, $thick, "T$tag", 'Thickness', 'TEXT', null, null, null, 0, 60, 1), 'kind cannot change');
step('a field can be renamed and given another unit', fn () => saveField($admin, $thick, "T$tag", 'Gasket thickness', 'NUMBER', 'mm', null, null, 1, 60, 1));
check('the new name shows on the sheet fields', in_array('Gasket thickness', array_column(Db::call('sp_qc_sheet_get', [$admin, $sheetN])[4], 'label'), true));
step('a field can be switched off', fn () => saveField($admin, $note, "N$tag", 'Remark', 'TEXT', null, null, null, 0, 62, 0));
$sheetM = openSheet($admin, $jc1);
check('a switched-off field is gone from a new sheet but stays on the sheet that has readings for it', !in_array("N$tag", array_column(Db::call('sp_qc_sheet_get', [$admin, $sheetM])[4], 'code'), true)
    && in_array("N$tag", array_column(Db::call('sp_qc_sheet_get', [$admin, $sheetN])[4], 'code'), true));
refuses('a switched-off field is ignored when a reading is entered', fn () => addRow($admin, $sheetM, ["N$tag" => 'nothing else']), 'at least one reading');

echo "\n== The words on the sheet\n";
$labels = array_column(Db::rows('sp_qc_labels_list', [$admin]), null, 'label_key');
check('the paper sheet\'s words are there, the spare header fields hidden', $labels['DIE_NO']['label'] === 'Die no.' && $labels['FOOTER']['label'] === 'Label approved by :' && (int) $labels['X1']['is_visible'] === 0 && count($labels) >= 15);
step('a header word is renamed', fn () => Db::call('sp_qc_label_save', [$admin, 'DIE_NO', "Mould $tag", 1]));
step('a spare header field is renamed and shown', fn () => Db::call('sp_qc_label_save', [$admin, 'X1', 'Operator', 1]));
step('a header field is hidden', fn () => Db::call('sp_qc_label_save', [$admin, 'DTD', 'DTD', 0]));
$labels = array_column(Db::rows('sp_qc_labels_list', [$admin]), null, 'label_key');
check('the list shows the new words and visibility', $labels['DIE_NO']['label'] === "Mould $tag" && (int) $labels['X1']['is_visible'] === 1 && (int) $labels['DTD']['is_visible'] === 0);
Db::call('sp_qc_label_save', [$admin, 'SR_NO', 'No.', 0]);
check('the serial number column cannot be hidden', (int) array_column(Db::rows('sp_qc_labels_list', [$admin]), null, 'label_key')['SR_NO']['is_visible'] === 1);
refuses('an unknown label is refused', fn () => Db::call('sp_qc_label_save', [$admin, 'NOPE', 'x', 1]), 'Unknown label');
refuses('an empty label is refused', fn () => Db::call('sp_qc_label_save', [$admin, 'DIE_NO', '   ', 1]), 'cannot be empty');

echo "\n== Closing\n";
$empty = openSheet($admin, $jc1);
refuses('a sheet with no readings cannot be closed', fn () => Db::call('sp_qc_sheet_close', [$admin, $empty]), 'no readings');
step('the sheet is closed', fn () => Db::call('sp_qc_sheet_close', [$admin, $sheet]));
refuses('a reading cannot be added to a closed sheet', fn () => addRow($admin, $sheet, ['PIN' => '5']), 'closed');
refuses('a closed sheet cannot be closed again', fn () => Db::call('sp_qc_sheet_close', [$admin, $sheet]), 'already closed');
refuses('a reading cannot be voided on a closed sheet', fn () => Db::call('sp_qc_sheet_void_row', [$admin, $r1, 'late']), 'closed');
check('the list filters open and closed sheets', in_array($sheet, array_column(Db::rows('sp_qc_sheet_list', [$admin, 'CLOSED', null]), 'id'), true) && !in_array($sheet, array_column(Db::rows('sp_qc_sheet_list', [$admin, 'OPEN', null]), 'id'), true));

echo "\n== One sheet per machine: who is due a reading\n";
$board = array_column(Db::rows('sp_qc_machine_board', [$admin]), null, 'code');
check('every machine is on the board', isset($board["QWS-$tag"], $board["QW2-$tag"]));
check('a machine running a job, with an open sheet and a fresh reading, is not due', $board["QWS-$tag"]['job_card_id'] === $jc1 && (int) $board["QWS-$tag"]['due'] === 0 && $board["QWS-$tag"]['sheet_id'] !== null);
check('the board shows the batch and the readings on the open sheet', $board["QWS-$tag"]['batch_number'] === $batch && (int) $board["QWS-$tag"]['readings'] >= 0);
Db::pdo()->exec("UPDATE job_cards SET started_at = NOW() - INTERVAL 3 HOUR WHERE id = '$jc3'");
Db::pdo()->exec("UPDATE qc_sheets SET created_at = NOW() - INTERVAL 3 HOUR WHERE id = '$sheetDefault'");
$board = array_column(Db::rows('sp_qc_machine_board', [$admin]), null, 'code');
check('a machine whose job has run for hours with no reading is due', (int) $board["QW2-$tag"]['due'] === 1);
Db::call('sp_generate_alerts', []);
$n = (string) one("SELECT title FROM notifications WHERE source_type = 'QC' AND source_id = 'readings-due' AND status <> 'RESOLVED'");
check('one alert counts the running machines that are due a reading', preg_match('/^(\d+) running machines are due a QC reading$/', $n) === 1 && (int) $n >= 1, $n);
addRow($admin, $sheetDefault, ['PIN' => '5.0']);
$board = array_column(Db::rows('sp_qc_machine_board', [$admin]), null, 'code');
check('a reading on that machine\'s sheet clears it', (int) $board["QW2-$tag"]['due'] === 0 && (int) $board["QW2-$tag"]['readings'] === 1);

echo "\n== Who may do what\n";
$qcU = Db::callOut('sp_user_create', [$admin, "qcs.qc.$tag@test.local", "Quality Person $tag", Auth::hash('Quality-Pass-1234'), null, json_encode([$roles['QC']]), $ip])[0];
$opU = Db::callOut('sp_user_create', [$admin, "qcs.op.$tag@test.local", "Floor Operator $tag", Auth::hash('Operator-Pass-123'), null, json_encode([$roles['Operator']]), $ip])[0];
$salesU = Db::callOut('sp_user_create', [$admin, "qcs.sales.$tag@test.local", "Sales Person $tag", Auth::hash('Sales-Pass-12345'), null, json_encode([$roles['Sales']]), $ip])[0];
$mgrU = Db::callOut('sp_user_create', [$admin, "qcs.mgr.$tag@test.local", "Manager $tag", Auth::hash('Manager-Pass-1234'), null, json_encode([$roles['Manager']]), $ip])[0];
$s2 = step('QC can start a sheet', fn () => openSheet($qcU, $jc1));
$q1 = step('QC can add a reading', fn () => addRow($qcU, $s2, ['PIN' => '5.0']));
check('the reading is signed with the QC person\'s name, not the admin\'s', one('SELECT authorised_name FROM qc_sheet_rows WHERE id = ?', [$q1]) === "Quality Person $tag");
step('QC can void a reading and close the sheet', function () use ($qcU, $s2, $q1) {
    Db::call('sp_qc_sheet_void_row', [$qcU, $q1, 'test']);
    addRow($qcU, $s2, ['PIN' => '5.0']);
    Db::call('sp_qc_sheet_close', [$qcU, $s2]);
});
step('QC can read the limits, the fields, the labels and the machine board', function () use ($qcU, $fg) {
    Db::rows('sp_qc_spec_list', [$qcU, $fg]);
    Db::rows('sp_qc_field_list', [$qcU]);
    Db::rows('sp_qc_labels_list', [$qcU]);
    Db::rows('sp_qc_machine_board', [$qcU]);
});
step('QC can shape the sheet (that is QC update)', function () use ($qcU, $tag, &$added) {
    $added[] = saveField($qcU, null, "Q$tag", 'QC own field', 'NUMBER', null, null, null, 0, 70, 1);
    Db::call('sp_qc_label_save', [$qcU, 'COLOUR', 'Colour', 1]);
});
refuses('QC cannot change the limits (that is Items)', fn () => Db::call('sp_item_spec_save', [$qcU, $fg, 'Red', json_encode([])]), 'Forbidden');
check('the colour is unchanged', one('SELECT colour FROM items WHERE id = ?', [$fg]) === 'Black');
$s3 = openSheet($admin, $jc1);
foreach ([['Operator', $opU], ['Sales', $salesU]] as [$name, $u]) {
    refuses("$name cannot read sheets", fn () => Db::rows('sp_qc_sheet_list', [$u, null, null]), 'Forbidden');
    refuses("$name cannot read one sheet", fn () => Db::call('sp_qc_sheet_get', [$u, $s3]), 'Forbidden');
    refuses("$name cannot start a sheet", fn () => openSheet($u, $jc1), 'Forbidden');
    refuses("$name cannot add a reading", fn () => addRow($u, $s3, ['PIN' => '5']), 'Forbidden');
    refuses("$name cannot see the job card list", fn () => Db::rows('sp_qc_job_card_pick', [$u]), 'Forbidden');
    refuses("$name cannot see the machine board", fn () => Db::rows('sp_qc_machine_board', [$u]), 'Forbidden');
    refuses("$name cannot change what is measured", fn () => saveField($u, null, "Z$tag", 'Z', 'NUMBER', null, null, null, 0, 0, 1), 'Forbidden');
    refuses("$name cannot rename a header word", fn () => Db::call('sp_qc_label_save', [$u, 'DIE_NO', 'Hacked', 1]), 'Forbidden');
}
step('Manager can read sheets, fields and the board', function () use ($mgrU) {
    Db::rows('sp_qc_sheet_list', [$mgrU, null, null]);
    Db::rows('sp_qc_field_list', [$mgrU]);
    Db::rows('sp_qc_machine_board', [$mgrU]);
});
refuses('Manager cannot add a reading (read only)', fn () => addRow($mgrU, $s3, ['PIN' => '5']), 'Forbidden');
refuses('Manager cannot close a sheet', fn () => Db::call('sp_qc_sheet_close', [$mgrU, $s3]), 'Forbidden');
refuses('Manager cannot change what is measured', fn () => saveField($mgrU, null, "Y$tag", 'Y', 'NUMBER', null, null, null, 0, 0, 1), 'Forbidden');
check('the audit log has the sheet and setup events', (int) one("SELECT COUNT(*) FROM audit_logs WHERE event IN ('QC_SHEET_OPENED','QC_SHEET_CLOSED','QC_READING_VOIDED','QC_FIELD_SAVED','QC_LABEL_SAVED') AND user_id = ?", [$admin]) >= 5);

echo "\n== Put the setup back\n";
foreach ($added as $id) {
    $f = Db::pdo()->query("SELECT code, label, kind, unit, choices, bad_values, has_limits, sort_no FROM qc_fields WHERE id = '$id'")->fetch(PDO::FETCH_ASSOC);
    if ($f) {
        saveField($admin, $id, $f['code'], $f['label'], $f['kind'], $f['unit'], $f['choices'], $f['bad_values'], (int) $f['has_limits'], (int) $f['sort_no'], 0);
    }
}
foreach (['DIE_NO', 'X1', 'DTD', 'SR_NO', 'COLOUR'] as $k) {
    Db::call('sp_qc_label_save', [$admin, $k, $origLabels[$k]['label'], (int) $origLabels[$k]['is_visible']]);
}
check('the fields this test added are off again and the header words are back', (int) one('SELECT COUNT(*) FROM qc_fields WHERE is_active = 1') <= 12
    && array_column(Db::rows('sp_qc_labels_list', [$admin]), null, 'label_key')['DIE_NO']['label'] === $origLabels['DIE_NO']['label']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
