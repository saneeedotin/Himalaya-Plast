<?php /** @var list<array> $rows @var array<string,int> $count @var string $show */ ?>
<div class="page-head">
  <h1>Deadlines</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/planning')) ?>">Planning board</a>
    <a class="btn" href="<?= e(url('/orders')) ?>">Orders</a>
  </div>
</div>
<div class="tiles">
  <div class="tile<?= $count['LATE'] > 0 ? ' tile-bad' : '' ?>"><span class="tile-label">Will miss the date</span><span class="tile-value"><?= (int) $count['LATE'] ?></span></div>
  <div class="tile<?= $count['AT_RISK'] > 0 ? ' tile-warn' : '' ?>"><span class="tile-label">No slack</span><span class="tile-value"><?= (int) $count['AT_RISK'] ?></span></div>
  <div class="tile"><span class="tile-label">On track</span><span class="tile-value"><?= (int) $count['ON_TRACK'] ?></span></div>
  <div class="tile"><span class="tile-label">Made</span><span class="tile-value"><?= (int) $count['READY'] ?></span></div>
</div>
<nav class="tabs" aria-label="Show">
  <a href="<?= e(url('/deadlines')) ?>"<?= $show === 'act' ? ' class="active" aria-current="page"' : '' ?>>Needs attention</a>
  <a href="<?= e(url('/deadlines?show=all')) ?>"<?= $show === 'all' ? ' class="active" aria-current="page"' : '' ?>>All open orders</a>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Delivery by</th><th>Can finish</th><th class="num">Spare days</th><th>Check</th><th class="num">Made</th><th>Why</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/orders/' . $r['order_id'])) ?>"><?= e($r['order_number']) ?></a></td>
      <td><?= e($r['customer_name']) ?></td>
      <td><?= status_pill($r['status']) ?></td>
      <td><?= e(substr((string) $r['delivery_date'], 0, 10)) ?></td>
      <td><?= $r['basis'] === 'DONE' ? '-' : e(substr((string) $r['projected_finish'], 0, 10)) ?></td>
      <td class="num"><?= $r['basis'] === 'DONE' ? '-' : (int) $r['slack_days'] ?></td>
      <td><?= health_pill($r['health']) ?></td>
      <td class="num"><?= $r['percent_made'] !== null ? (int) $r['percent_made'] . '%' : '-' ?></td>
      <td class="muted"><?= e(projection_reason($r)) ?><?= (int) $r['not_confirmed'] === 1 ? ' Not confirmed by the client yet.' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted"><?= $show === 'all' ? 'No open orders.' : 'No order is late or short of slack.' ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">For each open order HPOS uses the planning board's dates if its jobs are planned. Otherwise it divides the metres still to make by what the machines really make a day (from finished job cards; a figure in Settings is assumed until there are some), starting when raw material and a machine are free, plus a spare day. It never changes a delivery date.</p>
