<?php /** @var array<string,array> $vouchers @var string $from @var string $to @var string $type @var string $q @var array<string,string> $types @var bool $truncated */
$totalDr = $totalCr = 0.0; ?>
<div class="page-head">
  <h1>Day book</h1>
  <div class="head-actions">
    <?php if (can('Accounts', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/books/vouchers/new')) ?>"><?= icon('plus') ?>New voucher</a><?php endif; ?>
  </div>
</div>
<form method="get" action="<?= e(url('/books/vouchers')) ?>" class="filter-row">
  <div><?= field('from', 'From', $from, ['type' => 'date']) ?></div>
  <div><?= field('to', 'To', $to, ['type' => 'date']) ?></div>
  <div><?= select_field('type', 'Type', $type, $types, ['blank' => 'All types']) ?></div>
  <div><?= field('q', 'Search', $q, ['maxlength' => 100, 'hint' => 'Voucher number, narration or reference']) ?></div>
  <button class="btn" type="submit">Show</button>
  <a class="btn" href="<?= e(url('/books/vouchers?' . http_build_query(array_filter(['from' => $from, 'to' => $to, 'type' => $type, 'q' => $q, 'format' => 'csv'])))) ?>"><?= icon('download') ?>Download CSV</a>
</form>
<?php if ($truncated): ?><div class="alert alert-warn" role="status">Only the first 3,000 lines are shown. Narrow the dates to see the rest.</div><?php endif; ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Date</th><th>Voucher</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
  <?php foreach ($vouchers as $id => $v): $h = $v['head']; ?>
  <tbody class="voucher-group">
    <?php foreach ($v['lines'] as $n => $l): $totalDr += (float) $l['debit']; $totalCr += (float) $l['credit']; ?>
    <tr>
      <td><?= $n === 0 ? e(substr((string) $h['voucher_date'], 0, 10)) : '' ?></td>
      <td><?php if ($n === 0): ?><a href="<?= e(url('/books/vouchers/' . $id)) ?>"><?= e($h['voucher_number']) ?></a> <span class="muted"><?= e($types[$h['voucher_type']] ?? $h['voucher_type']) ?></span><?php if ($h['status'] === 'REVERSED'): ?> <?= pill('Reversed', 'fail') ?><?php endif; ?><?php endif; ?></td>
      <td><?= e($l['account_name']) ?><?php if ($l['party_name']): ?> <span class="muted">(<?= e($l['party_name']) ?>)</span><?php endif; ?></td>
      <td class="num"><?= (float) $l['debit'] > 0 ? e(fmt_num($l['debit'])) : '' ?></td>
      <td class="num"><?= (float) $l['credit'] > 0 ? e(fmt_num($l['credit'])) : '' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($h['narration']): ?><tr class="narration"><td></td><td colspan="4" class="muted"><?= e($h['narration']) ?></td></tr><?php endif; ?>
  </tbody>
  <?php endforeach; ?>
  <?php if (!$vouchers): ?><tbody><tr><td colspan="5" class="muted">No vouchers in this period.</td></tr></tbody><?php endif; ?>
  <tfoot><tr class="subtotal"><td colspan="3">Total</td><td class="num"><?= e(fmt_num($totalDr)) ?></td><td class="num"><?= e(fmt_num($totalCr)) ?></td></tr></tfoot>
</table>
</div>
