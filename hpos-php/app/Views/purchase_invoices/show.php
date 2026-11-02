<?php /** @var array $inv @var list<array> $lines @var list<array> $payments */
$id = $inv['id'];
$open = in_array($inv['status'], ['UNPAID', 'PARTIAL'], true); ?>
<div class="page-head">
  <h1><?= e($inv['invoice_number']) ?> <?= status_pill($inv['status']) ?></h1>
  <div class="head-actions">
    <?php if ($inv['status'] === 'DRAFT' && can('Accounts', 'update')): ?><?= action_form("/purchase-invoices/$id/submit", 'Post bill', 'Post this bill? It will count as a payable.', 'btn btn-primary') ?><?php endif; ?>
    <?php if ($open && can('Accounts', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/payments/new?purchase_invoice=' . $id)) ?>">Record payment</a><?php endif; ?>
    <?php if (in_array($inv['status'], ['UNPAID', 'PARTIAL', 'PAID'], true) && can('Accounts', 'create')): ?><a class="btn" href="<?= e(url('/credit-notes/new?kind=PURCHASE&invoice=' . $id)) ?>">Debit note</a><?php endif; ?>
    <?php if (in_array($inv['status'], ['DRAFT', 'UNPAID'], true) && can('Accounts', 'delete')): ?><?= action_form("/purchase-invoices/$id/cancel", 'Cancel bill', 'Cancel this bill?', 'btn btn-danger') ?><?php endif; ?>
    <a class="btn" href="<?= e(url('/purchase-invoices')) ?>">All bills</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Supplier</dt><dd><a href="<?= e(url('/suppliers/' . $inv['supplier_id'])) ?>"><?= e($inv['supplier_name']) ?></a></dd></div>
    <div><dt>Bill date</dt><dd><?= e(substr((string) $inv['invoice_date'], 0, 10)) ?></dd></div>
    <div><dt>Due</dt><dd><?= e(substr((string) $inv['due_date'], 0, 10)) ?></dd></div>
    <div><dt>Subtotal</dt><dd><?= e(fmt_num($inv['subtotal_amount'])) ?></dd></div>
    <div><dt>GST</dt><dd><?= e(fmt_num($inv['tax_amount'])) ?></dd></div>
    <div><dt>Total</dt><dd><strong><?= e(fmt_num($inv['total_amount'])) ?></strong></dd></div>
    <div><dt>Outstanding</dt><dd><strong><?= e(fmt_num($inv['outstanding'])) ?></strong></dd></div>
  </dl>
</section>
<?php if ($lines): ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Item</th><th>HSN</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">GST %</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> <?= e($l['name']) ?></td><td><?= e($l['hsn_code']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?> <?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td><td class="num"><?= e(trim_zeros($l['tax_rate'])) ?></td><td class="num"><?= e(fmt_num($l['total_amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php if ($payments): ?>
<h2>Payments</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Payment</th><th>Date</th><th>Mode</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr><td><?= e($p['payment_number']) ?></td><td><?= e(substr((string) $p['payment_date'], 0, 10)) ?></td><td><?= e(App\Controllers\PaymentController::MODES[$p['mode_of_payment']] ?? $p['mode_of_payment']) ?></td><td><?= e($p['reference_number']) ?></td><td><?= status_pill($p['status']) ?></td><td class="num"><?= e(fmt_num($p['amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
