<?php /** @var list<array> $notes */ ?>
<div class="page-head">
  <h1>Dispatch</h1>
  <div class="head-actions">
    <?php if (can('Dispatch', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/dispatch/new')) ?>">Pack an order</a><?php endif; ?>
  </div>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Delivery note</th><th>Order</th><th>Customer</th><th>Status</th><th class="num">Cartons loaded</th><th class="num">Quantity</th><th>Dispatched</th></tr></thead>
  <tbody>
  <?php foreach ($notes as $n): ?>
    <tr>
      <td><a href="<?= e(url('/dispatch/' . $n['delivery_note_id'])) ?>"><?= e($n['dn_number']) ?></a></td>
      <td><a href="<?= e(url('/orders/' . $n['sales_order_id'])) ?>"><?= e($n['order_number']) ?></a></td>
      <td><?= e($n['customer_name']) ?></td>
      <td><?= status_pill($n['status']) ?></td>
      <td class="num"><?= (int) $n['scanned_cartons'] ?> / <?= (int) $n['total_cartons'] ?></td>
      <td class="num"><?= e(fmt_num($n['total_qty'], 0)) ?></td>
      <td><?= e(fmt_dt($n['dispatched_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$notes): ?><tr><td colspan="7" class="muted">No delivery notes yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
