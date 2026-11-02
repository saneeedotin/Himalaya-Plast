<?php /** @var array $po @var list<array> $lines @var list<array> $receipts */
$id = $po['id'];
$open = in_array($po['status'], ['DRAFT', 'SUBMITTED', 'PARTIAL'], true);
$canUpdate = can('PurchaseOrders', 'update'); ?>
<div class="page-head">
  <h1><?= e($po['po_number']) ?> <?= status_pill($po['status']) ?></h1>
  <div class="head-actions">
    <?php if ($po['status'] === 'DRAFT' && $canUpdate): ?><a class="btn" href="<?= e(url("/purchase-orders/$id/edit")) ?>">Edit</a><?php endif; ?>
    <?php if ($po['status'] === 'DRAFT' && can('PurchaseOrders', 'delete')): ?><?= action_form("/purchase-orders/$id/delete", 'Delete', 'Delete this purchase order?', 'btn btn-danger') ?><?php endif; ?>
    <a class="btn" href="<?= e(url('/purchase-orders')) ?>">All purchase orders</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Supplier</dt><dd><a href="<?= e(url('/suppliers/' . $po['supplier_id'])) ?>"><?= e($po['supplier_name']) ?></a></dd></div>
    <div><dt>Ordered</dt><dd><?= e(fmt_dt($po['order_date'])) ?></dd></div>
    <div><dt>Total</dt><dd><?= e(fmt_num($po['total_amount'])) ?></dd></div>
  </dl>
  <?php if ($po['notes']): ?><p class="notes"><?= nl2br(e($po['notes'])) ?></p><?php endif; ?>
</section>

<div class="card flush">
<table class="table">
  <thead><tr><th>Item</th><th>Unit</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> <?= e($l['name']) ?></td><td><?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="4" class="num">Total</td><td class="num"><?= e(fmt_num($po['total_amount'])) ?></td></tr></tfoot>
</table>
</div>

<?php if ($open && $canUpdate): ?>
<section class="card">
  <h3>Next step</h3>
  <div class="form-actions">
    <?php if ($po['status'] === 'DRAFT'): ?>
      <form method="post" action="<?= e(url("/purchase-orders/$id/status")) ?>" class="inline"><?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="SUBMITTED"><button class="btn btn-primary" type="submit">Submit to supplier</button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(url("/purchase-orders/$id/receive")) ?>" class="inline" data-confirm="Receive every line into stock now?"><?= App\Core\Csrf::field() ?><button class="btn" type="submit">Receive everything</button></form>
    <form method="post" action="<?= e(url("/purchase-orders/$id/receipt")) ?>" class="inline"><?= App\Core\Csrf::field() ?><button class="btn" type="submit">Draft a goods receipt</button></form>
    <form method="post" action="<?= e(url("/purchase-orders/$id/status")) ?>" class="inline" data-confirm="Close this purchase order without receiving?"><?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="CLOSED"><button class="btn btn-quiet" type="submit">Close</button></form>
  </div>
  <p class="hint">"Receive everything" books every line in one go. A goods receipt lets you check the quantities first; stock moves when you submit it.</p>
</section>
<?php endif; ?>

<?php if ($receipts): ?>
<h2>Goods receipts</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Receipt</th><th>Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($receipts as $r): ?>
    <tr><td><a href="<?= e(url('/receipts/' . $r['id'])) ?>"><?= e($r['receipt_number']) ?></a></td><td><?= e(fmt_dt($r['receipt_date'])) ?></td><td><?= status_pill($r['status']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
