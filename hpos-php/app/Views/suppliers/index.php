<?php /** @var list<array> $suppliers */ ?>
<div class="page-head">
  <h1>Suppliers</h1>
  <?php if (can('Suppliers', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/suppliers/new')) ?>">New supplier</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Name</th><th>GSTIN</th><th>Email</th><th>Phone</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($suppliers as $s): ?>
    <tr>
      <td><a href="<?= e(url('/suppliers/' . $s['id'])) ?>"><?= e($s['name']) ?></a></td>
      <td><?= e($s['gstin']) ?></td>
      <td><?= e($s['email']) ?></td>
      <td><?= e($s['phone']) ?></td>
      <td><?= $s['status'] === 'ACTIVE' ? pill('Active', 'ok') : pill('Inactive', 'neutral') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$suppliers): ?><tr><td colspan="5" class="muted">No suppliers yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p><a href="<?= e(url('/leads')) ?>">Supplier leads</a></p>
