<?php
declare(strict_types=1);

/**
 * Sidebar menu, in groups, in the order a person uses HPOS from day one:
 * set up the people and things you deal with (customers, suppliers, items, recipes, machines)
 * -> take an order -> buy material -> receive and store it -> plan and run production -> inspect -> pack and dispatch
 * -> invoice and collect payment -> the books and their reports. Admin and the user's own profile come last.
 * An item shows only when the user can read its resource. The database enforces access either way; this only hides links.
 *
 * Add entries here when a phase ships its screens.
 * 'perm' = [resource, action] or null for "any signed-in user".
 * 'match' = path prefixes that keep the item highlighted (defaults to its own path).
 * 'exclude' = path prefixes that must not highlight it (they belong to the next item).
 * 'icon' = a name from icon() in helpers.php.
 */
return [
    ['group' => null, 'items' => [
        ['label' => 'Dashboard', 'path' => '/', 'perm' => null, 'match' => [], 'icon' => 'layout-grid'],
    ]],
    // 1. Set up once, add to as you go: who you sell to and buy from, what you make and with what
    ['group' => 'Set up', 'items' => [
        ['label' => 'Customers',    'path' => '/customers',    'perm' => ['Customers', 'read'], 'icon' => 'users'],
        ['label' => 'Suppliers',    'path' => '/suppliers',    'perm' => ['Suppliers', 'read'], 'match' => ['/leads'], 'icon' => 'truck'],
        ['label' => 'Items',        'path' => '/items',        'perm' => ['Items', 'read'], 'icon' => 'box'],
        ['label' => 'BOMs',         'path' => '/boms',         'perm' => ['BOMs', 'read'], 'icon' => 'workflow'],
        ['label' => 'Workstations', 'path' => '/workstations', 'perm' => ['Workstations', 'read'], 'match' => ['/dies'], 'icon' => 'server'],
    ]],
    // 2. A customer orders; the order checks raw material and, once confirmed, creates the work order
    ['group' => 'Sales', 'items' => [
        ['label' => 'Orders', 'path' => '/orders', 'perm' => ['SalesOrders', 'read'], 'icon' => 'shopping-cart'],
        ['label' => 'Deadlines', 'path' => '/deadlines', 'perm' => ['SalesOrders', 'read'], 'icon' => 'clock'],
        ['label' => 'Reorder watch', 'path' => '/reorders', 'perm' => ['Customers', 'read'], 'icon' => 'repeat'],
        ['label' => 'Customer leads', 'path' => '/customer-leads', 'perm' => ['Customers', 'read'], 'icon' => 'user-plus'],
    ]],
    // 3. Short of material? Buy it
    ['group' => 'Buying', 'items' => [
        ['label' => 'Buying advice',   'path' => '/buying-advice',   'perm' => ['PurchaseOrders', 'read'], 'icon' => 'lightbulb'],
        ['label' => 'Purchase orders', 'path' => '/purchase-orders', 'perm' => ['PurchaseOrders', 'read'], 'icon' => 'shopping-bag'],
        ['label' => 'Goods receipts',  'path' => '/receipts',        'perm' => ['PurchaseOrders', 'read'], 'icon' => 'clipboard-list'],
    ]],
    // 4. Material arrives and is stored
    ['group' => 'Stock', 'items' => [
        ['label' => 'Receive material', 'path' => '/stock/receive', 'perm' => ['Stock', 'create'], 'match' => ['/scan/receive'], 'icon' => 'scan-line'],
        ['label' => 'Stock',            'path' => '/stock',         'perm' => ['Stock', 'read'], 'exact' => true, 'icon' => 'package'],
        ['label' => 'Machine stock',    'path' => '/machine-stock', 'perm' => ['Stock', 'read'], 'icon' => 'cpu'],
        ['label' => 'Batches',          'path' => '/stock/batches', 'perm' => ['Stock', 'read'], 'icon' => 'layers'],
        ['label' => 'Stock ledger',     'path' => '/stock/ledger',  'perm' => ['Stock', 'read'], 'icon' => 'book-open'],
        ['label' => 'Adjust stock',     'path' => '/stock/adjust',  'perm' => ['Stock', 'create'], 'icon' => 'sliders'],
    ]],
    // 5. Plan the job, then run it on the machine
    ['group' => 'Production', 'items' => [
        ['label' => 'Planning board',   'path' => '/planning',     'perm' => ['WorkOrders', 'read'], 'icon' => 'calendar'],
        ['label' => 'Work orders',      'path' => '/work-orders',  'perm' => ['WorkOrders', 'read'], 'icon' => 'workflow'],
        ['label' => 'Shop floor',       'path' => '/shop-floor',   'perm' => ['Production', 'read'], 'icon' => 'cpu'],
        ['label' => 'Mixing check',     'path' => '/scan/mixing',  'perm' => ['Production', 'create'], 'icon' => 'flask'],
        ['label' => 'Downtime',         'path' => '/downtime',     'perm' => ['Production', 'read'], 'icon' => 'alert-triangle'],
        ['label' => 'Shift handovers',  'path' => '/handovers',    'perm' => ['Production', 'read'], 'icon' => 'arrow-right-left'],
    ]],
    // 6. Inspect what was made, pack it, send it
    ['group' => 'Quality and dispatch', 'items' => [
        ['label' => 'QC machines',   'path' => '/quality/machines', 'perm' => ['QC', 'read'], 'icon' => 'cpu'],
        ['label' => 'QC sheets',     'path' => '/quality/sheets', 'perm' => ['QC', 'read'], 'match' => ['/quality/setup'], 'icon' => 'clipboard-list'],
        ['label' => 'Quality',       'path' => '/quality',       'perm' => ['QC', 'read'], 'exclude' => ['/quality/sheets', '/quality/machines', '/quality/setup'], 'icon' => 'shield-check'],
        ['label' => 'Dispatch',      'path' => '/dispatch',      'perm' => ['Dispatch', 'read'], 'match' => ['/scan/dispatch'], 'icon' => 'truck'],
    ]],
    // 7. Bill the customer, collect, fix mistakes with a note; then the supplier side
    ['group' => 'Billing', 'items' => [
        ['label' => 'Sales invoices',         'path' => '/invoices',          'perm' => ['Accounts', 'read'], 'icon' => 'file-spreadsheet'],
        ['label' => 'Payments',               'path' => '/payments',          'perm' => ['Accounts', 'read'], 'icon' => 'wallet'],
        ['label' => 'Receivables',            'path' => '/receivables',       'perm' => ['Accounts', 'read'], 'icon' => 'activity'],
        ['label' => 'Credit and debit notes', 'path' => '/credit-notes',      'perm' => ['Accounts', 'read'], 'icon' => 'file-minus'],
        ['label' => 'Purchase invoices',      'path' => '/purchase-invoices', 'perm' => ['Accounts', 'read'], 'icon' => 'receipt'],
        ['label' => 'Payables',               'path' => '/payables',          'perm' => ['Accounts', 'read'], 'icon' => 'activity'],
    ]],
    // 8. The books behind it all: every document above posts here by itself
    ['group' => 'Books', 'items' => [
        ['label' => 'Books overview',     'path' => '/books',          'perm' => ['Accounts', 'read'], 'exact' => true, 'icon' => 'landmark'],
        ['label' => 'Day book',           'path' => '/books/vouchers', 'perm' => ['Accounts', 'read'], 'icon' => 'book-open'],
        ['label' => 'Ledger',             'path' => '/books/ledger',   'perm' => ['Accounts', 'read'], 'icon' => 'list'],
        ['label' => 'Chart of accounts',  'path' => '/books/accounts', 'perm' => ['Accounts', 'read'], 'match' => ['/books/opening'], 'icon' => 'layers'],
        ['label' => 'Reports',            'path' => '/books/reports',  'perm' => ['Accounts', 'read'], 'match' => ['/books/reports'], 'icon' => 'bar-chart'],
        ['label' => 'Exports',            'path' => '/exports',        'perm' => ['Accounts', 'read'], 'icon' => 'download'],
    ]],
    ['group' => 'Admin', 'items' => [
        ['label' => 'Users',    'path' => '/users',    'perm' => ['users', 'read'], 'icon' => 'user'],
        ['label' => 'Settings', 'path' => '/settings', 'perm' => ['users', 'read'], 'icon' => 'sliders'],
    ]],
    ['group' => 'Account', 'items' => [
        ['label' => 'My profile', 'path' => '/account', 'perm' => null, 'icon' => 'user', 'match' => ['/account/password']],
    ]],
];
