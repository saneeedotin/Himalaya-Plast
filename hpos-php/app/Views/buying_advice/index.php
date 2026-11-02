<?php /** @var array<string,list<array>> $groups @var array<string,string> $suppliers @var array<string,int> $count @var string $show */
$status = App\Controllers\BuyingAdviceController::STATUS;
$canCreate = can('PurchaseOrders', 'create'); ?>
<div class="page-head">
  <h1>Buying advice</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/purchase-orders')) ?>">Purchase orders</a>
    <a class="btn" href="<?= e(url('/stock')) ?>">Stock</a>
  </div>
</div>
<div class="tiles">
  <div class="tile<?= $count['ORDER_NOW'] > 0 ? ' tile-bad' : '' ?>"><span class="tile-label">Order now</span><span class="tile-value"><?= (int) $count['ORDER_NOW'] ?></span></div>
  <div class="tile<?= $count['ORDER_SOON'] > 0 ? ' tile-warn' : '' ?>"><span class="tile-label">Order soon</span><span class="tile-value"><?= (int) $count['ORDER_SOON'] ?></span></div>
  <div class="tile"><span class="tile-label">Fine for now</span><span class="tile-value"><?= (int) $count['OK'] ?></span></div>
</div>
<nav class="tabs" aria-label="Show">
  <a href="<?= e(url('/buying-advice')) ?>"<?= $show === 'act' ? ' class="active" aria-current="page"' : '' ?>>Needs ordering</a>
  <a href="<?= e(url('/buying-advice?show=all')) ?>"<?= $show === 'all' ? ' class="active" aria-current="page"' : '' ?>>All materials</a>
</nav>
<?php foreach ($groups as $sid => $rows): ?>
<section class="card flush">
  <form method="post" action="<?= e(url('/buying-advice/po')) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="advice-head">
      <h3><?= $sid !== '' ? e($suppliers[$sid] ?? 'Supplier') : 'No preferred supplier' ?></h3>
      <?php if ($canCreate): ?>
      <div class="advice-actions">
        <select name="supplier_id" aria-label="Supplier for this order" required>
          <option value="">Choose supplier...</option>
          <?php foreach ($suppliers as $id => $name): ?><option value="<?= e($id) ?>"<?= (string) $id === (string) $sid ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Create draft purchase order</button>
      </div>
      <?php endif; ?>
    </div>
    <table class="table">
      <thead><tr><?php if ($canCreate): ?><th>Order</th><?php endif; ?><th>Material</th><th>Advice</th><th class="num">In stock</th><th class="num">On order</th><th class="num">Uses per day</th><th class="num">Days left</th><th class="num">Lead time</th><th class="num">Suggested</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): [$label, $kind] = $status[$r['status']]; $can = $canCreate && $r['status'] !== 'OK' && !$r['draft_po']; ?>
        <tr>
          <?php if ($canCreate): ?><td><?php if ($can): ?><input type="checkbox" name="item[]" value="<?= e($r['item_id']) ?>"<?= $r['status'] === 'ORDER_NOW' ? ' checked' : '' ?> aria-label="Order <?= e($r['name']) ?>"><?php endif; ?></td><?php endif; ?>
          <td><a href="<?= e(url('/items/' . $r['item_id'])) ?>"><?= e($r['code']) ?></a> <span class="muted"><?= e($r['name']) ?></span></td>
          <td><?= pill($label, $kind) ?><?php if ($r['draft_po']): ?> <span class="muted">on draft <?= e($r['draft_po']) ?></span><?php endif; ?></td>
          <td class="num"><?= e(fmt_num($r['physical_stock'], 0)) ?> <?= e($r['uom']) ?></td>
          <td class="num"><?= (float) $r['incoming_qty'] > 0 ? e(fmt_num($r['incoming_qty'], 0)) : '-' ?></td>
          <td class="num"><?= (float) $r['daily_use'] > 0 ? e(fmt_num($r['daily_use'], 1)) : '-' ?></td>
          <td class="num"><?= $r['days_left'] !== null ? e(trim_zeros($r['days_left'])) : '-' ?></td>
          <td class="num"><?= $r['lead_time_days'] !== null ? (int) $r['lead_time_days'] . ' d' : '7 d <span class="muted">(assumed)</span>' ?></td>
          <td class="num">
            <?php if ($can): ?><input name="qty[<?= e($r['item_id']) ?>]" type="number" step="0.001" min="0" value="<?= e(trim_zeros($r['suggested_qty'])) ?>" aria-label="Quantity of <?= e($r['name']) ?>" class="advice-qty">
            <?php elseif ($r['status'] !== 'OK'): ?><?= e(fmt_num($r['suggested_qty'], 0)) ?><?php else: ?>-<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </form>
</section>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="card"><p class="muted"><?= $show === 'all' ? 'No raw material or packaging items yet.' : 'Nothing needs ordering right now.' ?> HPOS looks at what each material has used per day over the last 30 days, what is in stock and on order, and how long the supplier takes.</p></div><?php endif; ?>
<p class="muted">Advice is a suggestion. Set a lead time, a smallest order quantity and a preferred supplier on each material's page to sharpen it. Days of cover to keep are set in Settings (cover days). Ticked lines become one draft purchase order; nothing is sent to the supplier.</p>
