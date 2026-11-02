<?php /** @var ?array $customer @var array $row @var ?string $error */
$isNew = $customer === null; ?>
<h1><?= $isNew ? 'New customer' : 'Edit customer' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/customers' : '/customers/' . $customer['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
    <?= field('gstin', 'GSTIN', $row['gstin'] ?? '', ['maxlength' => 15, 'hint' => 'Optional. Decides CGST+SGST or IGST on invoices.']) ?>
    <?= field('email', 'Email', $row['email'] ?? '', ['type' => 'email', 'maxlength' => 190]) ?>
    <?= field('phone', 'Phone', $row['phone'] ?? '', ['type' => 'tel', 'maxlength' => 40]) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url($isNew ? '/customers' : '/customers/' . $customer['id'])) ?>">Cancel</a>
    </div>
  </form>
</section>
