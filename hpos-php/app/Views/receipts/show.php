<?php /** @var array $grn @var list<array> $lines */ ?>
<div class="page-head">
  <h1><?= e($grn['receipt_number']) ?> <?= status_pill($grn['status']) ?></h1>
  <div class="head-actions">
    <?php if ($grn['status'] === 'DRAFT' && can('PurchaseOrders', 'update')): ?>
      <?= action_form('/receipts/' . $grn['id'] . '/submit', 'Submit and book stock', 'Book these quantities into stock?', 'btn btn-primary') ?>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/receipts')) ?>">All receipts</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Purchase order</dt><dd><a href="<?= e(url('/purchase-orders/' . $grn['purchase_order_id'])) ?>"><?= e($grn['po_number']) ?></a></dd></div>
    <div><dt>Supplier</dt><dd><?= e($grn['supplier_name']) ?></dd></div>
    <div><dt>Received on</dt><dd><?= e(fmt_dt($grn['receipt_date'])) ?></dd></div>
  </dl>
  <?php if ($grn['notes']): ?><p class="notes"><?= nl2br(e($grn['notes'])) ?></p><?php endif; ?>
</section>
<div class="card flush">
<table class="table">
  <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Rate</th><th>Batch</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> <?= e($l['name']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?> <?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td><?= e($l['batch_number']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
