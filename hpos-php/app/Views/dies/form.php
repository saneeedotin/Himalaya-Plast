<?php /** @var ?array $die @var array $row @var ?string $error */
$isNew = $die === null; ?>
<h1><?= $isNew ? 'New die' : 'Edit die' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/dies' : '/dies/' . $die['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= field('code', 'Code', $row['code'] ?? '', ['required' => true, 'maxlength' => 40]) ?>
    <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
    <?= field('maintenance_threshold_hours', 'Service after (hours)', $row['maintenance_threshold_hours'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'hint' => 'A "service due" flag shows once the die has run this long.']) ?>
    <?= select_field('status', 'Status', $row['status'] ?? 'ACTIVE', App\Controllers\DieController::STATUSES) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url('/dies')) ?>">Cancel</a>
    </div>
  </form>
</section>
