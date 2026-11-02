<?php /** @var list<array> $items @var ?string $category */
$cats = App\Controllers\ItemController::CATEGORIES; ?>
<div class="page-head">
  <h1>Items</h1>
  <?php if (can('Items', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/items/new')) ?>">New item</a><?php endif; ?>
</div>
<nav class="tabs" aria-label="Category">
  <a href="<?= e(url('/items')) ?>"<?= $category === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($cats as $k => $label): ?>
    <a href="<?= e(url('/items?category=' . $k)) ?>"<?= $category === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th class="num">In stock</th><th class="num">Reserved</th><th class="num">Available</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr>
      <td><a href="<?= e(url('/items/' . $i['id'])) ?>"><?= e($i['code']) ?></a></td>
      <td><?= e($i['name']) ?></td>
      <td><?= e($cats[$i['category']] ?? $i['category']) ?></td>
      <td><?= e($i['uom']) ?></td>
      <td class="num"><?= e(fmt_num($i['physical_stock'], 3)) ?></td>
      <td class="num"><?= e(fmt_num($i['reserved_stock'], 3)) ?></td>
      <td class="num"><?= e(fmt_num($i['available_stock'], 3)) ?></td>
      <td><?= (int) $i['reorder_alert'] === 1 ? pill('Reorder', 'warn') : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="8" class="muted">No items.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
