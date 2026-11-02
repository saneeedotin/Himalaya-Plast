<?php /** @var list<array> $receipts */ ?>
<div class="page-head">
  <h1>Goods receipts</h1>
  <a class="btn" href="<?= e(url('/purchase-orders')) ?>">Purchase orders</a>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Receipt</th><th>Purchase order</th><th>Supplier</th><th>Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($receipts as $r): ?>
    <tr>
      <td><a href="<?= e(url('/receipts/' . $r['id'])) ?>"><?= e($r['receipt_number']) ?></a></td>
      <td><a href="<?= e(url('/purchase-orders/' . $r['purchase_order_id'])) ?>"><?= e($r['po_number']) ?></a></td>
      <td><?= e($r['supplier_name']) ?></td>
      <td><?= e(fmt_dt($r['receipt_date'])) ?></td>
      <td><?= status_pill($r['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$receipts): ?><tr><td colspan="5" class="muted">No goods receipts yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
