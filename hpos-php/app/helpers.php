<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;

/** Escape for HTML output. Every template value goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** URL prefix of the folder that holds index.php, so the app also works in a sub-folder. */
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
}

function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    // The response is complete: release the session lock and let the browser follow the redirect while anything
    // left to do (Web Push for what this request raised) runs after it.
    header('Content-Length: 0');
    header('Connection: close');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (PHP_SAPI !== 'cli') {
        flush();
    }
    exit;
}

function can(string $resource, string $action = 'read'): bool
{
    return Auth::can($resource, $action);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/**
 * Labelled text input. $o: type, required, hint, maxlength, step, min, readonly, autocomplete.
 */
function field(string $name, string $label, mixed $value = '', array $o = []): string
{
    $repeated = str_ends_with($name, '[]');
    $id = 'f_' . $name;
    $attrs = '';
    foreach (['maxlength', 'step', 'min', 'max', 'autocomplete'] as $a) {
        if (isset($o[$a])) {
            $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
        }
    }
    foreach (['required', 'readonly'] as $flag) {
        if (!empty($o[$flag])) {
            $attrs .= ' ' . $flag;
        }
    }
    $hint = isset($o['hint']) ? '<p class="hint">' . e($o['hint']) . '</p>' : '';
    $text = e($label) . (!empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '');
    $input = '<input' . ($repeated ? '' : ' id="' . e($id) . '"') . ' name="' . e($name) . '" type="' . e($o['type'] ?? 'text') . '" value="' . e($value) . '"' . $attrs . '>';
    return ($repeated ? '<label>' . $text . $input . '</label>' : '<label for="' . e($id) . '">' . $text . '</label>' . $input) . $hint;
}

/** Labelled textarea. */
function textarea_field(string $name, string $label, mixed $value = '', array $o = []): string
{
    $id = 'f_' . $name;
    return '<label for="' . e($id) . '">' . e($label) . '</label>'
        . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 3) . '"' . (isset($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '') . '>' . e($value) . '</textarea>'
        . (isset($o['hint']) ? '<p class="hint">' . e($o['hint']) . '</p>' : '');
}

/**
 * Labelled select. $options is value => label.
 * $o: required, blank (text of an empty first option), empty => [path, text] (a link shown when there is nothing to choose,
 * e.g. ['/customers/new', 'Add a customer']).
 */
function select_field(string $name, string $label, mixed $value, array $options, array $o = []): string
{
    $repeated = str_ends_with($name, '[]');
    $id = 'f_' . $name;
    $select = '<select' . ($repeated ? '' : ' id="' . e($id) . '"') . ' name="' . e($name) . '"' . (!empty($o['required']) ? ' required' : '') . '>';
    if (!empty($o['blank'])) {
        $select .= '<option value="">' . e($o['blank']) . '</option>';
    }
    foreach ($options as $v => $text) {
        $select .= '<option value="' . e($v) . '"' . ((string) $v === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    $select .= '</select>';
    // a form that cannot be filled in because the list is empty should say what to do about it
    $none = (!$options && isset($o['empty']) && can_any_create($o['empty'][0]))
        ? '<p class="hint">Nothing to choose from yet. <a href="' . e(url($o['empty'][0])) . '">' . e($o['empty'][1]) . '</a>.</p>' : '';
    return ($repeated ? '<label>' . e($label) . $select . '</label>' : '<label for="' . e($id) . '">' . e($label) . '</label>' . $select) . $none;
}

/** True when the signed-in user may open this "new ..." page (so a link to it is worth showing). */
function can_any_create(string $path): bool
{
    static $needs = [
        '/customers/new' => ['Customers', 'create'], '/suppliers/new' => ['Suppliers', 'create'], '/items/new' => ['Items', 'create'],
        '/workstations/new' => ['Workstations', 'create'], '/boms/new' => ['BOMs', 'create'], '/orders/new' => ['SalesOrders', 'create'],
        '/purchase-orders/new' => ['PurchaseOrders', 'create'], '/work-orders/new' => ['WorkOrders', 'create'],
        '/invoices/new' => ['Accounts', 'create'], '/stock/adjust' => ['Stock', 'create'], '/dispatch/new' => ['Dispatch', 'create'],
    ];
    return isset($needs[$path]) ? can($needs[$path][0], $needs[$path][1]) : true;
}

/** A one-button POST form (delete, activate...). Asks first when $confirm is given. */
function action_form(string $path, string $label, ?string $confirm = null, string $class = 'btn'): string
{
    return '<form method="post" action="' . e(url($path)) . '" class="inline"' . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . App\Core\Csrf::field()
        . '<button class="' . e($class) . '" type="submit">' . e($label) . '</button></form>';
}

/** DATETIME from the DB to the value a datetime-local input wants. */
function dt_input(mixed $v): string
{
    return $v ? str_replace(' ', 'T', substr((string) $v, 0, 16)) : '';
}

/** 5.000000 -> 5, 0.0250 -> 0.025. For numbers the DB returns padded to their column scale. */
function trim_zeros(mixed $v): string
{
    $s = (string) ($v ?? '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** Short date-time for tables. */
function fmt_dt(mixed $v): string
{
    return $v ? substr((string) $v, 0, 16) : '';
}

function fmt_num(mixed $v, int $dp = 2): string
{
    return $v === null || $v === '' ? '' : number_format((float) $v, $dp);
}

/** First day of the financial year (1 April) that a date falls in; today by default. */
function fy_start(?string $date = null): string
{
    $t = $date !== null && strtotime($date) !== false ? strtotime($date) : time();
    $year = (int) date('Y', $t);
    return ((int) date('n', $t) >= 4 ? $year : $year - 1) . '-04-01';
}

/** A signed debit-minus-credit amount as "1,234.00 Dr" or "1,234.00 Cr" ("0.00" for nothing). */
function drcr(mixed $signed, int $dp = 2): string
{
    $n = round((float) $signed, $dp);
    if ($n === 0.0) {
        return number_format(0, $dp);
    }
    return number_format(abs($n), $dp) . ($n > 0 ? ' Dr' : ' Cr');
}

/**
 * Filter row for a report: a from/to period (or one "as on" date) and a link to download the same report as CSV.
 * $o: from, to, asof (use one or the other), keep (other query values to carry, such as a tab or a party).
 */
function period_form(string $path, array $o): string
{
    $keep = $o['keep'] ?? [];
    $html = '<form method="get" action="' . e(url($path)) . '" class="filter-row">';
    foreach ($keep as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    if (isset($o['asof'])) {
        $html .= '<div>' . field('asof', 'As on', $o['asof'], ['type' => 'date']) . '</div>';
        $dates = ['asof' => $o['asof']];
    } else {
        $html .= '<div>' . field('from', 'From', $o['from'], ['type' => 'date']) . '</div><div>' . field('to', 'To', $o['to'], ['type' => 'date']) . '</div>';
        $dates = ['from' => $o['from'], 'to' => $o['to']];
    }
    $csv = $path . '?' . http_build_query(array_filter($keep + $dates + ['format' => 'csv'], static fn ($v) => $v !== '' && $v !== null));
    return $html . '<button class="btn" type="submit">Show</button><a class="btn" href="' . e(url($csv)) . '">' . icon('download') . 'Download CSV</a></form>';
}

/**
 * One line of a journal or opening-balance form: account, customer or supplier (only needed for debtors and creditors),
 * debit, credit and a note. $accounts is id => ['label' => ..., 'sub' => ...].
 * @param array<string,string> $customers  "CUSTOMER:<id>" => name
 * @param array<string,string> $suppliers  "SUPPLIER:<id>" => name
 */
function journal_line(array $l, array $accounts, array $customers, array $suppliers): string
{
    $labels = array_map(static fn (array $a): string => $a['label'], $accounts);
    $party = '<label>Customer or supplier<select name="party[]"><option value="">None</option>';
    foreach (['Customers' => $customers, 'Suppliers' => $suppliers] as $group => $list) {
        $party .= '<optgroup label="' . e($group) . '">';
        foreach ($list as $v => $name) {
            $party .= '<option value="' . e($v) . '"' . ((string) $v === (string) ($l['party'] ?? '') ? ' selected' : '') . '>' . e($name) . '</option>';
        }
        $party .= '</optgroup>';
    }
    $party .= '</select></label>';
    return '<div class="journal-line">'
        . '<div>' . select_field('account[]', 'Account', $l['account'] ?? '', $labels, ['blank' => 'Choose an account']) . '</div>'
        . '<div>' . $party . '</div>'
        . '<div>' . field('debit[]', 'Debit', $l['debit'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0']) . '</div>'
        . '<div>' . field('credit[]', 'Credit', $l['credit'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0']) . '</div>'
        . '<div>' . field('note[]', 'Note', $l['note'] ?? '', ['maxlength' => 200]) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}

/** Inline SVG icon (Lucide shapes, drawn here so no script or font download is needed). */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'layout-grid' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'shopping-cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'shopping-bag' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'box' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'cpu' => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2M15 20v2M2 15h2M2 9h2M20 15h2M20 9h2M9 2v2M9 20v2"/>',
        'activity' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'arrow-right-left' => '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
        'workflow' => '<rect width="8" height="8" x="3" y="3" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect width="8" height="8" x="13" y="13" rx="2"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'qr-code' => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'truck' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'server' => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
        'file-spreadsheet' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2M14 13h2M8 17h2M14 17h2"/>',
        'layers' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2m-7.07-17.07 1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu' => '<path d="M4 12h16M4 6h16M4 18h16"/>',
        'plus' => '<path d="M5 12h14M12 5v14"/>',
        'scan-line' => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
        'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'sliders' => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'book-open' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
        'flask' => '<path d="M4.5 3h15"/><path d="M6 3v16a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V3"/><path d="M6 14h12"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'lightbulb' =>'<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
        'repeat' =>'<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'calendar' =>'<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'landmark' =>'<path d="M10 18v-7"/><path d="M11.12 2.198a2 2 0 0 1 1.76.006l7.866 3.847c.476.233.31.949-.22.949H3.474c-.53 0-.695-.716-.22-.949z"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>',
        'scale' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
        'bar-chart' =>'<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'percent' => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'list' => '<path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13"/>',
        'file-minus' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M9 15h6"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    ];
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['box']) . '</svg>';
}

/** "Chrome on Windows" from a User-Agent string; only a rough label for the sign-in list. */
function device_label(?string $ua): string
{
    $ua = (string) $ua;
    if ($ua === '') {
        return 'Unknown device';
    }
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    return $os !== '' ? $browser . ' on ' . $os : $browser;
}

/** Two-letter initials for the avatar circle. */
function initials(?string $name): string
{
    $out = '';
    foreach (preg_split('/\s+/', trim((string) $name)) ?: [] as $part) {
        if ($part !== '') {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return mb_substr($out !== '' ? $out : '?', 0, 2);
}

/** Coloured status label. */
function pill(string $text, string $kind = 'neutral'): string
{
    return '<span class="pill pill-' . e($kind) . '">' . e($text) . '</span>';
}

/** 123456.50 -> "One Lakh Twenty Three Thousand Four Hundred Fifty Six Rupees and Fifty Paise Only" (Indian grouping). */
function amount_in_words(float|string $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
        'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $below1000 = static function (int $n) use ($ones, $tens): string {
        $w = [];
        if ($n >= 100) {
            $w[] = $ones[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $w[] = $tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        } elseif ($n > 0) {
            $w[] = $ones[$n];
        }
        return implode(' ', $w);
    };
    $paiseTotal = (int) round(((float) $amount) * 100);
    $rupees = intdiv($paiseTotal, 100);
    $paise = $paiseTotal % 100;
    if ($rupees === 0 && $paise === 0) {
        return 'Zero Rupees Only';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$unit, $name]) {
        if ($rupees >= $unit) {
            $parts[] = $below1000(intdiv($rupees, $unit)) . ' ' . $name;
            $rupees %= $unit;
        }
    }
    if ($rupees > 0) {
        $parts[] = $below1000($rupees);
    }
    $words = $parts ? implode(' ', $parts) . ' Rupees' : '';
    if ($paise > 0) {
        $words .= ($words ? ' and ' : '') . $below1000($paise) . ' Paise';
    }
    return $words . ' Only';
}

/**
 * Unread alerts for the header bell. Remembered in the session for a minute so every page
 * does not ask the database; actions that change alerts clear it.
 */
function unread_notifications(): int
{
    $hit = $_SESSION['unread'] ?? null;
    if (is_array($hit) && ($hit['at'] ?? 0) > time() - 60 && ($hit['user'] ?? '') === Auth::id()) {
        return (int) $hit['n'];
    }
    try {
        $n = (int) (App\Core\Db::row('sp_notification_unread_count', [Auth::id()])['unread'] ?? 0);
    } catch (Throwable) {
        return 0;
    }
    $_SESSION['unread'] = ['n' => $n, 'at' => time(), 'user' => Auth::id()];
    return $n;
}

/** Pill for a document status (orders, work orders, invoices...). Unknown statuses stay neutral. */
function status_pill(?string $status): string
{
    static $kind = [
        'DRAFT' => 'warn', 'PROFORMA_SENT' => 'warn', 'PENDING' => 'warn', 'UNPAID' => 'warn', 'PARTIAL' => 'warn',
        'CONFIRMED' => 'ok', 'APPROVED' => 'ok', 'IN_PRODUCTION' => 'ok', 'IN_PROGRESS' => 'ok', 'ACTIVE' => 'ok',
        'READY_TO_DISPATCH' => 'ok', 'DISPATCH_PREP' => 'ok', 'DISPATCHED' => 'ok', 'COMPLETED' => 'ok', 'PAID' => 'ok',
        'PASS' => 'ok', 'AVAILABLE' => 'ok', 'CONSUMED' => 'neutral', 'RESERVED' => 'ok', 'QUEUED' => 'neutral',
        'CANCELLED' => 'fail', 'DEFICIT' => 'fail', 'REJECT' => 'fail', 'SCRAP' => 'fail',
    ];
    $status = (string) $status;
    return pill(ucfirst(strtolower(str_replace('_', ' ', $status))), $kind[$status] ?? 'info');
}

/** Pill for an order's delivery health from v_order_projection (READY, ON_TRACK, AT_RISK, LATE). */
function health_pill(?string $health): string
{
    static $map = ['LATE' => ['Will miss the date', 'fail'], 'AT_RISK' => ['No slack', 'warn'], 'ON_TRACK' => ['On track', 'ok'], 'READY' => ['Made', 'ok']];
    $m = $map[(string) $health] ?? null;
    return $m ? pill($m[0], $m[1]) : '';
}

/** A sentence that explains an order projection row: what the date is based on and what holds it up. */
function projection_reason(array $p): string
{
    if ($p['basis'] === 'DONE') {
        return 'Everything on the order is made.';
    }
    if ($p['basis'] === 'PLANNED') {
        return 'The jobs are planned on the planning board until ' . substr((string) $p['projected_finish'], 0, 10) . '.';
    }
    $s = 'About ' . fmt_num($p['remaining_metres'], 0) . ' m left at roughly ' . fmt_num($p['rate_per_day'], 0) . ' m a day is ' . (int) $p['days_needed'] . ' day' . ((int) $p['days_needed'] === 1 ? '' : 's')
        . ', starting ' . substr((string) $p['start_date'], 0, 10) . ' plus a spare day.';
    if ((int) $p['material_wait_days'] > 0) {
        $s .= ' Raw material is short, so allow ' . (int) $p['material_wait_days'] . ' days for supply first.';
    }
    return $s;
}

/** Pill for a customer's reorder status from v_customer_reorder (NONE, NEW, ON_TRACK, SOON, DUE, QUIET). */
function reorder_pill(?string $status): string
{
    static $map = [
        'DUE' => ['Due to reorder', 'fail'], 'QUIET' => ['Gone quiet', 'fail'], 'SOON' => ['Reorder soon', 'warn'],
        'ON_TRACK' => ['On track', 'ok'], 'NEW' => ['Too few orders', 'neutral'], 'NONE' => ['No orders', 'neutral'],
    ];
    $m = $map[(string) $status] ?? null;
    return $m ? pill($m[0], $m[1]) : '';
}

/** Client IP as seen by PHP. Behind a proxy this is the proxy; configure the host accordingly. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function client_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}
