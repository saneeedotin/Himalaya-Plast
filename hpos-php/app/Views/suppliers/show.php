<?php /** @var array $supplier @var list<array> $orders */ ?>
<div class="page-head">
  <h1><?= e($supplier['name']) ?></h1>
  <div class="head-actions">
    <?php if (can('Suppliers', 'update')): ?><a class="btn" href="<?= e(url('/suppliers/' . $supplier['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
    <?php if (can('Suppliers', 'delete')): ?><?= action_form('/suppliers/' . $supplier['id'] . '/delete', 'Delete', 'Delete this supplier?', 'btn btn-danger') ?><?php endif; ?>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>GSTIN</dt><dd><?= e($supplier['gstin'] ?: '-') ?></dd></div>
    <div><dt>Email</dt><dd><?= e($supplier['email'] ?: '-') ?></dd></div>
    <div><dt>Phone</dt><dd><?= e($supplier['phone'] ?: '-') ?></dd></div>
    <div><dt>Status</dt><dd><?= e($supplier['status']) ?></dd></div>
  </dl>
</section>
<h2>Recent purchase orders</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>PO</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr><td><?= e($o['po_number']) ?></td><td><?= e(fmt_dt($o['order_date'])) ?></td><td><?= e($o['status']) ?></td><td class="num"><?= e(fmt_num($o['total_amount'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="4" class="muted">No purchase orders yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
