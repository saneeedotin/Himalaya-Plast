<?php /** @var array $customer @var list<array> $followUps @var list<array> $orders @var ?array $ledger @var ?array $insight */ ?>
<div class="page-head">
  <h1><?= e($customer['name']) ?></h1>
  <div class="head-actions">
    <?php if (can('Customers', 'update')): ?><a class="btn" href="<?= e(url('/customers/' . $customer['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
    <?php if (can('Customers', 'delete')): ?><?= action_form('/customers/' . $customer['id'] . '/delete', 'Delete', 'Delete this customer?', 'btn btn-danger') ?><?php endif; ?>
  </div>
</div>

<section class="card">
  <dl class="facts">
    <div><dt>GSTIN</dt><dd><?= e($customer['gstin'] ?: '-') ?></dd></div>
    <div><dt>Email</dt><dd><?= e($customer['email'] ?: '-') ?></dd></div>
    <div><dt>Phone</dt><dd><?= e($customer['phone'] ?: '-') ?></dd></div>
    <?php if ($ledger): ?>
      <?php foreach ($ledger as $k => $v): if ($k === 'customer_id') { continue; } ?>
        <div><dt><?= e(ucwords(str_replace('_', ' ', $k))) ?></dt><dd><?= is_numeric($v) ? e(fmt_num($v)) : e($v) ?></dd></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </dl>
</section>

<?php if ($insight && (int) $insight['order_count'] > 0): ?>
<section class="card">
  <h3>Ordering pattern <?= reorder_pill($insight['reorder_status']) ?></h3>
  <dl class="facts">
    <div><dt>Orders</dt><dd><?= (int) $insight['order_count'] ?></dd></div>
    <div><dt>Last order</dt><dd><?= e(substr((string) $insight['last_order'], 0, 10)) ?> <span class="muted">(<?= (int) $insight['days_since_last'] ?> days ago)</span></dd></div>
    <div><dt>Usual gap</dt><dd><?= $insight['usual_gap_days'] !== null ? e(trim_zeros($insight['usual_gap_days'])) . ' days' : '-' ?></dd></div>
    <div><dt>Expected next order</dt><dd><?= e($insight['expected_next'] ? substr((string) $insight['expected_next'], 0, 10) : '-') ?></dd></div>
    <div><dt>Total ordered</dt><dd><?= e(fmt_num($insight['lifetime_value'])) ?></dd></div>
  </dl>
  <?php if (in_array($insight['reorder_status'], ['DUE', 'QUIET', 'SOON'], true) && can('Customers', 'update')): ?>
    <?= action_form('/reorders/' . $customer['id'] . '/follow-up', 'Create a reorder follow-up', null, 'btn btn-primary') ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<h2>Follow-ups</h2>
<?php if (can('Customers', 'update')): ?>
<section class="card">
  <form method="post" action="<?= e(url('/customers/' . $customer['id'] . '/follow-ups')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= textarea_field('notes', 'Note', '', ['rows' => 2]) ?>
    <div class="grid-2">
      <div><?= field('expected_next', 'Next order expected', '', ['type' => 'date']) ?></div>
      <div><?= field('reminder', 'Remind me on', '', ['type' => 'datetime-local']) ?></div>
    </div>
    <button class="btn btn-primary" type="submit">Log follow-up</button>
  </form>
</section>
<?php endif; ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Logged</th><th>Note</th><th>Next order</th><th>Reminder</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($followUps as $f): ?>
    <tr>
      <td><?= e(fmt_dt($f['created_at'])) ?></td>
      <td><?= nl2br(e($f['notes'])) ?></td>
      <td><?= e(fmt_dt($f['expected_next_order_date'])) ?></td>
      <td><?= e(fmt_dt($f['reminder_date'])) ?></td>
      <td><?= pill($f['reminder_status'], $f['reminder_status'] === 'PENDING' ? 'warn' : 'neutral') ?></td>
      <td class="actions">
        <?php if (can('Customers', 'update')): ?>
          <?php if ($f['reminder_status'] === 'PENDING'): ?>
            <form method="post" action="<?= e(url('/follow-ups/' . $f['id'] . '/status')) ?>" class="inline">
              <?= App\Core\Csrf::field() ?><input type="hidden" name="customer_id" value="<?= e($customer['id']) ?>"><input type="hidden" name="status" value="COMPLETED">
              <button class="link" type="submit">Done</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/follow-ups/' . $f['id'] . '/delete')) ?>" class="inline" data-confirm="Delete this follow-up?">
            <?= App\Core\Csrf::field() ?><input type="hidden" name="customer_id" value="<?= e($customer['id']) ?>">
            <button class="link" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$followUps): ?><tr><td colspan="6" class="muted">No follow-ups yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Recent orders</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr><td><?= e($o['order_number']) ?></td><td><?= e(fmt_dt($o['transaction_date'])) ?></td><td><?= e($o['status']) ?></td><td class="num"><?= e(fmt_num($o['total_amount'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
