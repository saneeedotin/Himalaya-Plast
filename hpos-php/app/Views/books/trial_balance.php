<?php /** @var list<array> $rows @var string $asof @var float $dr @var float $cr */
$types = App\Controllers\BooksController::TYPES; ?>
<div class="page-head"><h1>Trial balance</h1><a class="btn" href="<?= e(url('/books/reports')) ?>">Reports</a></div>
<?= period_form('/books/reports/trial-balance', ['asof' => $asof]) ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Code</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['code']) ?></td>
      <td><a href="<?= e(url('/books/ledger?account=' . $r['id'] . '&to=' . $asof)) ?>"><?= e($r['name']) ?></a> <span class="muted"><?= e($types[$r['acct_type']] ?? '') ?></span></td>
      <td class="num"><?= $r['dr'] > 0 ? e(fmt_num($r['dr'])) : '' ?></td>
      <td class="num"><?= $r['cr'] > 0 ? e(fmt_num($r['cr'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="muted">No entries yet.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr class="subtotal"><td colspan="2">Total</td><td class="num"><?= e(fmt_num($dr)) ?></td><td class="num"><?= e(fmt_num($cr)) ?></td></tr></tfoot>
</table>
</div>
<?php if (abs($dr - $cr) < 0.005): ?>
  <p class="muted"><?= pill('Balanced', 'ok') ?> Debits equal credits. Stock is valued at standard cost and is not part of the trial balance; it appears on the balance sheet.</p>
<?php else: ?>
  <div class="alert alert-error" role="alert">Debits and credits differ by <?= e(fmt_num(abs($dr - $cr))) ?>. This should never happen: tell your administrator.</div>
<?php endif; ?>
