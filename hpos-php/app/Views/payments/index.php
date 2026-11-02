<?php /** @var list<array> $payments */
$modes = App\Controllers\PaymentController::MODES; ?>
<div class="page-head">
  <h1>Payments</h1>
  <?php if (can('Accounts', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/payments/new')) ?>">Record payment</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Payment</th><th>Date</th><th>Party</th><th>Invoice</th><th>Mode</th><th>Reference</th><th>Status</th><th class="num">Amount</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= e($p['payment_number']) ?></td>
      <td><?= e(substr((string) $p['payment_date'], 0, 10)) ?></td>
      <td><?= e($p['party_name']) ?> <small class="muted"><?= $p['party_type'] === 'CUSTOMER' ? 'in' : 'out' ?></small></td>
      <td><?= e($p['sales_invoice_number'] ?: $p['purchase_invoice_number'] ?: 'On account') ?></td>
      <td><?= e($modes[$p['mode_of_payment']] ?? $p['mode_of_payment']) ?></td>
      <td><?= e($p['reference_number']) ?></td>
      <td><?= status_pill($p['status']) ?></td>
      <td class="num"><?= e(fmt_num($p['amount'])) ?></td>
      <td class="actions">
        <?php if ($p['status'] === 'DRAFT' && can('Accounts', 'update')): ?><?= action_form('/payments/' . $p['id'] . '/submit', 'Post', null, 'link') ?><?php endif; ?>
        <?php if ($p['status'] !== 'CANCELLED' && can('Accounts', 'delete')): ?><?= action_form('/payments/' . $p['id'] . '/cancel', 'Cancel', 'Cancel this payment?', 'link') ?><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$payments): ?><tr><td colspan="9" class="muted">No payments yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
