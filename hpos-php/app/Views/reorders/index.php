<?php /** @var list<array> $rows @var array<string,int> $count */
$canFollow = can('Customers', 'update'); ?>
<div class="page-head">
  <h1>Reorder watch</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/customer-leads')) ?>">Customer leads</a>
    <a class="btn" href="<?= e(url('/customers')) ?>">Customers</a>
  </div>
</div>
<div class="tiles">
  <div class="tile<?= $count['DUE'] > 0 ? ' tile-bad' : '' ?>"><span class="tile-label">Due to reorder</span><span class="tile-value"><?= (int) $count['DUE'] ?></span></div>
  <div class="tile<?= $count['QUIET'] > 0 ? ' tile-bad' : '' ?>"><span class="tile-label">Gone quiet</span><span class="tile-value"><?= (int) $count['QUIET'] ?></span></div>
  <div class="tile<?= $count['SOON'] > 0 ? ' tile-warn' : '' ?>"><span class="tile-label">Reorder soon</span><span class="tile-value"><?= (int) $count['SOON'] ?></span></div>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Customer</th><th>Status</th><th>Last order</th><th class="num">Usual gap</th><th class="num">Days since</th><th>Expected</th><th class="num">Orders</th><th class="num">Total ordered</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/customers/' . $r['customer_id'])) ?>"><?= e($r['name']) ?></a><?= $r['phone'] ? ' <span class="muted">' . e($r['phone']) . '</span>' : '' ?></td>
      <td><?= reorder_pill($r['reorder_status']) ?></td>
      <td><?= e(substr((string) $r['last_order'], 0, 10)) ?></td>
      <td class="num"><?= $r['usual_gap_days'] !== null ? e(trim_zeros($r['usual_gap_days'])) . ' d' : '-' ?></td>
      <td class="num"><?= (int) $r['days_since_last'] ?></td>
      <td><?= e($r['expected_next'] ? substr((string) $r['expected_next'], 0, 10) : '-') ?></td>
      <td class="num"><?= (int) $r['order_count'] ?></td>
      <td class="num"><?= e(fmt_num($r['lifetime_value'], 0)) ?></td>
      <td class="actions">
        <?php if ($canFollow && in_array($r['reorder_status'], ['DUE', 'QUIET', 'SOON'], true)): ?>
          <?php if ((int) $r['open_follow_ups'] > 0): ?><span class="muted">Follow-up open</span>
          <?php else: ?><?= action_form('/reorders/' . $r['customer_id'] . '/follow-up', 'Create follow-up', null, 'btn btn-quiet') ?><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted">No customer has an order yet, so there is nothing to work out.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">HPOS works out each customer's usual gap between orders from their past orders (drafts and cancelled orders do not count; a customer needs at least two orders, set in Settings). Due means the usual gap has passed; Gone quiet means more than twice the gap. Nothing is sent: you call them, and the button only creates the follow-up and a reminder.</p>
