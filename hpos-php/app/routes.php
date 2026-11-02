<?php
declare(strict_types=1);


use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\BomController;
use App\Controllers\BookReportController;
use App\Controllers\BuyingAdviceController;
use App\Controllers\BooksController;
use App\Controllers\CreditNoteController;
use App\Controllers\CustomerController;
use App\Controllers\CustomerLeadController;
use App\Controllers\ReorderController;
use App\Controllers\DeadlineController;
use App\Controllers\DieController;
use App\Controllers\HandoverController;
use App\Controllers\HomeController;
use App\Controllers\ItemController;
use App\Controllers\JobChecklistController;
use App\Controllers\LeadController;
use App\Controllers\MachineStockController;
use App\Controllers\DispatchController;
use App\Controllers\ExportController;
use App\Controllers\InvoiceController;
use App\Controllers\LedgerController;
use App\Controllers\NotificationController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\PlanningController;
use App\Controllers\PurchaseInvoiceController;
use App\Controllers\SettingsController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\QcMachineController;
use App\Controllers\QcSetupController;
use App\Controllers\QcSheetController;
use App\Controllers\QualityController;
use App\Controllers\ReceiptController;
use App\Controllers\ScanController;
use App\Controllers\SearchController;
use App\Controllers\SetupController;
use App\Controllers\StockController;
use App\Controllers\ShopFloorController;
use App\Controllers\SupplierController;
use App\Controllers\UserController;
use App\Controllers\VoucherController;
use App\Controllers\WorkOrderController;
use App\Controllers\WorkstationController;
use App\Core\Router;

$r = new Router();

// public
$r->get('/setup', [new SetupController(), 'index'], ['auth' => false]);
$r->get('/login', [new AuthController(), 'showLogin'], ['auth' => false]);
$r->post('/login', [new AuthController(), 'login'], ['auth' => false]);

// signed in
$r->get('/', [new HomeController(), 'index']);
$r->post('/logout', [new AuthController(), 'logout']);
$r->get('/account', [new AccountController(), 'show']);
$r->post('/account', [new AccountController(), 'update']);
$r->get('/account/password', [new AccountController(), 'showPassword']);
$r->post('/account/password', [new AccountController(), 'changePassword']);

/**
 * Standard screens for a master: list, new, create, show, edit, update, delete.
 * /new is registered before /{id} so "new" is not read as an id.
 * $perm is the resource the pages need; the database checks it again on every call.
 */
$crud = static function (string $path, object $c, string $perm, array $extra = []) use ($r): void {
    $r->get($path, [$c, 'index'], ['perm' => [$perm, 'read']]);
    $r->get("$path/new", [$c, 'create'], ['perm' => [$perm, 'create']]);
    $r->post($path, [$c, 'store'], ['perm' => [$perm, 'create']]);
    if (method_exists($c, 'show')) {
        $r->get("$path/{id}", [$c, 'show'], ['perm' => [$perm, 'read']]);
    }
    $r->get("$path/{id}/edit", [$c, 'edit'], ['perm' => [$perm, 'update']]);
    $r->post("$path/{id}", [$c, 'update'], ['perm' => [$perm, 'update']]);
    if (method_exists($c, 'destroy')) {
        $r->post("$path/{id}/delete", [$c, 'destroy'], ['perm' => [$perm, 'delete']]);
    }
};

$crud('/orders', $orders = new OrderController(), 'SalesOrders');
$r->post('/orders/{id}/confirm', [$orders, 'confirm'], ['perm' => ['SalesOrders', 'update']]);
$r->post('/orders/{id}/status', [$orders, 'setStatus'], ['perm' => ['SalesOrders', 'update']]);
$r->post('/orders/{id}/shortage-po', [$orders, 'shortagePo'], ['perm' => ['PurchaseOrders', 'create']]);

// production
$wo = new WorkOrderController();
$r->get('/work-orders', [$wo, 'index'], ['perm' => ['WorkOrders', 'read']]);
$r->get('/work-orders/new', [$wo, 'create'], ['perm' => ['WorkOrders', 'create']]);
$r->post('/work-orders', [$wo, 'store'], ['perm' => ['WorkOrders', 'create']]);
$r->get('/work-orders/{id}', [$wo, 'show'], ['perm' => ['WorkOrders', 'read']]);
$r->post('/work-orders/{id}', [$wo, 'update'], ['perm' => ['WorkOrders', 'update']]);
$r->post('/work-orders/{id}/delete', [$wo, 'destroy'], ['perm' => ['WorkOrders', 'delete']]);
$r->post('/work-orders/{id}/job-cards', [$wo, 'addJobCard'], ['perm' => ['WorkOrders', 'create']]);
$r->post('/job-cards/{id}/status', [$wo, 'jobCardStatus'], ['perm' => ['Production', 'update']]);
// smart: stock near the machines
$mstock = new MachineStockController();
$r->get('/machine-stock', [$mstock, 'index'], ['perm' => ['Stock', 'read']]);
$r->post('/machine-stock/issue', [$mstock, 'issue'], ['perm' => ['Stock', 'create']]);
$r->post('/machine-stock/return', [$mstock, 'giveBack'], ['perm' => ['Stock', 'create']]);

