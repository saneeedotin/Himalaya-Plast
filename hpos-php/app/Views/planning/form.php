<?php /** @var array $job @var array<string,string> $machines @var array $row @var ?string $error */
$id = $job['job_card_id'];
$canPlan = can('WorkOrders', 'update') && $job['status'] !== 'COMPLETED';
$queued = $job['status'] === 'QUEUED';
$planned = !empty($job['planned_start']); ?>
<div class="page-head">
  <h1>Plan <?= e($job['work_order_number']) ?> <?= status_pill($job['status']) ?></h1>
  <a class="btn" href="<?= e(url('/planning')) ?>">Planning board</a>
</div>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <dl class="facts">
    <div><dt>Product</dt><dd><?= e($job['fg_code']) ?> <span class="muted"><?= e($job['fg_name']) ?></span></dd></div>
    <div><dt>Metres</dt><dd><?= e(fmt_num($job['planned_qty'], 0)) ?> <span class="muted">(<?= e(fmt_num($job['produced_qty'], 0)) ?> made)</span></dd></div>
    <div><dt>Needed by</dt><dd><?= e($job['delivery_date'] ? substr((string) $job['delivery_date'], 0, 10) : '-') ?></dd></div>
    <div><dt>Die</dt><dd><?= e($job['die_code'] ?: '-') ?></dd></div>
  </dl>
  <?php if ($canPlan): ?>
  <form method="post" action="<?= e(url("/job-cards/$id/plan")) ?>">
    <?= App\Core\Csrf::field() ?>
    <?php if ($queued): ?>
      <?= select_field('workstation_id', 'Machine', $row['workstation_id'] ?? '', $machines, ['required' => true]) ?>
    <?php else: ?>
      <input type="hidden" name="workstation_id" value="<?= e($job['current_workstation_id']) ?>">
      <p class="muted">Machine: <strong><?= e($job['workstation_code']) ?></strong>. A running job stays on its machine.</p>
    <?php endif; ?>
    <?= field('planned_start', 'Start', $row['planned_start'] ?? '', ['type' => 'date', 'required' => true]) ?>
    <?= field('planned_end', 'End', $row['planned_end'] ?? '', ['type' => 'date', 'required' => true]) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save plan</button>
      <a class="btn" href="<?= e(url('/planning')) ?>">Cancel</a>
    </div>
  </form>
  <?php if ($planned): ?>
    <?= action_form("/job-cards/$id/plan/clear", 'Remove the plan', 'Remove this job from the board?', 'btn btn-quiet') ?>
  <?php endif; ?>
  <?php else: ?>
    <p class="muted"><?= $job['status'] === 'COMPLETED' ? 'This job is complete.' : 'You can see the plan but not change it.' ?>
      <?= $planned ? 'Planned ' . e(substr((string) $job['planned_start'], 0, 10)) . ' to ' . e(substr((string) $job['planned_end'], 0, 10)) . ' on ' . e($job['workstation_code']) . '.' : 'It is not planned.' ?></p>
  <?php endif; ?>
</section>
