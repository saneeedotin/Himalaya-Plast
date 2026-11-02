<?php /** @var string $from @var string $to @var string $party @var array<string,array<string,string>> $parties @var ?array $head @var list<array> $rows */
$types = App\Controllers\VoucherController::ALL_TYPES;
$debit = array_sum(array_map(static fn (array $r): float => (float) $r['debit'], $rows));
$credit = array_sum(array_map(static fn (array $r): float => (float) $r['credit'], $rows));
$isCustomer = $head && $head['party_type'] === 'CUSTOMER'; ?>
<div class="page-head"><h1>Statement of account</h1><a class="btn" href="<?= e(url('/books/reports')) ?>">Reports</a></div>
<form method="get" action="<?= e(url('/books/reports/statement')) ?>" class="filter-row">
  <div>
    <label for="f_party">Customer or supplier</label>
    <select id="f_party" name="party" required>
      <option value="">Choose...</option>
      <?php foreach ($parties as $group => $list): ?>
        <optgroup label="<?= e($group) ?>"><?php foreach ($list as $v => $name): ?><option value="<?= e($v) ?>"<?= $party === $v ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></optgroup>
      <?php endforeach; ?>
    </select>
  </div>
  <div><?= field('from', 'From', $from, ['type' => 'date']) ?></div>
  <div><?= field('to', 'To', $to, ['type' => 'date']) ?></div>
  <button class="btn" type="submit">Show</button>
  <?php if ($head): ?><a class="btn" href="<?= e(url('/books/reports/statement?' . http_build_query(['party' => $party, 'from' => $from, 'to' => $to, 'format' => 'csv']))) ?>"><?= icon('download') ?>Download CSV</a><?php endif; ?>
</form>
<?php if (!$head): ?>
  <div class="card"><p class="muted">Choose a customer or supplier to see what they have been billed, what they have paid and what is still due.</p></div>
<?php else: $closing = (float) $head['opening'] + $debit - $credit; ?>
<section class="card">
  <dl class="facts">
    <div><dt><?= $isCustomer ? 'Customer' : 'Supplier' ?></dt><dd><strong><?= e($head['name']) ?></strong></dd></div>
    <div><dt>GSTIN</dt><dd><?= e($head['gstin'] ?: 'Unregistered') ?></dd></div>
    <div><dt>Period</dt><dd><?= e($from) ?> to <?= e($to) ?></dd></div>
    <?php $owed = $isCustomer ? $closing : -$closing; ?>
    <div><dt><?= $owed >= 0 ? ($isCustomer ? 'They owe you' : 'You owe them') : ($isCustomer ? 'You owe them (paid in advance)' : 'They owe you (paid in advance)') ?></dt><dd><strong><?= e(fmt_num(abs($owed))) ?></strong></dd></div>
  </dl>
</section>
<div class="card flush">
<table class="table">
  <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
  <tbody>
    <tr class="subtotal"><td colspan="6">Opening balance</td><td class="num"><?= e(drcr($head['opening'])) ?></td></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(substr((string) $r['voucher_date'], 0, 10)) ?></td>
      <td><a href="<?= e(url('/books/vouchers/' . $r['voucher_id'])) ?>"><?= e($r['voucher_number']) ?></a></td>
      <td><?= e($types[$r['voucher_type']] ?? $r['voucher_type']) ?></td>
      <td><?= e($r['narration']) ?></td>
      <td class="num"><?= (float) $r['debit'] > 0 ? e(fmt_num($r['debit'])) : '' ?></td>
      <td class="num"><?= (float) $r['credit'] > 0 ? e(fmt_num($r['credit'])) : '' ?></td>
      <td class="num"><?= e(drcr($r['balance'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No entries in this period.</td></tr><?php endif; ?>
    <tr class="subtotal"><td colspan="4">Closing balance</td><td class="num"><?= e(fmt_num($debit)) ?></td><td class="num"><?= e(fmt_num($credit)) ?></td><td class="num"><?= e(drcr($closing)) ?></td></tr>
  </tbody>
</table>
</div>
<p class="muted">Dr means the customer owes you (or you paid a supplier more than you owed); Cr means you owe the supplier (or the customer paid in advance).</p>
<?php endif; ?>