// smart: deadlines, repeat-order tracking and customer leads
$r->get('/deadlines', [new DeadlineController(), 'index'], ['perm' => ['SalesOrders', 'read']]);
$reorders = new ReorderController();
$r->get('/reorders', [$reorders, 'index'], ['perm' => ['Customers', 'read']]);
$r->post('/reorders/{id}/follow-up', [$reorders, 'followUp'], ['perm' => ['Customers', 'update']]);
$cleads = new CustomerLeadController();
$r->get('/customer-leads', [$cleads, 'index'], ['perm' => ['Customers', 'read']]);
$r->get('/customer-leads/new', [$cleads, 'create'], ['perm' => ['Customers', 'create']]);
$r->post('/customer-leads', [$cleads, 'store'], ['perm' => ['Customers', 'create']]);
$r->get('/customer-leads/{id}/edit', [$cleads, 'edit'], ['perm' => ['Customers', 'update']]);
$r->post('/customer-leads/{id}', [$cleads, 'update'], ['perm' => ['Customers', 'update']]);
$r->post('/customer-leads/{id}/convert', [$cleads, 'convert'], ['perm' => ['Customers', 'create']]);

$planning = new PlanningController();
$r->get('/planning', [$planning, 'board'], ['perm' => ['WorkOrders', 'read']]);
$r->get('/job-cards/{id}/plan', [$planning, 'edit'], ['perm' => ['WorkOrders', 'read']]);
$r->post('/job-cards/{id}/plan', [$planning, 'save'], ['perm' => ['WorkOrders', 'update']]);
$r->post('/job-cards/{id}/plan/clear', [$planning, 'clear'], ['perm' => ['WorkOrders', 'update']]);
$checklist = new JobChecklistController();
$r->get('/job-cards/{id}/checklist', [$checklist, 'show'], ['perm' => ['Production', 'read']]);
$r->post('/job-cards/{id}/checklist', [$checklist, 'set'], ['perm' => ['Production', 'create']]);
$r->post('/job-cards/{id}/delete', [$wo, 'jobCardDelete'], ['perm' => ['WorkOrders', 'delete']]);

$floor = new ShopFloorController();
$r->get('/shop-floor', [$floor, 'board'], ['perm' => ['Production', 'read']]);
$r->post('/production/log', [$floor, 'log'], ['perm' => ['Production', 'create']]);
$r->get('/downtime', [$floor, 'downtime'], ['perm' => ['Production', 'read']]);
$r->post('/downtime/start', [$floor, 'startStop'], ['perm' => ['Production', 'create']]);
$r->post('/downtime/{id}/end', [$floor, 'endStop'], ['perm' => ['Production', 'update']]);

$handovers = new HandoverController();
$r->get('/handovers', [$handovers, 'index'], ['perm' => ['Production', 'read']]);
$r->get('/handovers/new', [$handovers, 'create'], ['perm' => ['Production', 'create']]);
$r->post('/handovers', [$handovers, 'store'], ['perm' => ['Production', 'create']]);
$r->post('/handovers/{id}/accept', [$handovers, 'accept'], ['perm' => ['Production', 'create']]);

$scan = new ScanController();
$r->get('/stock/receive', [$scan, 'receiveForm'], ['perm' => ['Stock', 'create']]);
$r->post('/stock/receive', [$scan, 'receive'], ['perm' => ['Stock', 'create']]);
$r->get('/scan/receive', [$scan, 'receiveForm'], ['perm' => ['Stock', 'create']]);
$r->post('/scan/receive', [$scan, 'receive'], ['perm' => ['Stock', 'create']]);
$r->get('/scan/mixing', [$scan, 'mixingForm'], ['perm' => ['Production', 'create']]);
$r->post('/scan/mixing', [$scan, 'mixing'], ['perm' => ['Production', 'create']]);

