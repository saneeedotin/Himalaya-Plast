<?php /** @var list<array> $leads @var string $status */
$kind = ['NEW' => 'warn', 'CONTACTED' => 'neutral', 'QUOTED' => 'info', 'WON' => 'ok', 'LOST' => 'fail'];
$statuses = App\Controllers\CustomerLeadController::STATUSES; ?>
<div class="page-head">
  <h1>Customer leads</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/reorders')) ?>">Reorder watch</a>
    <?php if (can('Customers', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/customer-leads/new')) ?>"><?= icon('plus') ?>New lead</a><?php endif; ?>
  </div>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url('/customer-leads')) ?>"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($statuses as $k => $label): ?>
    <a href="<?= e(url('/customer-leads?status=' . $k)) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Company</th><th>Contact</th><th>Phone</th><th>Source</th><th>Status</th><th>Next follow-up</th><th>Owner</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($leads as $l): $due = $l['next_follow_up'] && substr((string) $l['next_follow_up'], 0, 16) <= date('Y-m-d H:i') && in_array($l['status'], ['NEW', 'CONTACTED', 'QUOTED'], true); ?>
    <tr>
      <td><?= e($l['company_name']) ?></td>
      <td><?= e($l['contact_name']) ?></td>
      <td><?= e($l['phone']) ?></td>
      <td><?= e($l['source']) ?></td>
      <td><?= pill($statuses[$l['status']] ?? $l['status'], $kind[$l['status']] ?? 'neutral') ?></td>
      <td><?= e(fmt_dt($l['next_follow_up'])) ?> <?= $due ? pill('Due', 'fail') : '' ?></td>
      <td><?= e($l['owner_name']) ?></td>
      <td class="actions">
        <?php if ($l['converted_customer_id']): ?><a href="<?= e(url('/customers/' . $l['converted_customer_id'])) ?>">Customer</a>
        <?php else: ?>
          <?php if (can('Customers', 'update')): ?><a href="<?= e(url('/customer-leads/' . $l['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
          <?php if (can('Customers', 'create') && $l['status'] !== 'LOST'): ?><?= action_form('/customer-leads/' . $l['id'] . '/convert', 'Convert to customer', 'Turn this lead into a customer?', 'btn btn-quiet') ?><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$leads): ?><tr><td colspan="8" class="muted">No leads here yet. Add the people you are talking to before they place a first order.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
