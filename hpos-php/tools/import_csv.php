<?php
declare(strict_types=1);

/**
 * Loads master data from a CSV file through the same stored procedures the screens use, so every
 * rule (unique codes, GSTIN format, permissions, audit trail) applies. A dry run is the default.
 *
 *   php tools/import_csv.php customers customers.csv            check the file, change nothing
 *   php tools/import_csv.php customers customers.csv --commit   really import
 *   php tools/import_csv.php items items.csv --commit --update  also update rows that already exist
 *
 * Types and their columns (first row is the header, order does not matter, extra columns are ignored):
 *   customers  name*, gstin, email, phone                                   (existing = same name)
 *   suppliers  name*, gstin, email, phone, status (ACTIVE/INACTIVE)         (existing = same name)
 *   items      code*, name*, category* (RAW_MATERIAL, FINISHED_GOODS, PACKAGING, SCRAP), uom*,
 *              hsn_code, standard_cost, min_stock_level                     (existing = same code)
 *
 * Runs as the seeded admin account (admin@himalayaplast.local), or as --as=email.
 * Exit code 1 when any row is rejected, so a script can stop on it.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AppError;
use App\Core\Db;

$type = $argv[1] ?? '';
$file = $argv[2] ?? '';
$commit = in_array('--commit', $argv, true);
$update = in_array('--update', $argv, true);
$as = 'admin@himalayaplast.local';
foreach ($argv as $a) {
    if (str_starts_with($a, '--as=')) {
        $as = substr($a, 5);
    }
}
$specs = [
    'customers' => ['required' => ['name'],                          'optional' => ['gstin', 'email', 'phone']],
    'suppliers' => ['required' => ['name'],                          'optional' => ['gstin', 'email', 'phone', 'status']],
    'items'     => ['required' => ['code', 'name', 'category', 'uom'], 'optional' => ['hsn_code', 'standard_cost', 'min_stock_level']],
];
if (!isset($specs[$type]) || !is_file($file)) {
    fwrite(STDERR, "usage: php tools/import_csv.php <customers|suppliers|items> <file.csv> [--commit] [--update] [--as=email]\n");
    exit(2);
}

$actor = Db::row('sp_auth_get_user_by_email', [strtolower($as)]);
if (!$actor || (int) $actor['is_active'] !== 1) {
    fwrite(STDERR, "User $as was not found or is inactive.\n");
    exit(2);
}
$uid = (string) $actor['id'];

$fh = fopen($file, 'rb');
$first = fgets($fh);
rewind($fh);
if (str_starts_with((string) $first, "\xEF\xBB\xBF")) {
    fread($fh, 3);   // Excel's byte-order mark
}
$delimiter = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
$header = fgetcsv($fh, 0, $delimiter, '"', '\\') ?: [];
$cols = array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);
$missing = array_diff($specs[$type]['required'], $cols);
if ($missing) {
    fwrite(STDERR, 'The header row is missing: ' . implode(', ', $missing) . ' (found: ' . implode(', ', $cols) . ")\n");
    exit(2);
}

// what already exists, to tell creates from updates (needs the right to read that master)
$existing = [];
try {
    if ($type === 'customers') {
        foreach (Db::rows('sp_customer_list', [$uid]) as $r) { $existing[strtolower($r['name'])] = $r['id']; }
    } elseif ($type === 'suppliers') {
        foreach (Db::rows('sp_supplier_list', [$uid]) as $r) { $existing[strtolower($r['name'])] = $r['id']; }
    } else {
        foreach (Db::rows('sp_item_list', [$uid, null]) as $r) { $existing[strtolower($r['code'])] = $r['id']; }
    }
} catch (AppError $e) {
    fwrite(STDERR, "User $as may not import $type: " . $e->getMessage() . "\n");
    exit(1);
}

$gstFormat = '/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/';
$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0];
$seen = [];
$line = 1;
while (($raw = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
    $line++;
    if ($raw === [null] || !array_filter($raw, static fn ($v): bool => trim((string) $v) !== '')) {
        continue;   // blank line
    }
    $row = [];
    foreach ($cols as $i => $name) {
        $row[$name] = trim((string) ($raw[$i] ?? ''));
    }
    $reject = static function (string $why) use (&$counts, $line): void {
        $counts['rejected']++;
        echo "  line $line: REJECTED - $why\n";
    };

    foreach ($specs[$type]['required'] as $req) {
        if (($row[$req] ?? '') === '') {
            $reject("$req is empty");
            continue 2;
        }
    }
    $key = strtolower($type === 'items' ? $row['code'] : $row['name']);
    if (isset($seen[$key])) {
        $reject("'" . ($type === 'items' ? $row['code'] : $row['name']) . "' appears twice in the file (first on line {$seen[$key]})");
        continue;
    }
    $seen[$key] = $line;

    $id = $existing[$key] ?? null;
    if ($id !== null && !$update) {
        $counts['skipped']++;
        echo "  line $line: skipped - already exists\n";
        continue;
    }

    try {
        if ($type === 'customers' || $type === 'suppliers') {
            $gstin = strtoupper($row['gstin'] ?? '');
            if ($gstin !== '' && !preg_match($gstFormat, $gstin)) {
                throw new AppError("GSTIN '$gstin' is not in the 15-character format");
            }
            $email = $row['email'] ?? '';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new AppError("email '$email' does not look right");
            }
            if ($type === 'customers') {
                $args = [$uid, $id, $row['name'], $gstin ?: null, $email ?: null, ($row['phone'] ?? '') ?: null];
                if ($commit) { Db::callOut('sp_customer_save', $args); }
            } else {
                $status = strtoupper($row['status'] ?? '') ?: 'ACTIVE';
                if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                    throw new AppError("status '$status' must be ACTIVE or INACTIVE");
                }
                $args = [$uid, $id, $row['name'], $gstin ?: null, $email ?: null, ($row['phone'] ?? '') ?: null, $status];
                if ($commit) { Db::callOut('sp_supplier_save', $args); }
            }
        } else {
            $cat = strtoupper($row['category']);
            if (!in_array($cat, ['RAW_MATERIAL', 'FINISHED_GOODS', 'PACKAGING', 'SCRAP'], true)) {
                throw new AppError("category '$cat' is not one of RAW_MATERIAL, FINISHED_GOODS, PACKAGING, SCRAP");
            }
            foreach (['standard_cost', 'min_stock_level'] as $num) {
                $v = $row[$num] ?? '';
                if ($v !== '' && (!is_numeric($v) || (float) $v < 0)) {
                    throw new AppError("$num '$v' must be a number, zero or more");
                }
            }
            $args = [$uid, $id, strtoupper($row['code']), $row['name'], $cat, $row['uom'], ($row['hsn_code'] ?? '') ?: null,
                ($row['standard_cost'] ?? '') === '' ? 0 : $row['standard_cost'], ($row['min_stock_level'] ?? '') === '' ? 0 : $row['min_stock_level'], null];
            if ($commit) { Db::callOut('sp_item_save', $args); }
        }
        $counts[$id === null ? 'created' : 'updated']++;
    } catch (AppError $e) {
        $reject($e->getMessage());
    } catch (\Throwable $e) {
        $reject($e->getMessage());
    }
}
fclose($fh);

printf("\n%s: %d %s, %d %s, %d skipped, %d rejected. %s\n",
    $type, $counts['created'], $commit ? 'created' : 'would be created', $counts['updated'], $commit ? 'updated' : 'would be updated',
    $counts['skipped'], $counts['rejected'],
    $commit ? 'Committed.' : 'Dry run: nothing was changed. Add --commit to import.');
exit($counts['rejected'] > 0 ? 1 : 0);
