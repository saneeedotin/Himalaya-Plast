<?php /** @var list<array> $orders @var ?string $status */
$statuses = ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'PARTIAL' => 'Partial', 'RECEIVED' => 'Received', 'CLOSED' => 'Closed']; ?>
<div class="page-head">
  <h1>Purchase orders</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/receipts')) ?>">Goods receipts</a>
    <?php if (can('PurchaseOrders', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/purchase-orders/new')) ?>">New purchase order</a><?php endif; ?>
  </div>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url('/purchase-orders')) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($statuses as $k => $label): ?>
    <a href="<?= e(url('/purchase-orders?status=' . $k)) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>PO</th><th>Supplier</th><th>Date</th><th>Status</th><th class="num">Lines</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="<?= e(url('/purchase-orders/' . $o['id'])) ?>"><?= e($o['po_number']) ?></a></td>
      <td><?= e($o['supplier_name']) ?></td>
      <td><?= e(fmt_dt($o['order_date'])) ?></td>
      <td><?= status_pill($o['status']) ?></td>
      <td class="num"><?= (int) $o['line_count'] ?></td>
      <td class="num"><?= e(fmt_num($o['total_amount'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="6" class="muted">No purchase orders.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
