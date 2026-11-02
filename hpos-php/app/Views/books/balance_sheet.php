<?php /** @var string $asof @var list<array> $assets @var list<array> $liabilities @var float $stock @var float $introduced @var float $profit @var float $assetTotal @var float $liabTotal @var float $difference */ ?>
<div class="page-head"><h1>Balance sheet</h1><a class="btn" href="<?= e(url('/books/reports')) ?>">Reports</a></div>
<?= period_form('/books/reports/balance-sheet', ['asof' => $asof]) ?>
<div class="grid-2 books-grid">
  <section class="card flush">
    <h3 class="card-title">What you own</h3>
    <table class="table">
      <tbody>
      <?php foreach ($assets as $a): ?>
        <tr><td><a href="<?= e(url('/books/ledger?to=' . $asof . '&account=' . $a['id'])) ?>"><?= e($a['name']) ?></a></td><td class="num"><?= e(fmt_num($a['shown'])) ?></td></tr>
      <?php endforeach; ?>
        <tr><td>Closing stock <span class="muted">(at standard cost)</span></td><td class="num"><?= e(fmt_num($stock)) ?></td></tr>
      </tbody>
      <tfoot><tr class="subtotal total"><td>Total assets</td><td class="num"><?= e(fmt_num($assetTotal)) ?></td></tr></tfoot>
    </table>
  </section>
  <section class="card flush">
    <h3 class="card-title">What you owe, and the capital</h3>
    <table class="table">
      <tbody>
      <?php foreach ($liabilities as $a): ?>
        <tr><td><a href="<?= e(url('/books/ledger?to=' . $asof . '&account=' . $a['id'])) ?>"><?= e($a['name']) ?></a></td><td class="num"><?= e(fmt_num($a['shown'])) ?></td></tr>
      <?php endforeach; ?>
        <?php if (abs($introduced) >= 0.005): ?><tr><td>Stock brought in as opening stock</td><td class="num"><?= e(fmt_num($introduced)) ?></td></tr><?php endif; ?>
        <tr><td><?= $profit >= 0 ? 'Profit to date' : 'Loss to date' ?></td><td class="num"><?= e(fmt_num($profit)) ?></td></tr>
      </tbody>
      <tfoot><tr class="subtotal total"><td>Total</td><td class="num"><?= e(fmt_num($liabTotal)) ?></td></tr></tfoot>
    </table>
  </section>
</div>
<?php if (abs($difference) < 0.005): ?>
  <p class="muted"><?= pill('Balanced', 'ok') ?> Assets equal liabilities plus capital as on <?= e($asof) ?>.</p>
<?php else: ?>
  <div class="alert alert-error" role="alert">The two sides differ by <?= e(fmt_num(abs($difference))) ?>. This should never happen: tell your administrator.</div>
<?php endif; ?>
<p class="muted">Debtors and creditors are the sums customers owe and you owe suppliers. Profit to date is everything earned since the books began, including the change in stock value.</p>
