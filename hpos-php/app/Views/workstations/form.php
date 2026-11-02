<?php /** @var ?array $ws @var array $row @var ?string $error */
$isNew = $ws === null; ?>
<h1><?= $isNew ? 'New workstation' : 'Edit workstation' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/workstations' : '/workstations/' . $ws['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= field('code', 'Code', $row['code'] ?? '', ['required' => true, 'maxlength' => 40]) ?>
    <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
    <?= field('hourly_rate', 'Hourly rate', $row['hourly_rate'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0']) ?>
    <?= select_field('status', 'Status', $row['status'] ?? 'IDLE', App\Controllers\WorkstationController::STATUSES) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url($isNew ? '/workstations' : '/workstations/' . $ws['id'])) ?>">Cancel</a>
    </div>
  </form>
</section>
