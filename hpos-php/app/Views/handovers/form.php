<?php /** @var array $row @var array<string,string> $workstations @var ?string $error */ ?>
<h1>New handover</h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/handovers')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('shift_type', 'Shift you are handing over', $row['shift_type'] ?? 'MORNING', App\Controllers\HandoverController::SHIFTS) ?>
    <?= select_field('workstation_id', 'Machine', $row['workstation_id'] ?? '', $workstations, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/workstations/new', 'Add a workstation']]) ?>
    <?= field('scrap_qty', 'Scrap made this shift (kg)', $row['scrap_qty'] ?? '', ['type' => 'number', 'step' => '0.001', 'min' => '0']) ?>
    <?= textarea_field('notes', 'Notes for the next shift', $row['notes'] ?? '', ['rows' => 4]) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Record handover</button>
      <a class="btn" href="<?= e(url('/handovers')) ?>">Cancel</a>
    </div>
  </form>
</section>
