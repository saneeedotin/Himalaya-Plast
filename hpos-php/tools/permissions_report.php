<?php
declare(strict_types=1);

/**
 * Prints who can do what, as a Markdown table, straight from the roles and permissions in the database.
 * Review it with the client, then regenerate docs/PERMISSIONS.md after any change:
 *
 *   php tools/permissions_report.php > docs/PERMISSIONS.md
 *
 * Reads the tables directly, so it needs an account with SELECT (the admin/loader account, not the
 * EXECUTE-only app account).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;

$rows = Db::pdo()->query(
    "SELECT r.name AS role, p.resource, p.action
       FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
      ORDER BY r.name, p.resource, p.action"
)->fetchAll();

$roles = [];
$resources = [];
foreach ($rows as $r) {
    $roles[$r['role']][$r['resource']][] = $r['action'];
    $resources[$r['resource']] = true;
}
unset($resources['*']);
ksort($resources);
$letters = ['read' => 'R', 'create' => 'C', 'update' => 'U', 'delete' => 'D', 'scan' => 'S', '*' => '*'];

echo "# Who can do what\n\n";
echo "Generated from the database by `php tools/permissions_report.php`. **R** read, **C** create, **U** update, **D** delete, **S** scan.\n";
echo "The database checks every one of these on every call; the menu only hides links.\n\n";
echo '| Role | ' . implode(' | ', array_keys($resources)) . " |\n";
echo '|---|' . str_repeat('---|', count($resources)) . "\n";
foreach ($roles as $role => $perms) {
    $cells = [];
    foreach (array_keys($resources) as $res) {
        $acts = $perms['*'] ?? [];
        $acts = array_merge($acts, $perms[$res] ?? []);
        if (in_array('*', $perms['*'] ?? [], true)) {
            $cells[] = 'all';
            continue;
        }
        $cells[] = $acts ? implode('', array_map(static fn ($a): string => $letters[$a] ?? $a, array_unique($acts))) : '';
    }
    echo "| $role | " . implode(' | ', $cells) . " |\n";
}

echo "\n## Things worth a second look\n\n";
$notes = [];
foreach ($roles as $role => $perms) {
    if ($role !== 'Admin' && isset($perms['users'])) {
        $notes[] = "$role can manage users: " . implode(', ', $perms['users']);
    }
    if ($role !== 'Admin' && in_array('delete', $perms['Accounts'] ?? [], true)) {
        $notes[] = "$role can cancel invoices and payments (Accounts delete).";
    }
}
echo $notes ? '- ' . implode("\n- ", $notes) . "\n" : "Nothing unexpected: only Admin manages users, and only Accounts and Admin can cancel invoices and payments.\n";
echo "\nAdmin holds the wildcard (every resource, every action), including users and settings.\n";
