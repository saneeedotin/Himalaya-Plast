<?php /** @var list<array> $rows @var array<string,string> $items @var ?string $item @var int $limit */
$out = ['CONSUMPTION', 'SCRAP', 'DISPATCH', 'ADJUSTMENT_OUT']; ?>
<div class="page-head">
  <h1>Stock ledger</h1>
  <a class="btn" href="<?= e(url('/stock')) ?>">Stock</a>
</div>
<form method="get" action="<?= e(url('/stock/ledger')) ?>" class="filter-row">
  <div><?= select_field('item', 'Item', $item ?? '', $items, ['blank' => 'All items']) ?></div>
  <div><?= select_field('limit', 'Show', (string) $limit, ['50' => 'Last 50', '100' => 'Last 100', '500' => 'Last 500']) ?></div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card flush">
<table class="table">
  <thead><tr><th>When</th><th>Item</th><th>Movement</th><th>Warehouse</th><th>Batch</th><th class="num">Quantity</th><th>Note</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $isOut = in_array($r['movement_type'], $out, true); ?>
    <tr>
      <td><?= e(fmt_dt($r['created_at'])) ?></td>
      <td><?= e($r['item_code']) ?> <?= e($r['item_name']) ?></td>
      <td><?= e(ucfirst(strtolower(str_replace('_', ' ', $r['movement_type'])))) ?></td>
      <td><?= e($r['warehouse']) ?></td>
      <td><?= e($r['batch_number']) ?></td>
      <td class="num"><?= $isOut ? '-' : '+' ?><?= e(fmt_num($r['quantity'], 3)) ?> <?= e($r['uom']) ?></td>
      <td><?= e($r['notes']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No movements.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">The ledger only ever grows: stock is the sum of these lines, and a mistake is fixed with a reversing line, never by editing one.</p>
