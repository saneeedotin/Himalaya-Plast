<?php /** @var array $s @var list<array> $rows @var array<string,array<string,array>> $values @var list<array> $fields @var array<string,array> $labels @var App\Controllers\QcSheetController $ctl */
$pad = max(25, count($rows));
$cols = count($fields) + 3;
$show = [];
foreach (App\Controllers\QcSheetController::HEADER as $key => $col) {
    if ($labels[$key]['visible']) {
        $show[] = [$labels[$key]['label'], $ctl->headerValue($key, $s)];
    }
}
$perRow = 4; ?>
<div class="no-print"><p><button class="btn btn-primary" type="button" data-print>Print or save as PDF</button> <a href="<?= e(url('/quality/sheets/' . $s['id'])) ?>">Back</a></p></div>
<article class="paper qc-paper">
  <table class="qc-head">
    <?php foreach (array_chunk($show, $perRow) as $chunk): ?>
      <tr><?php foreach ($chunk as [$label, $val]): ?><td><b><?= e(strtoupper($label)) ?>:</b> <?= e($val) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table>
  <table class="qc-grid">
    <thead><tr><th><?= e(strtoupper($labels['SR_NO']['label'])) ?></th>
      <?php foreach ($fields as $f): ?><th><?= e(strtoupper($f['label'])) ?><?= $f['unit'] ? ' (' . e($f['unit']) . ')' : '' ?></th><?php endforeach; ?>
      <th><?= e(strtoupper($labels['DATE_TIME']['label'])) ?></th><th><?= e(strtoupper($labels['AUTHORISED']['label'])) ?></th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $pad; $i++): $r = $rows[$i] ?? null; $void = $r && (int) $r['is_void'] === 1; ?>
      <tr<?= $void ? ' class="void"' : '' ?>>
        <?php if ($r): ?>
          <td>L-<?= e(str_pad((string) $r['sr_no'], 2, '0', STR_PAD_LEFT)) ?></td>
          <?php foreach ($fields as $f): ?><td><?= e(App\Controllers\QcSheetController::cell($f, $values[$r['id']][$f['code']] ?? null)) ?></td><?php endforeach; ?>
          <td><?= e(substr((string) $r['measured_at'], 0, 16)) ?></td>
          <td><?= $void ? 'VOID' : e(initials($r['authorised_name'])) ?></td>
        <?php else: ?>
          <td>&nbsp;</td><?php for ($k = 0; $k < $cols - 1; $k++): ?><td></td><?php endfor; ?>
        <?php endif; ?>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>
  <p class="qc-foot"><b><?= e(strtoupper($labels['FOOTER']['label'])) ?></b></p>
</article>
