<?php /** @var array $bom @var list<array> $lines */ ?>
<div class="page-head">
  <h1><?= e($bom['name']) ?></h1>
  <div class="head-actions">
    <?php if (can('BOMs', 'update')): ?><a class="btn" href="<?= e(url('/boms/' . $bom['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
    <?php if (can('BOMs', 'delete')): ?><?= action_form('/boms/' . $bom['id'] . '/delete', 'Delete', 'Delete this BOM?', 'btn btn-danger') ?><?php endif; ?>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Finished good</dt><dd><?= e($bom['fg_code']) ?> <?= e($bom['fg_name']) ?></dd></div>
    <div><dt>Output</dt><dd><?= e(fmt_num($bom['output_qty'], 3)) ?></dd></div>
    <div><dt>Scrap factor</dt><dd><?= e(fmt_num((float) $bom['scrap_factor'] * 100, 2)) ?>%</dd></div>
  </dl>
  <p class="muted">Raw material needed = quantity ordered / output x quantity per unit / (1 - scrap).</p>
</section>
<div class="card flush">
<table class="table">
  <thead><tr><th>Raw material</th><th>Unit</th><th class="num">Quantity per output</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> <?= e($l['name']) ?></td><td><?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['qty_per_unit'], 4)) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
