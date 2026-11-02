<?php /** @var list<array> $boms */ ?>
<div class="page-head">
  <h1>Bills of materials</h1>
  <?php if (can('BOMs', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/boms/new')) ?>">New BOM</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>BOM</th><th>Finished good</th><th class="num">Output</th><th class="num">Scrap</th><th class="num">Materials</th></tr></thead>
  <tbody>
  <?php foreach ($boms as $b): ?>
    <tr>
      <td><a href="<?= e(url('/boms/' . $b['id'])) ?>"><?= e($b['name']) ?></a></td>
      <td><?= e($b['fg_code']) ?> <?= e($b['fg_name']) ?></td>
      <td class="num"><?= e(fmt_num($b['output_qty'], 3)) ?></td>
      <td class="num"><?= e(fmt_num((float) $b['scrap_factor'] * 100, 2)) ?>%</td>
      <td class="num"><?= (int) $b['material_count'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$boms): ?><tr><td colspan="5" class="muted">No BOMs yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">When a finished good has more than one BOM, the oldest one is used for material checks and production.</p>