// buying, quality, dispatch
$po = new PurchaseOrderController();
$crud('/purchase-orders', $po, 'PurchaseOrders');
$r->post('/purchase-orders/{id}/status', [$po, 'setStatus'], ['perm' => ['PurchaseOrders', 'update']]);
$r->post('/purchase-orders/{id}/receive', [$po, 'receiveAll'], ['perm' => ['PurchaseOrders', 'update']]);
$r->post('/purchase-orders/{id}/receipt', [$po, 'createReceipt'], ['perm' => ['PurchaseOrders', 'update']]);
$grn = new ReceiptController();
$r->get('/receipts', [$grn, 'index'], ['perm' => ['PurchaseOrders', 'read']]);
$r->get('/receipts/{id}', [$grn, 'show'], ['perm' => ['PurchaseOrders', 'read']]);
$r->post('/receipts/{id}/submit', [$grn, 'submit'], ['perm' => ['PurchaseOrders', 'update']]);

$qc = new QualityController();
$r->get('/quality', [$qc, 'index'], ['perm' => ['QC', 'read']]);
$r->get('/quality/new', [$qc, 'create'], ['perm' => ['QC', 'create']]);
$r->post('/quality', [$qc, 'store'], ['perm' => ['QC', 'create']]);
// QC sheet setup and the machine board: registered before /quality/{id} too
$qcSetup = new QcSetupController();
$r->get('/quality/setup', [$qcSetup, 'index'], ['perm' => ['QC', 'read']]);
$r->post('/quality/setup/fields', [$qcSetup, 'saveField'], ['perm' => ['QC', 'update']]);
$r->post('/quality/setup/labels', [$qcSetup, 'saveLabels'], ['perm' => ['QC', 'update']]);
$r->get('/quality/machines', [new QcMachineController(), 'index'], ['perm' => ['QC', 'read']]);
// QC sheets: registered before /quality/{id} so "sheets" is not read as an id
$sheets = new QcSheetController();
$r->get('/quality/sheets', [$sheets, 'index'], ['perm' => ['QC', 'read']]);
$r->get('/quality/sheets/new', [$sheets, 'create'], ['perm' => ['QC', 'create']]);
$r->post('/quality/sheets', [$sheets, 'store'], ['perm' => ['QC', 'create']]);
$r->get('/quality/sheets/{id}', [$sheets, 'show'], ['perm' => ['QC', 'read']]);
$r->get('/quality/sheets/{id}/print', [$sheets, 'print'], ['perm' => ['QC', 'read']]);
$r->get('/quality/sheets/{id}/csv', [$sheets, 'csv'], ['perm' => ['QC', 'read']]);
$r->post('/quality/sheets/{id}/rows', [$sheets, 'addRow'], ['perm' => ['QC', 'create']]);
$r->post('/quality/sheets/{id}/rows/{row}/void', [$sheets, 'voidRow'], ['perm' => ['QC', 'update']]);
$r->post('/quality/sheets/{id}/close', [$sheets, 'close'], ['perm' => ['QC', 'update']]);
$r->get('/quality/{id}', [$qc, 'show'], ['perm' => ['QC', 'read']]);

$dispatch = new DispatchController();
$r->get('/dispatch', [$dispatch, 'index'], ['perm' => ['Dispatch', 'read']]);
$r->get('/dispatch/new', [$dispatch, 'create'], ['perm' => ['Dispatch', 'create']]);
$r->post('/dispatch', [$dispatch, 'store'], ['perm' => ['Dispatch', 'create']]);
$r->get('/dispatch/{id}', [$dispatch, 'show'], ['perm' => ['Dispatch', 'read']]);
$r->get('/dispatch/{id}/labels', [$dispatch, 'labels'], ['perm' => ['Dispatch', 'read']]);
$r->get('/dispatch/{id}/print', [$dispatch, 'note'], ['perm' => ['Dispatch', 'read']]);
$r->post('/dispatch/{id}/cartons', [$dispatch, 'addCarton'], ['perm' => ['Dispatch', 'create']]);
$r->post('/dispatch/{id}/transport', [$dispatch, 'transport'], ['perm' => ['Dispatch', 'create']]);
$r->post('/dispatch/{id}/load', [$dispatch, 'loadCartons'], ['perm' => ['Dispatch', 'scan']]);
$r->get('/scan/dispatch', [$dispatch, 'scanForm'], ['perm' => ['Dispatch', 'scan']]);
$r->post('/scan/dispatch', [$dispatch, 'scan'], ['perm' => ['Dispatch', 'scan']]);

