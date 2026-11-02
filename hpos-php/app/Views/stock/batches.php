<?php /** @var list<array> $rows @var ?string $source @var string $q */ ?>
<div class="page-head">
  <h1>Batches</h1>
  <a class="btn" href="<?= e(url('/stock')) ?>">Stock</a>
</div>
<form method="get" action="<?= e(url('/stock/batches')) ?>" class="filter-row">
  <div><?= field('q', 'Search', $q, ['maxlength' => 60]) ?></div>
  <div><?= select_field('source', 'Source', $source ?? '', ['PURCHASE' => 'Purchased', 'EXTRUSION' => 'Made here', 'ADJUSTMENT' => 'Adjustment'], ['blank' => 'All']) ?></div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card flush">
<table class="table">
  <thead><tr><th>Batch</th><th>Item</th><th>Source</th><th class="num">Quantity</th><th>Created</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): ?>
    <tr>
      <td><?= e($b['batch_number']) ?></td>
      <td><a href="<?= e(url('/stock/ledger?item=' . $b['item_id'])) ?>"><?= e($b['item_code']) ?></a> <?= e($b['item_name']) ?></td>
      <td><?= ['PURCHASE' => 'Purchased', 'EXTRUSION' => 'Made here', 'ADJUSTMENT' => 'Adjustment'][$b['source']] ?? e($b['source']) ?></td>
      <td class="num"><?= e(fmt_num($b['quantity'], 3)) ?> <?= e($b['uom']) ?></td>
      <td><?= e(fmt_dt($b['created_at'])) ?></td>
      <td class="actions"><?php if (can('QC', 'create')): ?><a href="<?= e(url('/quality/new?batch=' . rawurlencode($b['batch_number']))) ?>">Inspect</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No batches match.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
