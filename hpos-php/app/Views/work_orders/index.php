<?php /** @var list<array> $workOrders @var ?string $status */
$statuses = App\Controllers\WorkOrderController::STATUSES; ?>
<div class="page-head">
  <h1>Work orders</h1>
  <?php if (can('WorkOrders', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/work-orders/new')) ?>">New work order</a><?php endif; ?>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url('/work-orders')) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($statuses as $k => $label): ?>
    <a href="<?= e(url('/work-orders?status=' . $k)) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Work order</th><th>Item</th><th>Sales order</th><th>Status</th><th>Batch</th><th class="num">Planned</th><th class="num">Produced</th><th class="num">Done</th></tr></thead>
  <tbody>
  <?php foreach ($workOrders as $w): ?>
    <tr>
      <td><a href="<?= e(url('/work-orders/' . $w['id'])) ?>"><?= e($w['work_order_number']) ?></a></td>
      <td><?= e($w['fg_code']) ?> <?= e($w['fg_name']) ?></td>
      <td><?= e($w['order_number']) ?></td>
      <td><?= status_pill($w['status']) ?></td>
      <td><?= e($w['fg_batch_number']) ?></td>
      <td class="num"><?= e(fmt_num($w['planned_qty'], 0)) ?></td>
      <td class="num"><?= e(fmt_num($w['produced_qty'], 0)) ?></td>
      <td class="num"><?= e(fmt_num($w['pct_complete'], 1)) ?>%</td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$workOrders): ?><tr><td colspan="8" class="muted">No work orders.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
