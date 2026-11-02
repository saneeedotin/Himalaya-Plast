<?php /** @var array $v @var list<array> $lines @var array<string,string> $types */
$id = $v['id'];
$manual = $v['source_type'] === 'MANUAL' || $v['source_type'] === null;
$totalDr = array_sum(array_map(static fn (array $l): float => (float) $l['debit'], $lines));
$totalCr = array_sum(array_map(static fn (array $l): float => (float) $l['credit'], $lines));
$sources = ['SALES_INVOICE' => ['/invoices/', 'Sales invoice'], 'PURCHASE_INVOICE' => ['/purchase-invoices/', 'Purchase invoice'], 'PAYMENT' => ['/payments', 'Payment'], 'CREDIT_NOTE' => ['/credit-notes/', 'Credit or debit note']]; ?>
<div class="page-head">
  <h1><?= e($v['voucher_number']) ?> <?= $v['status'] === 'REVERSED' ? pill('Reversed', 'fail') : pill('Posted', 'ok') ?></h1>
  <div class="head-actions">
    <?php if ($v['status'] === 'POSTED' && $manual && $v['reverses_id'] === null && can('Accounts', 'delete')): ?>
      <form method="post" action="<?= e(url("/books/vouchers/$id/cancel")) ?>" class="inline-form" data-confirm="Cancel this voucher? A reversing entry will be posted.">
        <?= App\Core\Csrf::field() ?>
        <div><?= field('reason', 'Reason', '', ['maxlength' => 200]) ?></div>
        <button class="btn btn-danger" type="submit">Cancel voucher</button>
      </form>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/books/vouchers')) ?>">Day book</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Type</dt><dd><?= e($types[$v['voucher_type']] ?? $v['voucher_type']) ?></dd></div>
    <div><dt>Date</dt><dd><?= e(substr((string) $v['voucher_date'], 0, 10)) ?></dd></div>
    <div><dt>Reference</dt><dd><?= e($v['reference'] ?: '-') ?></dd></div>
    <div><dt>Entered by</dt><dd><?= e($v['created_by_name'] ?: 'System') ?></dd></div>
    <?php if (!$manual && isset($sources[$v['source_type']])): [$sp, $sl] = $sources[$v['source_type']]; ?>
      <div><dt>From</dt><dd><?php if (str_ends_with($sp, '/')): ?><a href="<?= e(url($sp . $v['source_id'])) ?>"><?= e($sl) ?></a><?php else: ?><a href="<?= e(url($sp)) ?>"><?= e($sl) ?></a><?php endif; ?></dd></div>
    <?php endif; ?>
    <?php if ($v['reverses_number']): ?><div><dt>Reverses</dt><dd><a href="<?= e(url('/books/vouchers/' . $v['reverses_id'])) ?>"><?= e($v['reverses_number']) ?></a></dd></div><?php endif; ?>
    <?php if ($v['reversed_by_number']): ?><div><dt>Reversed by</dt><dd><a href="<?= e(url('/books/vouchers/' . $v['reversed_by_id'])) ?>"><?= e($v['reversed_by_number']) ?></a></dd></div><?php endif; ?>
  </dl>
  <?php if ($v['narration']): ?><p><?= e($v['narration']) ?></p><?php endif; ?>
</section>
<div class="card flush">
<table class="table">
  <thead><tr><th>Account</th><th>Party</th><th>Note</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr>
      <td><a href="<?= e(url('/books/ledger?account=' . $l['account_id'])) ?>"><?= e($l['code']) ?> <?= e($l['account_name']) ?></a></td>
      <td><?= e($l['party_name']) ?></td>
      <td><?= e($l['note']) ?></td>
      <td class="num"><?= (float) $l['debit'] > 0 ? e(fmt_num($l['debit'])) : '' ?></td>
      <td class="num"><?= (float) $l['credit'] > 0 ? e(fmt_num($l['credit'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="subtotal"><td colspan="3">Total</td><td class="num"><?= e(fmt_num($totalDr)) ?></td><td class="num"><?= e(fmt_num($totalCr)) ?></td></tr></tfoot>
</table>
</div>
<?php if (!$manual): ?><p class="muted">This voucher was posted by a document. To change it, cancel the document; the books are reversed automatically.</p><?php endif; ?>
