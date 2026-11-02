<?php /** @var array $s @var list<array> $rows @var array<string,array> $specs @var array<string,array<string,array>> $values @var list<array> $fields @var array<string,array> $labels @var App\Controllers\QcSheetController $ctl */
$id = $s['id'];
$open = $s['status'] === 'OPEN';
$canAdd = $open && can('QC', 'create');
$canUpdate = $open && can('QC', 'update');
$live = array_filter($rows, static fn (array $r): bool => (int) $r['is_void'] === 0);
$bad = array_filter($live, static fn (array $r): bool => (int) $r['out_of_range'] === 1);
$entry = array_filter($fields, static fn (array $f): bool => (int) $f['is_active'] === 1);
$limits = [];
foreach ($fields as $f) {
    if ($f['kind'] === 'NUMBER' && isset($specs[$f['code']])) {
        $limits[] = $f['label'] . ' ' . (trim_zeros($specs[$f['code']]['min_value'] ?? '') ?: '-') . ' to ' . (trim_zeros($specs[$f['code']]['max_value'] ?? '') ?: '-');
    }
}
$options = static function (array $f): array {
    $o = [];
    foreach (explode(';', (string) $f['choices']) as $pair) {
        [$v, $name] = array_pad(explode('=', $pair, 2), 2, '');
        if ($v !== '') {
            $o[$v] = $name;
        }
    }
    return $o;
}; ?>
<div class="page-head">
  <h1><?= e($s['sheet_number']) ?> <?= pill($open ? 'Open' : 'Closed', $open ? 'warn' : 'ok') ?></h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url("/quality/sheets/$id/print")) ?>" target="_blank" rel="noopener">Print</a>
    <a class="btn" href="<?= e(url("/quality/sheets/$id/csv")) ?>"><?= icon('download') ?>Download CSV</a>
    <?php if ($canUpdate && $live): ?><?= action_form("/quality/sheets/$id/close", 'Close sheet', 'Close this sheet? No more readings can be added.', 'btn btn-primary') ?><?php endif; ?>
    <a class="btn" href="<?= e(url('/quality/sheets')) ?>">All sheets</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <?php foreach (App\Controllers\QcSheetController::HEADER as $key => $col): if (!$labels[$key]['visible']) { continue; } $val = $ctl->headerValue($key, $s); ?>
      <div><dt><?= e($labels[$key]['label']) ?></dt><dd>
        <?php if ($key === 'BATCH_NO'): ?><?= e($val) ?><?php if (can('QC', 'create')): ?> <a href="<?= e(url('/quality/new?batch=' . urlencode($s['batch_number']))) ?>">Inspect</a><?php endif; ?>
        <?php elseif ($key === 'PRODUCT_CODE'): ?><a href="<?= e(url('/items/' . $s['item_id'])) ?>"><?= e($val) ?></a> <span class="muted"><?= e($s['item_name']) ?></span>
        <?php else: ?><?= e($val !== '' ? $val : '-') ?><?php endif; ?>
      </dd></div>
    <?php endforeach; ?>
    <?php if ($s['machine_code']): ?><div><dt>Machine</dt><dd><?= e($s['machine_code']) ?> <span class="muted"><?= e($s['machine_name']) ?></span></dd></div><?php endif; ?>
    <div><dt>Started by</dt><dd><?= e($s['created_by_name'] ?: '-') ?></dd></div>
    <?php if (!$open): ?><div><dt>Closed</dt><dd><?= e(fmt_dt($s['closed_at'])) ?> <span class="muted"><?= e($s['closed_by_name']) ?></span></dd></div><?php endif; ?>
  </dl>
  <p class="muted"><?= $limits ? 'Limits: ' . e(implode(' · ', $limits)) : 'No limits are set for this product, so nothing is flagged. Set them on the item page.' ?></p>
</section>

<?php if ($bad): ?><div class="alert alert-error" role="status"><?= count($bad) ?> reading<?= count($bad) === 1 ? ' is' : 's are' ?> outside the limits.</div><?php endif; ?>

<?php if ($canAdd): ?>
<section class="card">
  <h3>Add reading <span class="muted">L-<?= str_pad((string) (count($rows) + 1), 2, '0', STR_PAD_LEFT) ?></span></h3>
  <form method="post" action="<?= e(url("/quality/sheets/$id/rows")) ?>" class="reading-form">
    <?= App\Core\Csrf::field() ?>
    <?php foreach ($entry as $f): $name = 'v[' . $f['code'] . ']'; $label = $f['label'] . ($f['unit'] ? ' (' . $f['unit'] . ')' : ''); ?>
      <div>
        <?php if ($f['kind'] === 'NUMBER'): ?><?= field($name, $label, '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'autocomplete' => 'off']) ?>
        <?php elseif ($f['kind'] === 'CHOICE'): ?><?= select_field($name, $label, '', $options($f), ['blank' => 'Not checked']) ?>
        <?php else: ?><?= field($name, $label, '', ['maxlength' => 100, 'autocomplete' => 'off']) ?><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div><?= field('measured_at', $labels['DATE_TIME']['label'], date('Y-m-d\TH:i'), ['type' => 'datetime-local']) ?></div>
    <div><button class="btn btn-primary" type="submit">Add reading</button></div>
  </form>
  <p class="hint">Signed with your name automatically. A reading cannot be edited afterwards; void it and enter it again.</p>
</section>
<?php endif; ?>

<div class="card flush">
<table class="table qc-sheet">
  <thead><tr><th><?= e($labels['SR_NO']['label']) ?></th>
    <?php foreach ($fields as $f): ?><th<?= $f['kind'] === 'NUMBER' ? ' class="num"' : '' ?>><?= e($f['label']) ?><?= $f['unit'] ? ' <span class="muted">(' . e($f['unit']) . ')</span>' : '' ?></th><?php endforeach; ?>
    <th><?= e($labels['DATE_TIME']['label']) ?></th><th><?= e($labels['AUTHORISED']['label']) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $void = (int) $r['is_void'] === 1; $outs = explode(',', (string) $r['out_params']); ?>
    <tr<?= $void ? ' class="void"' : '' ?>>
      <td>L-<?= e(str_pad((string) $r['sr_no'], 2, '0', STR_PAD_LEFT)) ?></td>
      <?php foreach ($fields as $f): ?><td class="<?= $f['kind'] === 'NUMBER' ? 'num' : '' ?><?= in_array($f['code'], $outs, true) ? ' out' : '' ?>"><?= e(App\Controllers\QcSheetController::cell($f, $values[$r['id']][$f['code']] ?? null)) ?></td><?php endforeach; ?>
      <td><?= e(substr((string) $r['measured_at'], 0, 16)) ?></td>
      <td title="<?= e($r['authorised_name']) ?>"><?= e(initials($r['authorised_name'])) ?></td>
      <td class="actions">
        <?php if ($void): ?><span class="muted">Void: <?= e($r['void_reason']) ?></span>
        <?php elseif ($canUpdate): ?>
          <form method="post" action="<?= e(url("/quality/sheets/$id/rows/" . $r['id'] . '/void')) ?>" class="inline void-form" data-confirm="Void this reading?">
            <?= App\Core\Csrf::field() ?>
            <input name="reason" type="text" maxlength="200" placeholder="Reason" required aria-label="Reason for voiding">
            <button class="btn btn-quiet" type="submit">Void</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= count($fields) + 4 ?>" class="muted">No readings yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">Readings in red are outside the product's limits. Voided readings stay on the sheet, struck through, so the record is complete.</p>