// stock, alerts, search
$stock = new StockController();
$r->get('/stock', [$stock, 'index'], ['perm' => ['Stock', 'read']]);
$r->get('/stock/ledger', [$stock, 'ledger'], ['perm' => ['Stock', 'read']]);
$r->get('/stock/batches', [$stock, 'batches'], ['perm' => ['Stock', 'read']]);
$r->get('/stock/adjust', [$stock, 'adjustForm'], ['perm' => ['Stock', 'create']]);
$r->post('/stock/adjust', [$stock, 'adjust'], ['perm' => ['Stock', 'create']]);

$notes = new NotificationController();
$r->get('/notifications', [$notes, 'index']);
$r->get('/notifications/poll', [$notes, 'poll']);
$r->post('/notifications/read-all', [$notes, 'readAll']);
$r->post('/push/status', [$notes, 'pushStatus']);
$r->post('/push/subscribe', [$notes, 'pushSubscribe']);
$r->post('/push/unsubscribe', [$notes, 'pushUnsubscribe']);
$r->post('/push/test', [$notes, 'pushTest']);
$r->post('/notifications/{id}/status', [$notes, 'setStatus']);

$r->get('/search', [new SearchController(), 'index']);
$r->get('/search/suggest', [new SearchController(), 'suggest']);

