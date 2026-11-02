<?php /** @var ?array $supplier @var array $row @var ?string $error */
$isNew = $supplier === null; ?>
<h1><?= $isNew ? 'New supplier' : 'Edit supplier' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/suppliers' : '/suppliers/' . $supplier['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
    <?= field('gstin', 'GSTIN', $row['gstin'] ?? '', ['maxlength' => 15]) ?>
    <?= field('email', 'Email', $row['email'] ?? '', ['type' => 'email', 'maxlength' => 190]) ?>
    <?= field('phone', 'Phone', $row['phone'] ?? '', ['type' => 'tel', 'maxlength' => 40]) ?>
    <?= select_field('status', 'Status', $row['status'] ?? 'ACTIVE', ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url($isNew ? '/suppliers' : '/suppliers/' . $supplier['id'])) ?>">Cancel</a>
    </div>
  </form>
</section>
