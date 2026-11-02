<?php /** @var array $row @var array<string,string> $customers @var array<string,string> $suppliers @var ?string $error */
$type = $row['party_type'] ?? 'CUSTOMER'; ?>
<h1>Record a payment</h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/payments')) ?>">
    <?= App\Core\Csrf::field() ?>
    <input type="hidden" name="sales_invoice_id" value="<?= e($row['sales_invoice_id'] ?? '') ?>">
    <input type="hidden" name="purchase_invoice_id" value="<?= e($row['purchase_invoice_id'] ?? '') ?>">
    <?php if (!empty($row['sales_invoice_id']) || !empty($row['purchase_invoice_id'])): ?>
      <p class="muted">Settling an invoice. The party is fixed by the invoice.</p>
      <input type="hidden" name="party_type" value="<?= e($type) ?>">
    <?php else: ?>
      <?= select_field('party_type', 'This is', $type, ['CUSTOMER' => 'Money received from a customer', 'SUPPLIER' => 'Money paid to a supplier']) ?>
    <?php endif; ?>
    <label for="f_party_id"><?= $type === 'CUSTOMER' ? 'Customer' : 'Supplier' ?></label>
    <select id="f_party_id" name="party_id" required>
      <option value="">Choose...</option>
      <optgroup label="Customers" data-party="CUSTOMER"><?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= ($row['party_id'] ?? '') === $id && $type === 'CUSTOMER' ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></optgroup>
      <optgroup label="Suppliers" data-party="SUPPLIER"><?php foreach ($suppliers as $id => $n): ?><option value="<?= e($id) ?>"<?= ($row['party_id'] ?? '') === $id && $type === 'SUPPLIER' ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></optgroup>
    </select>
    <?php if (empty($row['sales_invoice_id']) && empty($row['purchase_invoice_id'])): ?>
    <label for="f_invoice_ref">Settle an invoice (optional)</label>
    <select id="f_invoice_ref" name="invoice_ref">
      <option value="">On account, not tied to an invoice</option>
      <?php foreach ($openInvoices as $inv): ?>
        <option value="<?= e($inv['ref']) ?>" data-party="<?= e($inv['party']) ?>" data-outstanding="<?= e($inv['outstanding']) ?>"><?= e($inv['number']) ?> (owing <?= e(fmt_num($inv['outstanding'])) ?>)</option>
      <?php endforeach; ?>
    </select>
    <p class="hint">Pick the customer or supplier first; their unpaid invoices appear here. Leave it on account to record money that is not for one invoice.</p>
    <?php endif; ?>
    <?= field('amount', 'Amount', $row['amount'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]) ?>
    <?= select_field('mode', 'Mode', $row['mode'] ?? 'BANK_TRANSFER', App\Controllers\PaymentController::MODES) ?>
    <?= select_field('account_id', 'Cash or bank account', $row['account_id'] ?? '', $cashAccounts, ['blank' => 'Chosen by the mode (cash, cheque or bank)']) ?>
    <p class="hint">Pick the bank account or wallet the money moved through. Left alone, cash goes to Cash in Hand, a customer's cheque to Cheques in Hand and anything else to Bank Account.</p>
    <?= field('reference', 'Reference (UTR, cheque no.)', $row['reference'] ?? '', ['maxlength' => 80]) ?>
    <?= field('payment_date', 'Date', $row['payment_date'] ?? date('Y-m-d'), ['type' => 'date']) ?>
    <label class="check"><input type="checkbox" name="post_now" value="1"<?= ($row['post_now'] ?? '1') === '1' ? ' checked' : '' ?>> Post it now (an unposted payment does not change the invoice balance)</label>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save payment</button>
      <a class="btn" href="<?= e(url('/payments')) ?>">Cancel</a>
    </div>
  </form>
</section>
