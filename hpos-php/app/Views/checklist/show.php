<?php /** @var array $job @var array<string,array> $steps @var array<string,array> $done */
$id = $job['job_card_id'];
$count = count($done);
$canTick = can('Production', 'create') && $job['status'] !== 'COMPLETED'; ?>
<div class="page-head">
  <h1><?= e($job['workstation_code']) ?> checklist <?= status_pill($job['status']) ?></h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/shop-floor')) ?>">Shop floor</a>
    <a class="btn" href="<?= e(url('/work-orders/' . $job['work_order_id'])) ?>"><?= e($job['work_order_number']) ?></a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Product</dt><dd><?= e($job['fg_code']) ?> <span class="muted"><?= e($job['fg_name']) ?></span></dd></div>
    <div><dt>Die</dt><dd><?= e($job['die_code'] ?: '-') ?></dd></div>
    <div><dt>Operator</dt><dd><?= e($job['operator_name'] ?: '-') ?></dd></div>
    <div><dt>Progress</dt><dd><strong><?= $count ?> of <?= count($steps) ?></strong> steps</dd></div>
  </dl>
  <progress class="meter" max="<?= count($steps) ?>" value="<?= $count ?>" aria-label="<?= $count ?> of <?= count($steps) ?> steps done"><?= $count ?></progress>
  <?php if ($count < count($steps) && $job['status'] === 'ACTIVE'): ?><p class="muted">This job is running with the checklist unfinished.</p><?php endif; ?>
</section>
<?php foreach ($steps as $key => [$label, $optional, $hint]): $d = $done[$key] ?? null; ?>
<section class="card step-card<?= $d ? ' step-done' : '' ?>">
  <div class="step-head">
    <h3><?= e($label) ?></h3>
    <?= $d ? ($d['status'] === 'DONE' ? pill('Done', 'ok') : pill('Not needed', 'neutral')) : pill('To do', 'warn') ?>
  </div>
  <p class="muted"><?= e($hint) ?></p>
  <?php if ($d): ?>
    <p class="step-meta"><?= $d['reading'] !== null ? '<strong>' . e(trim_zeros($d['reading'])) . ' bar</strong> &middot; ' : '' ?><?= e($d['done_by_name']) ?>, <?= e(fmt_dt($d['done_at'])) ?><?= $d['note'] ? ' &middot; ' . e($d['note']) : '' ?></p>
  <?php endif; ?>
  <?php if ($canTick): ?>
  <form method="post" action="<?= e(url("/job-cards/$id/checklist")) ?>" class="step-form">
    <?= App\Core\Csrf::field() ?>
    <input type="hidden" name="step" value="<?= e($key) ?>">
    <?php if ($key === 'AIR_PRESSURE'): ?><input name="reading" type="number" step="0.001" min="0" inputmode="decimal" placeholder="Bar" aria-label="Air pressure in bar" value="<?= e($d && $d['reading'] !== null ? trim_zeros($d['reading']) : '') ?>"><?php endif; ?>
    <input name="note" type="text" maxlength="200" placeholder="Note (optional)" aria-label="Note for <?= e($label) ?>" value="<?= e($d['note'] ?? '') ?>">
    <button class="btn btn-primary" type="submit" name="action" value="DONE"><?= $d && $d['status'] === 'DONE' ? 'Save' : 'Mark done' ?></button>
    <?php if ($optional && !$d): ?><button class="btn" type="submit" name="action" value="NOT_NEEDED">Not needed</button><?php endif; ?>
    <?php if ($d): ?><button class="btn btn-quiet" type="submit" name="action" value="RESET">Undo</button><?php endif; ?>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
