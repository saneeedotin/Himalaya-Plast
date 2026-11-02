<?php
declare(strict_types=1);

/**
 * Fills a database with a believable demo factory, so a walkthrough or screen recording has something to show:
 * machines, customers (one in another state), suppliers, raw materials, finished goods with recipes, opening
 * stock, a purchase order, and orders at every stage (draft, confirmed, in production, dispatched and invoiced
 * with a part payment). It uses the stored procedures the app uses, so every rule applies.
 *
 *   php tools/demo_seed.php [--as=admin@example.com]
 *
 * Refuses to run unless the database name ends in _demo, _scratch or _test, or if the database already has customers.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

$dbName = (string) Config::get('db.name');
if (!preg_match('/_(demo|scratch|test)$/', $dbName)) {
    fwrite(STDERR, "Refusing to run against '$dbName'. Use a database ending in _demo, _scratch or _test.\n");
    exit(2);
}

$as = 'admin@himalayaplast.local';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--as=')) {
        $as = substr($a, 5);
    }
}
$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
if ($as === 'admin@himalayaplast.local' && $devAdmin !== '') {
    $as = $devAdmin;
}
$admin = (string) (Db::row('sp_auth_get_user_by_email', [$as])['id'] ?? '');
if ($admin === '') {
    fwrite(STDERR, "No user $as. Pass --as=<an administrator's email>.\n");
    exit(2);
}
if ((int) Db::pdo()->query('SELECT COUNT(*) FROM customers')->fetchColumn() > 0) {
    fwrite(STDERR, "This database already has customers. The demo seed is for an empty one.\n");
    exit(2);
}

$ip = '127.0.0.1';
$say = static function (string $s): void { echo $s, "\n"; };
$id = static fn (string $proc, array $args): string => (string) Db::callOut($proc, $args)[0];
$item = static fn (string $code, string $name, string $cat, string $uom, ?string $hsn, float $cost, float $min): string => $id('sp_item_save', [$admin, null, $code, $name, $cat, $uom, $hsn, $cost, $min, null]);
$due = static fn (int $days): string => date('Y-m-d H:i:s', strtotime("+$days days"));
$lines = static fn (array $l): string => json_encode($l);

$say('Machines');
$ws1 = $id('sp_workstation_save', [$admin, null, 'EXT-01', 'Extruder Line 1', 850, 'IDLE']);
$ws2 = $id('sp_workstation_save', [$admin, null, 'EXT-02', 'Extruder Line 2', 780, 'IDLE']);
$id('sp_workstation_save', [$admin, null, 'EXT-03', 'Extruder Line 3 (small profiles)', 600, 'IDLE']);

$say('Customers and suppliers');
$cAlpha = $id('sp_customer_save', [$admin, null, 'Alpha Auto Components', '24AABCA1234F1Z5', 'purchase@alphaauto.example', '+91 98250 11111']);
$cDelhi = $id('sp_customer_save', [$admin, null, 'Delhi Door Systems', '07AADCD5678K1Z2', 'orders@delhidoors.example', '011 4567 8910']);
$cSun = $id('sp_customer_save', [$admin, null, 'Sunrise Windows Pvt Ltd', '24AAECS9012M1Z8', 'accounts@sunrisewin.example', '0261 255 0000']);
$sPoly = $id('sp_supplier_save', [$admin, null, 'Polymer Supplies Ltd', '24AAACP4321Q1Z7', 'sales@polymersupplies.example', '0261 222 3333', 'ACTIVE']);
$sColor = $id('sp_supplier_save', [$admin, null, 'ColourChem Industries', '27AAACC8765R1Z3', 'info@colourchem.example', '022 2400 1000', 'ACTIVE']);

$say('Items and recipes');
$rmPvc = $item('RM-PVC', 'PVC Compound K67', 'RAW_MATERIAL', 'Kg', '3904', 95, 300);
$rmCb = $item('RM-CARBON', 'Carbon Black Masterbatch', 'RAW_MATERIAL', 'Kg', '3206', 120, 50);
$rmPlast = $item('RM-PLAST', 'Plasticiser DOP', 'RAW_MATERIAL', 'Kg', '2917', 140, 80);
$fgGasket = $item('FG-GASKET-A101', 'Door Gasket A101', 'FINISHED_GOODS', 'Meter', '3926', 40, 0);
$fgBead = $item('FG-GLAZ-C303', 'Glazing Bead C303', 'FINISHED_GOODS', 'Meter', '3926', 55, 0);
$fgSeal = $item('FG-SEAL-B202', 'Weatherseal B202', 'FINISHED_GOODS', 'Meter', '3926', 32, 0);
$item('PK-CARTON-5', 'Carton box 5 kg', 'PACKAGING', 'Nos', '4819', 18, 100);
$item('SC-PURGE', 'Purge and trim scrap', 'SCRAP', 'Kg', null, 0, 0);
$mat = static fn (array $pairs): string => json_encode(array_map(static fn (array $p): array => ['rmItemId' => $p[0], 'qtyPerUnit' => $p[1]], $pairs));
$id('sp_bom_save', [$admin, null, 'Gasket A101 recipe', $fgGasket, 100, 0.025, $mat([[$rmPvc, 5], [$rmCb, 0.2], [$rmPlast, 1.5]])]);
$id('sp_bom_save', [$admin, null, 'Glazing bead C303 recipe', $fgBead, 100, 0.03, $mat([[$rmPvc, 6.5], [$rmCb, 0.3]])]);
$id('sp_bom_save', [$admin, null, 'Weatherseal B202 recipe', $fgSeal, 100, 0.02, $mat([[$rmPvc, 4], [$rmPlast, 1]])]);

$say('Opening stock and a purchase');
Db::callOut('sp_stock_adjust', [$admin, $rmPvc, 'IN', 400, 'OPENING', 'Count at go-live', $ip]);
Db::callOut('sp_stock_adjust', [$admin, $rmCb, 'IN', 60, 'OPENING', 'Count at go-live', $ip]);
Db::callOut('sp_stock_adjust', [$admin, $rmPlast, 'IN', 120, 'OPENING', 'Count at go-live', $ip]);
$po = $id('sp_po_create', [$admin, $sPoly, 'First purchase, delivery to the factory gate', $lines([['itemId' => $rmPvc, 'qty' => 1000, 'rate' => 94], ['itemId' => $rmPlast, 'qty' => 200, 'rate' => 138]])]);
Db::call('sp_po_receive', [$admin, $po]);
$id('sp_po_create', [$admin, $sColor, 'Masterbatch top-up (not yet sent)', $lines([['itemId' => $rmCb, 'qty' => 100, 'rate' => 118]])]);

$say('Orders at every stage');
$order = static fn (string $cust, string $gstin, int $days, array $l): string => $id('sp_sales_order_create', [$admin, $cust, null, $gstin, $due($days), 'WHATSAPP', null, $lines($l)]);
$gAlpha = '24AABCA1234F1Z5';
$gDelhi = '07AADCD5678K1Z2';
$gSun = '24AAECS9012M1Z8';

$draft = $order($cSun, $gSun, 14, [['itemId' => $fgSeal, 'qty' => 2000, 'rate' => 32]]);
$confirmed = $order($cDelhi, $gDelhi, 10, [['itemId' => $fgBead, 'qty' => 1500, 'rate' => 55]]);
Db::row('sp_sales_order_confirm', [$admin, $confirmed, 'EMAIL', null]);

$running = $order($cAlpha, $gAlpha, 7, [['itemId' => $fgGasket, 'qty' => 3000, 'rate' => 40]]);
Db::row('sp_sales_order_confirm', [$admin, $running, 'WHATSAPP', null]);
$woRun = (string) Db::pdo()->query("SELECT id FROM work_orders WHERE sales_order_id = '$running'")->fetchColumn();
$jcRun = $id('sp_job_card_create', [$admin, $woRun, $ws1, null, null]);
Db::call('sp_job_card_set_status', [$admin, $jcRun, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jcRun, 'METERS', 1200]);
Db::call('sp_production_log', [$admin, $jcRun, 'SCRAP', 6.5]);

$done = $order($cAlpha, $gAlpha, 3, [['itemId' => $fgGasket, 'qty' => 1000, 'rate' => 40]]);
Db::row('sp_sales_order_confirm', [$admin, $done, 'WHATSAPP', null]);
$woDone = (string) Db::pdo()->query("SELECT id FROM work_orders WHERE sales_order_id = '$done'")->fetchColumn();
$jcDone = $id('sp_job_card_create', [$admin, $woDone, $ws2, null, null]);
Db::call('sp_job_card_set_status', [$admin, $jcDone, 'ACTIVE']);
Db::call('sp_production_log', [$admin, $jcDone, 'METERS', 1000]);
Db::call('sp_job_card_set_status', [$admin, $jcDone, 'COMPLETED']);
Db::callOut('sp_qc_quick_submit', [$admin, $done, 'PASS', json_encode(['pinSize' => 4.02, 'wallThickness' => 1.5])]);
$cartons = [];
for ($i = 0; $i < 4; $i++) {
    $cartons[] = Db::row('sp_carton_quick_serialize', [$admin, $done, 250, null])['carton_code'] ?? null;
}
foreach ($cartons as $code) {
    Db::row('sp_dispatch_scan', [$admin, $code, 0, null]);
}
$inv = $id('sp_sales_invoice_create', [$admin, null, $done, null, null, null]);
Db::call('sp_sales_invoice_submit', [$admin, $inv, null, null]);
$pay = $id('sp_payment_create', [$admin, 'CUSTOMER', $cAlpha, 'BANK_TRANSFER', 'UTR-DEMO-0001', 20000, $inv, null, null]);
Db::call('sp_payment_submit', [$admin, $pay]);

$say('Shop-floor notes');
Db::callOut('sp_customer_follow_up_log', [$admin, $cDelhi, 'Asked for a price on 5,000 m of glazing bead next month', $due(30), $due(25)]);

$say("\nDone. Sign in as $as.");
$say('Orders: 1 draft, 1 confirmed, 1 in production (1,200 of 3,000 m), 1 dispatched and invoiced with a part payment.');
