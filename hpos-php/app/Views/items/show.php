<?php /** @var array $item @var list<array> $boms @var list<array> $ledger @var array<string,array> $specs @var array<string,string> $specFields @var array<string,string> $suppliers */
$cats = App\Controllers\ItemController::CATEGORIES; ?>
<div class="page-head">
  <h1><?= e($item['name']) ?> <small><?= e($item['code']) ?></small></h1>
  <div class="head-actions">
    <?php if (can('Items', 'update')): ?><a class="btn" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
    <?php if (can('Items', 'delete')): ?><?= action_form('/items/' . $item['id'] . '/delete', 'Delete', 'Delete this item?', 'btn btn-danger') ?><?php endif; ?>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Category</dt><dd><?= e($cats[$item['category']] ?? $item['category']) ?></dd></div>
    <div><dt>Unit</dt><dd><?= e($item['uom']) ?></dd></div>
    <div><dt>HSN</dt><dd><?= e($item['hsn_code'] ?: '-') ?></dd></div>
    <div><dt>Standard cost</dt><dd><?= e(fmt_num($item['standard_cost'])) ?></dd></div>
    <div><dt>Minimum stock</dt><dd><?= e(fmt_num($item['min_stock_level'], 3)) ?></dd></div>
    <div><dt>In stock</dt><dd><?= e(fmt_num($item['physical_stock'], 3)) ?></dd></div>
    <div><dt>Reserved</dt><dd><?= e(fmt_num($item['reserved_stock'], 3)) ?></dd></div>
    <div><dt>Available</dt><dd><?= e(fmt_num($item['available_stock'], 3)) ?> <?= (int) $item['reorder_alert'] === 1 ? pill('Reorder', 'warn') : '' ?></dd></div>
  </dl>
</section>

<?php if ($item['category'] === 'FINISHED_GOODS' && (can('Items', 'update') || $specs || $item['colour'])): ?>
<h2>QC sheet limits</h2>
<section class="card">
  <?php if (can('Items', 'update')): ?>
  <form method="post" action="<?= e(url('/items/' . $item['id'] . '/specs')) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="card-narrow"><?= field('colour', 'Colour', $item['colour'] ?? '', ['maxlength' => 40, 'hint' => 'Printed on the QC sheet.']) ?></div>
    <table class="table spec-table">
      <thead><tr><th>Size</th><th class="num">Target</th><th class="num">Lowest allowed</th><th class="num">Highest allowed</th></tr></thead>
      <tbody>
      <?php foreach ($specFields as $k => $label): $s = $specs[$k] ?? []; ?>
        <tr>
          <td><?= e($label) ?></td>
          <?php foreach (['target' => 'target', 'min' => 'min_value', 'max' => 'max_value'] as $f => $col): ?>
            <td><input name="<?= e($k . '_' . $f) ?>" type="number" step="0.001" min="0" value="<?= e(trim_zeros($s[$col] ?? '')) ?>" aria-label="<?= e($label . ' ' . $f) ?>"></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint">Leave a limit empty to skip that check. A reading outside the limits is flagged on the QC sheet and alerts QC and Production. What is measured is set in <a href="<?= e(url('/quality/setup')) ?>">QC sheet setup</a>.</p>
    <button class="btn btn-primary" type="submit">Save limits</button>
  </form>
  <?php else: ?>
    <dl class="facts">
      <div><dt>Colour</dt><dd><?= e($item['colour'] ?: '-') ?></dd></div>
      <?php foreach ($specFields as $k => $label): if (!isset($specs[$k])) { continue; } $s = $specs[$k]; ?>
        <div><dt><?= e($label) ?></dt><dd><?= e(trim_zeros($s['min_value'] ?? '') ?: '-') ?> to <?= e(trim_zeros($s['max_value'] ?? '') ?: '-') ?></dd></div>
      <?php endforeach; ?>
    </dl>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (in_array($item['category'], ['RAW_MATERIAL', 'PACKAGING'], true) && (can('Items', 'update') || $item['lead_time_days'] !== null || $item['preferred_supplier_id'])): ?>
<h2>Buying</h2>
<section class="card">
  <?php if (can('Items', 'update')): ?>
  <form method="post" action="<?= e(url('/items/' . $item['id'] . '/buying')) ?>" class="inline-form">
    <?= App\Core\Csrf::field() ?>
    <div><?= field('lead_time_days', 'Supplier lead time (days)', $item['lead_time_days'] ?? '', ['type' => 'number', 'step' => '1', 'min' => '0', 'max' => '365', 'hint' => 'Empty means 7 days is assumed.']) ?></div>
    <div><?= field('reorder_qty', 'Smallest order', trim_zeros($item['reorder_qty'] ?? ''), ['type' => 'number', 'step' => '0.001', 'min' => '0', 'hint' => 'In ' . $item['uom'] . '. Advice never suggests less.']) ?></div>
    <div><?= select_field('preferred_supplier_id', 'Usual supplier', $item['preferred_supplier_id'] ?? '', $suppliers, ['blank' => 'None chosen']) ?></div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <?php else: ?>
  <dl class="facts">
    <div><dt>Lead time</dt><dd><?= $item['lead_time_days'] !== null ? (int) $item['lead_time_days'] . ' days' : '-' ?></dd></div>
    <div><dt>Smallest order</dt><dd><?= e(trim_zeros($item['reorder_qty'] ?? '') ?: '-') ?></dd></div>
  </dl>
  <?php endif; ?>
  <p class="muted"><a href="<?= e(url('/buying-advice')) ?>">Buying advice</a> uses these to say when to order and how much.</p>
</section>
<?php endif; ?>

<?php if ($boms): ?>
<h2>Bill of materials</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>BOM</th><th>Raw material</th><th class="num">Qty per unit</th></tr></thead>
  <tbody>
  <?php foreach ($boms as $b): ?>
    <tr>
      <td><a href="<?= e(url('/boms/' . $b['id'])) ?>"><?= e($b['name']) ?></a></td>
      <td><?= e($b['rm_code']) ?> <?= e($b['rm_name']) ?></td>
      <td class="num"><?= e(fmt_num($b['qty_per_unit'], 4)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2>Recent stock movements</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>When</th><th>Movement</th><th>Warehouse</th><th>Batch</th><th class="num">Qty</th><th>Note</th></tr></thead>
  <tbody>
  <?php foreach ($ledger as $l): ?>
    <tr>
      <td><?= e(fmt_dt($l['created_at'])) ?></td>
      <td><?= e($l['movement_type']) ?></td>
      <td><?= e($l['warehouse']) ?></td>
      <td><?= e($l['batch_number']) ?></td>
      <td class="num"><?= e(fmt_num($l['quantity'], 3)) ?></td>
      <td><?= e($l['notes']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$ledger): ?><tr><td colspan="6" class="muted">No movements yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
