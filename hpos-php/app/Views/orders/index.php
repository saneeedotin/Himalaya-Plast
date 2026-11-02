<?php /** @var list<array> $orders @var ?string $status @var list<string> $statuses */ ?>
<div class="page-head">
  <h1>Sales orders</h1>
  <?php if (can('SalesOrders', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/orders/new')) ?>">New order</a><?php endif; ?>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url('/orders')) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($statuses as $s): ?>
    <a href="<?= e(url('/orders?status=' . $s)) ?>"<?= $status === $s ? ' class="active" aria-current="page"' : '' ?>><?= e(ucfirst(strtolower(str_replace('_', ' ', $s)))) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Delivery</th><th class="num">Items</th><th class="num">Total</th><th class="num">Produced</th><th class="num">Cartons loaded</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="<?= e(url('/orders/' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
      <td><?= e($o['customer_name']) ?></td>
      <td><?= status_pill($o['status']) ?></td>
      <td><?= e(substr((string) $o['delivery_date'], 0, 10)) ?> <?= (int) $o['is_overdue'] === 1 ? pill('Overdue', 'fail') : '' ?></td>
      <td class="num"><?= (int) $o['line_count'] ?></td>
      <td class="num"><?= e(fmt_num($o['total_amount'])) ?></td>
      <td class="num"><?= (float) $o['planned_qty'] > 0 ? e(fmt_num($o['produced_qty'], 0) . ' / ' . fmt_num($o['planned_qty'], 0)) : '' ?></td>
      <td class="num"><?= (int) $o['cartons_total'] > 0 ? e($o['cartons_scanned'] . ' / ' . $o['cartons_total']) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="8" class="muted">No orders<?= $status ? ' with this status' : ' yet' ?>.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
