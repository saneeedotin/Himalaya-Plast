<?php /** @var array $wo @var list<array> $jobCards @var list<array> $requirements @var array<string,string> $workstations @var array<string,string> $dies */
$id = $wo['id'];
$statuses = App\Controllers\WorkOrderController::STATUSES; ?>
<div class="page-head">
  <h1><?= e($wo['work_order_number']) ?> <?= status_pill($wo['status']) ?></h1>
  <div class="head-actions">
    <?php if ($wo['sales_order_id']): ?><a class="btn" href="<?= e(url('/orders/' . $wo['sales_order_id'])) ?>">Order <?= e($wo['order_number']) ?></a><?php endif; ?>
    <?php if (can('WorkOrders', 'delete') && (float) $wo['produced_qty'] === 0.0): ?><?= action_form("/work-orders/$id/delete", 'Delete', 'Delete this work order?', 'btn btn-danger') ?><?php endif; ?>
    <a class="btn" href="<?= e(url('/work-orders')) ?>">All work orders</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Item</dt><dd><?= e($wo['fg_code']) ?> <?= e($wo['fg_name']) ?></dd></div>
    <div><dt>Planned</dt><dd><?= e(fmt_num($wo['planned_qty'], 3)) ?></dd></div>
    <div><dt>Produced</dt><dd><?= e(fmt_num($wo['produced_qty'], 3)) ?></dd></div>
    <div><dt>Batch</dt><dd><?= e($wo['fg_batch_number'] ?: '-') ?></dd></div>
    <div><dt>Started</dt><dd><?= e(fmt_dt($wo['start_date']) ?: '-') ?></dd></div>
    <div><dt>Finished</dt><dd><?= e(fmt_dt($wo['end_date']) ?: '-') ?></dd></div>
  </dl>
  <?php if (can('WorkOrders', 'update')): ?>
  <form method="post" action="<?= e(url("/work-orders/$id")) ?>" class="inline-form">
    <?= App\Core\Csrf::field() ?>
    <div><?= select_field('status', 'Status', $wo['status'], $statuses) ?></div>
    <div><?= field('planned_qty', 'Planned quantity', trim_zeros($wo['planned_qty']), ['type' => 'number', 'step' => '0.001', 'min' => '0']) ?></div>
    <div><?= field('fg_batch_number', 'Batch number', $wo['fg_batch_number'] ?? '', ['maxlength' => 80]) ?></div>
    <button class="btn" type="submit">Save</button>
  </form>
  <?php endif; ?>
</section>

<h2>Raw material for the full quantity</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Raw material</th><th class="num">Needed</th></tr></thead>
  <tbody>
  <?php foreach ($requirements as $r): ?>
    <tr><td><?= e($r['code']) ?> <?= e($r['name']) ?></td><td class="num"><?= e(fmt_num($r['planned_requirement'])) ?> <?= e($r['uom']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$requirements): ?><tr><td colspan="2" class="muted">The item has no BOM.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Job cards</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Line</th><th>Status</th><th>Operator</th><th>Die</th><th>Started</th><th class="num">Good</th><th class="num">Scrap</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobCards as $j): ?>
    <tr>
      <td><?= e($j['workstation_code']) ?> <?= e($j['workstation_name']) ?></td>
      <td><?= status_pill($j['status']) ?></td>
      <td><?= e($j['operator_name']) ?></td>
      <td><?= e($j['die_code']) ?></td>
      <td><?= e(fmt_dt($j['started_at'])) ?></td>
      <td class="num"><?= e(fmt_num($j['good_qty'], 3)) ?></td>
      <td class="num"><?= e(fmt_num($j['scrap_qty'], 3)) ?></td>
      <td class="actions">
        <?php if (can('WorkOrders', 'read') && $j['status'] !== 'COMPLETED'): ?><a href="<?= e(url('/job-cards/' . $j['job_card_id'] . '/plan')) ?>">Plan</a><?php endif; ?>
        <?php if (can('Production', 'read')): ?><a href="<?= e(url('/job-cards/' . $j['job_card_id'] . '/checklist')) ?>">Checklist</a><?php endif; ?>
        <?php if (can('Production', 'update') && $j['status'] !== 'COMPLETED'): ?>
          <?php if ($j['status'] === 'QUEUED'): ?>
            <form method="post" action="<?= e(url('/job-cards/' . $j['job_card_id'] . '/status')) ?>" class="inline"><?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="ACTIVE"><input type="hidden" name="back" value="/work-orders/<?= e($id) ?>"><button class="link" type="submit">Start</button></form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/job-cards/' . $j['job_card_id'] . '/status')) ?>" class="inline" data-confirm="Mark this job card complete?"><?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="COMPLETED"><input type="hidden" name="back" value="/work-orders/<?= e($id) ?>"><button class="link" type="submit">Complete</button></form>
        <?php endif; ?>
        <?php if (can('WorkOrders', 'delete') && (float) $j['good_qty'] === 0.0 && (float) $j['scrap_qty'] === 0.0): ?>
          <form method="post" action="<?= e(url('/job-cards/' . $j['job_card_id'] . '/delete')) ?>" class="inline" data-confirm="Delete this job card?"><?= App\Core\Csrf::field() ?><input type="hidden" name="back" value="/work-orders/<?= e($id) ?>"><button class="link" type="submit">Delete</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$jobCards): ?><tr><td colspan="8" class="muted">No job cards yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php if (can('WorkOrders', 'create') && $workstations): ?>
<section class="card">
  <h3>Add a job card</h3>
  <form method="post" action="<?= e(url("/work-orders/$id/job-cards")) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="grid-2">
      <div><?= select_field('workstation_id', 'Workstation', '', $workstations, ['blank' => 'Choose...', 'required' => true]) ?></div>
      <div><?= select_field('die_id', 'Die', '', $dies, ['blank' => 'No die']) ?></div>
    </div>
    <label class="check"><input type="checkbox" name="assign_me" value="1" checked> Assign to me</label>
    <button class="btn btn-primary" type="submit">Add job card</button>
  </form>
</section>
<?php endif; ?>
