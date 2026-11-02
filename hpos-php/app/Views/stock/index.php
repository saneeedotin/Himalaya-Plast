<?php /** @var list<array> $rows @var ?string $tier @var bool $low @var array<string,float> $atMachines */
$tiers = App\Controllers\StockController::TIERS; ?>
<div class="page-head">
  <h1>Stock</h1>
  <div class="head-actions">
    <?php if (can('Stock', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/stock/adjust')) ?>">Adjust stock</a><?php endif; ?>
    <a class="btn" href="<?= e(url('/machine-stock')) ?>">Machine stock</a>
    <a class="btn" href="<?= e(url('/stock/ledger')) ?>">Stock ledger</a>
    <a class="btn" href="<?= e(url('/stock/batches')) ?>">Batches</a>
  </div>
</div>
<nav class="tabs" aria-label="Category">
  <a href="<?= e(url('/stock')) ?>"<?= $tier === null && !$low ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($tiers as $k => $label): ?>
    <a href="<?= e(url('/stock?tier=' . $k)) ?>"<?= $tier === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(url('/stock?low=1')) ?>"<?= $low ? ' class="active" aria-current="page"' : '' ?>>Low stock</a>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Item</th><th>Category</th><th>Unit</th><th class="num">In stock</th><th class="num">At machines</th><th class="num">Reserved</th><th class="num">Available</th><th class="num">Minimum</th><th></th><th>Last movement</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/stock/ledger?item=' . $r['item_id'])) ?>"><?= e($r['code']) ?></a> <?= e($r['name']) ?></td>
      <td><?= e($tiers[$r['tier']] ?? $r['tier']) ?></td>
      <td><?= e($r['uom']) ?></td>
      <td class="num"><?= e(fmt_num($r['physical_stock'], 3)) ?></td>
      <td class="num"><?= isset($atMachines[$r['item_id']]) ? e(fmt_num($atMachines[$r['item_id']], 3)) : '' ?></td>
      <td class="num"><?= e(fmt_num($r['reserved_stock'], 3)) ?></td>
      <td class="num"><?= e(fmt_num($r['available_stock'], 3)) ?></td>
      <td class="num"><?= (float) $r['min_stock_level'] > 0 ? e(fmt_num($r['min_stock_level'], 3)) : '' ?></td>
      <td><?= (int) $r['reorder_alert'] === 1 ? pill('Reorder', 'warn') : '' ?></td>
      <td><?= e(fmt_dt($r['last_movement'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="muted">No items match.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