// accounts
$inv = new InvoiceController();
$r->get('/invoices', [$inv, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/invoices/new', [$inv, 'create'], ['perm' => ['Accounts', 'create']]);
$r->post('/invoices', [$inv, 'store'], ['perm' => ['Accounts', 'create']]);
$r->get('/invoices/{id}', [$inv, 'show'], ['perm' => ['Accounts', 'read']]);
$r->get('/invoices/{id}/print', [$inv, 'print'], ['perm' => ['Accounts', 'read']]);
$r->post('/invoices/{id}/lines', [$inv, 'setLines'], ['perm' => ['Accounts', 'update']]);
$r->post('/invoices/{id}/submit', [$inv, 'submit'], ['perm' => ['Accounts', 'update']]);
$r->post('/invoices/{id}/einvoice', [$inv, 'einvoice'], ['perm' => ['Accounts', 'update']]);
$r->post('/invoices/{id}/cancel', [$inv, 'cancel'], ['perm' => ['Accounts', 'delete']]);

$pinv = new PurchaseInvoiceController();
$r->get('/purchase-invoices', [$pinv, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/purchase-invoices/new', [$pinv, 'create'], ['perm' => ['Accounts', 'create']]);
$r->post('/purchase-invoices', [$pinv, 'store'], ['perm' => ['Accounts', 'create']]);
$r->get('/purchase-invoices/{id}', [$pinv, 'show'], ['perm' => ['Accounts', 'read']]);
$r->post('/purchase-invoices/{id}/submit', [$pinv, 'submit'], ['perm' => ['Accounts', 'update']]);
$r->post('/purchase-invoices/{id}/cancel', [$pinv, 'cancel'], ['perm' => ['Accounts', 'delete']]);

$pay = new PaymentController();
$r->get('/payments', [$pay, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/payments/new', [$pay, 'create'], ['perm' => ['Accounts', 'create']]);
$r->post('/payments', [$pay, 'store'], ['perm' => ['Accounts', 'create']]);
$r->post('/payments/{id}/submit', [$pay, 'submit'], ['perm' => ['Accounts', 'update']]);
$r->post('/payments/{id}/cancel', [$pay, 'cancel'], ['perm' => ['Accounts', 'delete']]);

$ledger = new LedgerController();
$r->get('/receivables', [$ledger, 'receivables'], ['perm' => ['Accounts', 'read']]);
$r->get('/payables', [$ledger, 'payables'], ['perm' => ['Accounts', 'read']]);

// credit notes (to customers) and debit notes (to suppliers)
$cn = new CreditNoteController();
$r->get('/credit-notes', [$cn, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/credit-notes/new', [$cn, 'create'], ['perm' => ['Accounts', 'create']]);
$r->post('/credit-notes', [$cn, 'store'], ['perm' => ['Accounts', 'create']]);
$r->get('/credit-notes/{id}', [$cn, 'show'], ['perm' => ['Accounts', 'read']]);
$r->get('/credit-notes/{id}/print', [$cn, 'print'], ['perm' => ['Accounts', 'read']]);
$r->get('/credit-notes/{id}/edit', [$cn, 'edit'], ['perm' => ['Accounts', 'update']]);
$r->post('/credit-notes/{id}', [$cn, 'update'], ['perm' => ['Accounts', 'update']]);
$r->post('/credit-notes/{id}/post', [$cn, 'post'], ['perm' => ['Accounts', 'update']]);
$r->post('/credit-notes/{id}/cancel', [$cn, 'cancel'], ['perm' => ['Accounts', 'delete']]);
$r->post('/credit-notes/{id}/delete', [$cn, 'delete'], ['perm' => ['Accounts', 'delete']]);

// books: ledger, vouchers, reports
$books = new BooksController();
$r->get('/books', [$books, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/reports', [$books, 'reports'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/accounts', [$books, 'accounts'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/accounts/new', [$books, 'accountNew'], ['perm' => ['Accounts', 'create']]);
$r->post('/books/accounts', [$books, 'accountStore'], ['perm' => ['Accounts', 'create']]);
$r->get('/books/accounts/{id}/edit', [$books, 'accountEdit'], ['perm' => ['Accounts', 'update']]);
$r->post('/books/accounts/{id}', [$books, 'accountUpdate'], ['perm' => ['Accounts', 'update']]);
$r->post('/books/accounts/{id}/toggle', [$books, 'accountToggle'], ['perm' => ['Accounts', 'update']]);
$r->get('/books/ledger', [$books, 'ledger'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/opening', [$books, 'opening'], ['perm' => ['Accounts', 'create']]);
$r->post('/books/opening', [$books, 'saveOpening'], ['perm' => ['Accounts', 'create']]);

$vch = new VoucherController();
$r->get('/books/vouchers', [$vch, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/vouchers/new', [$vch, 'create'], ['perm' => ['Accounts', 'create']]);
$r->post('/books/vouchers', [$vch, 'store'], ['perm' => ['Accounts', 'create']]);
$r->get('/books/vouchers/{id}', [$vch, 'show'], ['perm' => ['Accounts', 'read']]);
$r->post('/books/vouchers/{id}/cancel', [$vch, 'cancel'], ['perm' => ['Accounts', 'delete']]);

$rep = new BookReportController();
$r->get('/books/reports/trial-balance', [$rep, 'trialBalance'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/reports/profit-loss', [$rep, 'profitLoss'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/reports/balance-sheet', [$rep, 'balanceSheet'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/reports/gst', [$rep, 'gst'], ['perm' => ['Accounts', 'read']]);
$r->get('/books/reports/statement', [$rep, 'statement'], ['perm' => ['Accounts', 'read']]);

$exp = new ExportController();
$r->get('/exports', [$exp, 'index'], ['perm' => ['Accounts', 'read']]);
$r->get('/exports/bookkeeper', [$exp, 'bookkeeper'], ['perm' => ['Accounts', 'read']]);
$r->get('/exports/tally', [$exp, 'tally'], ['perm' => ['Accounts', 'read']]);

$settings = new SettingsController();
$r->get('/settings', [$settings, 'index'], ['perm' => ['users', 'read']]);
$r->post('/settings', [$settings, 'update'], ['perm' => ['users', 'update']]);

$crud('/users', $users = new UserController(), 'users');
$r->post('/users/{id}/password', [$users, 'resetPassword'], ['perm' => ['users', 'update']]);
$crud('/customers', $customers = new CustomerController(), 'Customers');
$r->post('/customers/{id}/follow-ups', [$customers, 'addFollowUp'], ['perm' => ['Customers', 'update']]);
$r->post('/follow-ups/{id}/status', [$customers, 'followUpStatus'], ['perm' => ['Customers', 'update']]);
$r->post('/follow-ups/{id}/delete', [$customers, 'followUpDelete'], ['perm' => ['Customers', 'update']]);
$crud('/suppliers', new SupplierController(), 'Suppliers');
$crud('/leads', new LeadController(), 'Suppliers');
$crud('/items', $itemsC = new ItemController(), 'Items');
$r->post('/items/{id}/specs', [$itemsC, 'saveSpecs'], ['perm' => ['Items', 'update']]);
$r->post('/items/{id}/buying', [$itemsC, 'saveBuying'], ['perm' => ['Items', 'update']]);
$advice = new BuyingAdviceController();
$r->get('/buying-advice', [$advice, 'index'], ['perm' => ['PurchaseOrders', 'read']]);
$r->post('/buying-advice/po', [$advice, 'createPo'], ['perm' => ['PurchaseOrders', 'create']]);
$crud('/boms', new BomController(), 'BOMs');
$crud('/workstations', new WorkstationController(), 'Workstations');
$crud('/dies', new DieController(), 'Workstations');

return $r;
