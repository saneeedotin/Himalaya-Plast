<?php /** @var list<array> $customers */ ?>
<div class="page-head">
  <h1>Customers</h1>
  <?php if (can('Customers', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/customers/new')) ?>">New customer</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Name</th><th>GSTIN</th><th>Email</th><th>Phone</th><th>Next expected order</th></tr></thead>
  <tbody>
  <?php foreach ($customers as $c): ?>
    <tr>
      <td><a href="<?= e(url('/customers/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td>
      <td><?= e($c['gstin']) ?></td>
      <td><?= e($c['email']) ?></td>
      <td><?= e($c['phone']) ?></td>
      <td><?php if ($c['next_expected_order']): ?><?= e(fmt_dt($c['next_expected_order'])) ?><?php elseif ($c['computed_next_order']): ?><?= e(substr((string) $c['computed_next_order'], 0, 10)) ?> <span class="muted">(worked out)</span><?php endif; ?> <?= in_array($c['reorder_status'], ['DUE', 'QUIET', 'SOON'], true) ? reorder_pill($c['reorder_status']) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$customers): ?><tr><td colspan="5" class="muted">No customers yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
