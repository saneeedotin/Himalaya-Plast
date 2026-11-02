<?php /** @var array $k @var list<array> $accounts @var list<array> $recent */
$types = App\Controllers\VoucherController::ALL_TYPES;
$tile = static function (string $label, mixed $value, string $note, string $href, string $tone): string {
    return '<a class="book-tile book-tile-' . e($tone) . '" href="' . e(url($href)) . '"><span class="book-tile-label">' . e($label) . '</span>'
        . '<span class="book-tile-value">' . e(fmt_num($value)) . '</span><span class="book-tile-note">' . e($note) . '</span></a>';
};
$groups = ['CHEQUE' => 'Cheque', 'BANK' => 'Bank', 'WALLET' => 'Wallet and UPI', 'CASH' => 'Cash'];
$byType = [];
foreach ($accounts as $a) {
    $byType[$a['subtype']][] = $a;
}
$canCreate = can('Accounts', 'create'); ?>
<div class="page-head">
  <h1>Books</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/books/reports')) ?>"><?= icon('bar-chart') ?>Reports</a>
    <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(url('/books/vouchers/new')) ?>"><?= icon('plus') ?>New voucher</a><?php endif; ?>
  </div>
</div>

<div class="book-tiles">
  <?= $tile('Customers owe you', $k['receivables'] ?? 0, 'Receivables', '/receivables', 'green') ?>
  <?= $tile('You owe suppliers', $k['payables'] ?? 0, 'Payables', '/payables', 'rose') ?>
  <?= $tile('Cash in hand', $k['cash'] ?? 0, 'Cash book', '/books/ledger', 'amber') ?>
  <?= $tile('In the bank', $k['bank'] ?? 0, 'Bank book', '/books/ledger', 'blue') ?>
</div>

<div class="grid-2 books-grid">
  <section class="card">
    <h3>Cash and bank balance</h3>
    <table class="table">
      <tbody>
      <?php foreach ($groups as $sub => $label): $list = $byType[$sub] ?? []; ?>
        <tr class="subtotal"><th scope="row"><?= e($label) ?></th><td class="num"><?= e(fmt_num($k[strtolower($sub)] ?? 0)) ?></td></tr>
        <?php foreach ($list as $a): ?>
          <tr class="sub"><td><a href="<?= e(url('/books/ledger?account=' . $a['id'])) ?>"><?= e($a['name']) ?></a></td><td class="num"><?= e(drcr($a['balance'])) ?></td></tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <section class="card">
    <h3>This month</h3>
    <dl class="facts facts-stack">
      <div><dt>Sales (net of returns)</dt><dd><strong><?= e(fmt_num($k['sales_month'] ?? 0)) ?></strong></dd></div>
      <div><dt>Purchases (net of returns)</dt><dd><strong><?= e(fmt_num($k['purchases_month'] ?? 0)) ?></strong></dd></div>
      <div><dt>Expenses</dt><dd><strong><?= e(fmt_num($k['expenses_month'] ?? 0)) ?></strong></dd></div>
    </dl>
    <?php if ($canCreate): ?>
    <h3>Enter</h3>
    <p class="quick-links">
      <a class="btn" href="<?= e(url('/books/vouchers/new?type=EXPENSE')) ?>">Expense</a>
      <a class="btn" href="<?= e(url('/books/vouchers/new?type=CONTRA')) ?>">Contra</a>
      <a class="btn" href="<?= e(url('/books/vouchers/new?type=RECEIPT')) ?>">Other receipt</a>
      <a class="btn" href="<?= e(url('/books/vouchers/new?type=PAYMENT')) ?>">Other payment</a>
      <a class="btn" href="<?= e(url('/books/vouchers/new?type=JOURNAL')) ?>">Journal</a>
      <a class="btn" href="<?= e(url('/credit-notes/new?kind=SALES')) ?>">Credit note</a>
      <a class="btn" href="<?= e(url('/credit-notes/new?kind=PURCHASE')) ?>">Debit note</a>
      <a class="btn" href="<?= e(url('/payments/new')) ?>">Payment</a>
    </p>
    <?php endif; ?>
  </section>
</div>

<h2>Latest entries</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Voucher</th><th>Date</th><th>Type</th><th>Narration</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $v): ?>
    <tr>
      <td><a href="<?= e(url('/books/vouchers/' . $v['id'])) ?>"><?= e($v['voucher_number']) ?></a> <?php if ($v['status'] === 'REVERSED'): ?><?= pill('Reversed', 'fail') ?><?php endif; ?></td>
      <td><?= e(substr((string) $v['voucher_date'], 0, 10)) ?></td>
      <td><?= e($types[$v['voucher_type']] ?? $v['voucher_type']) ?></td>
      <td><?= e($v['narration']) ?></td>
      <td class="num"><?= e(fmt_num($v['amount'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="5" class="muted">Nothing is in the books yet. Invoices, bills and payments will appear here by themselves once they are posted.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">Every invoice, bill, payment and note posts to these books automatically. Entries are never edited or deleted: a mistake is cancelled with a reversing entry.</p>
