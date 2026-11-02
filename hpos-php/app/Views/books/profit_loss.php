<?php /** @var string $from @var string $to @var array<string,list<array>> $g @var array<string,float> $t */
$rows = static function (array $list, string $from, string $to): string {
    $html = '';
    foreach ($list as $r) {
        $html .= '<tr class="sub"><td>' . e($r['name']) . '</td><td class="num">' . e(fmt_num($r['amount'])) . '</td></tr>';
    }
    return $html;
};
$line = static function (string $label, float $amount, string $cls = ''): string {
    return '<tr' . ($cls !== '' ? ' class="' . e($cls) . '"' : '') . '><th scope="row">' . e($label) . '</th><td class="num">' . e(fmt_num($amount)) . '</td></tr>';
}; ?>
<div class="page-head"><h1>Profit and loss</h1><a class="btn" href="<?= e(url('/books/reports')) ?>">Reports</a></div>
<?= period_form('/books/reports/profit-loss', ['from' => $from, 'to' => $to]) ?>

<h2>Trading account</h2>
<div class="card flush">
<table class="table">
  <tbody>
    <?= $line('Sales, net of returns', $t['sales'], 'subtotal') ?>
    <?= $rows($g['sales'], $from, $to) ?>
    <?= $line('Opening stock', $t['opening']) ?>
    <?= $line('Purchases, net of returns', $t['purchases']) ?>
    <?= $rows($g['purchases'], $from, $to) ?>
    <?= $line('Direct expenses', $t['direct']) ?>
    <?= $rows($g['direct'], $from, $to) ?>
    <?= $line('Less: closing stock', -$t['closing']) ?>
    <?= $line('Cost of goods sold', $t['cogs'], 'subtotal') ?>
    <?= $line($t['gross'] >= 0 ? 'Gross profit' : 'Gross loss', $t['gross'], 'subtotal total') ?>
  </tbody>
</table>
</div>

<h2>Profit and loss</h2>
<div class="card flush">
<table class="table">
  <tbody>
    <?= $line('Gross profit', $t['gross']) ?>
    <?= $line('Other income', $t['other_income'], 'subtotal') ?>
    <?= $rows($g['other_income'], $from, $to) ?>
    <?= $line('Indirect expenses', $t['indirect'], 'subtotal') ?>
    <?= $rows($g['indirect'], $from, $to) ?>
    <?= $line($t['net'] >= 0 ? 'Net profit' : 'Net loss', $t['net'], 'subtotal total') ?>
  </tbody>
</table>
</div>
<p class="muted">Stock is valued at standard cost, so the cost of goods and the profit are as good as the standard costs on your items. Stock entered as opening stock is capital, not profit. Opening and closing stock come from the stock ledger; everything else from the books.</p>
