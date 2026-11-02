<?php /** @var list<array> $cards @var list<array> $workstations @var array<string,array> $openStops @var array<string,string> $reasons */
$canLog = can('Production', 'create');
$canUpdate = can('Production', 'update'); ?>
<div class="page-head">
  <h1>Shop floor</h1>
  <div class="head-actions">
    <?php if (can('Production', 'create')): ?><a class="btn" href="<?= e(url('/scan/mixing')) ?>">Mixing check</a><?php endif; ?>
    <?php if (can('Stock', 'create')): ?><a class="btn" href="<?= e(url('/stock/receive')) ?>">Receive material</a><?php endif; ?>
    <a class="btn" href="<?= e(url('/handovers')) ?>">Handovers</a>
  </div>
</div>

<div class="floor-grid">
<?php foreach ($cards as $c):
    $planned = (float) $c['planned_qty'];
    $done = (float) $c['produced_qty'];
    $pct = $planned > 0 ? min(100, round(100 * $done / $planned)) : 0; ?>
  <section class="card floor-card">
    <div class="floor-head">
      <h2><?= e($c['workstation_code']) ?> <small><?= e($c['workstation_name']) ?></small></h2>
      <?= status_pill($c['status']) ?>
    </div>
    <p class="floor-job"><a href="<?= e(url('/work-orders/' . $c['work_order_id'])) ?>"><?= e($c['work_order_number']) ?></a> &middot; <?= e($c['fg_name']) ?>
      <?php if ($c['operator_name']): ?><span class="muted"> &middot; <?= e($c['operator_name']) ?></span><?php endif; ?></p>
    <progress class="meter" max="100" value="<?= (int) $pct ?>" aria-label="<?= (int) $pct ?> percent of the work order made"><?= (int) $pct ?>%</progress>
    <p class="floor-numbers"><strong><?= e(fmt_num($done, 0)) ?></strong> of <?= e(fmt_num($planned, 0)) ?> m
      <span class="muted">&middot; this card <?= e(fmt_num($c['good_qty'], 0)) ?> m good, <?= e(fmt_num($c['scrap_qty'], 1)) ?> kg scrap</span></p>

    <?php if (!empty($atMachine[$c['workstation_id']])): ?>
      <p class="floor-material">
        <?php foreach ($atMachine[$c['workstation_id']] as $l): ?>
          <span class="chip<?= $l['status'] === 'LOW' ? ' chip-low' : '' ?>"><?= e($l['item_code']) ?> <?= e(fmt_num($l['on_machine'], 0)) ?> <?= e($l['uom']) ?><?= $l['hours_left'] !== null ? ', about ' . e(trim_zeros($l['hours_left'])) . ' h left' : '' ?><?= $l['status'] === 'LOW' ? ' (low)' : '' ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if ($c['status'] === 'QUEUED' && $canUpdate): ?>
      <form method="post" action="<?= e(url('/job-cards/' . $c['job_card_id'] . '/status')) ?>">
        <?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="ACTIVE"><input type="hidden" name="back" value="/shop-floor">
        <button class="btn btn-primary btn-lg btn-block" type="submit">Start this job</button>
      </form>
    <?php endif; ?>

    <?php if ($canLog && $c['status'] === 'ACTIVE'): ?>
      <div class="preset-row">
        <?php foreach ([100, 500, 1000] as $m): ?>
          <form method="post" action="<?= e(url('/production/log')) ?>">
            <?= App\Core\Csrf::field() ?><input type="hidden" name="job_card_id" value="<?= e($c['job_card_id']) ?>"><input type="hidden" name="type" value="METERS"><input type="hidden" name="amount" value="<?= $m ?>"><input type="hidden" name="back" value="/shop-floor">
            <button class="btn btn-primary btn-lg" type="submit">+<?= number_format($m) ?> m</button>
          </form>
        <?php endforeach; ?>
      </div>
      <form method="post" action="<?= e(url('/production/log')) ?>" class="log-row">
        <?= App\Core\Csrf::field() ?><input type="hidden" name="job_card_id" value="<?= e($c['job_card_id']) ?>"><input type="hidden" name="type" value="METERS"><input type="hidden" name="back" value="/shop-floor">
        <input name="amount" type="number" step="0.001" min="0" inputmode="decimal" placeholder="Metres" aria-label="Metres made">
        <button class="btn btn-lg" type="submit">Add metres</button>
      </form>
      <form method="post" action="<?= e(url('/production/log')) ?>" class="log-row">
        <?= App\Core\Csrf::field() ?><input type="hidden" name="job_card_id" value="<?= e($c['job_card_id']) ?>"><input type="hidden" name="type" value="SCRAP"><input type="hidden" name="back" value="/shop-floor">
        <input name="amount" type="number" step="0.001" min="0" inputmode="decimal" placeholder="Scrap kg" aria-label="Scrap in kilograms">
        <button class="btn btn-lg" type="submit">Add scrap</button>
      </form>
    <?php endif; ?>

    <div class="form-actions">
      <?php $nDone = $stepsDone[$c['job_card_id']] ?? 0; ?>
      <a class="btn" href="<?= e(url('/job-cards/' . $c['job_card_id'] . '/checklist')) ?>">Checklist <?= $nDone ?>/<?= count(App\Controllers\JobChecklistController::STEPS) ?></a>
      <?php if (can('Production', 'create')): ?><a class="btn" href="<?= e(url('/scan/mixing?job=' . $c['job_card_id'])) ?>">Mixing check</a><?php endif; ?>
      <?php if ($canUpdate && $c['status'] === 'ACTIVE'): ?>
        <form method="post" action="<?= e(url('/job-cards/' . $c['job_card_id'] . '/status')) ?>" class="inline" data-confirm="Mark this job card complete?">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="COMPLETED"><input type="hidden" name="back" value="/shop-floor">
          <button class="btn" type="submit">Complete</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
<?php if (!$cards): ?><section class="card"><p class="muted">No job cards are queued or running. Create one from a <a href="<?= e(url('/work-orders')) ?>">work order</a>.</p></section><?php endif; ?>
</div>

<h2>Machines</h2>
<div class="floor-grid">
<?php foreach ($workstations as $w): $stop = $openStops[$w['id']] ?? null; ?>
  <section class="card floor-card">
    <div class="floor-head">
      <h3><?= e($w['code']) ?> <small><?= e($w['name']) ?></small></h3>
      <?= pill(ucfirst(strtolower($w['status'])), $w['status'] === 'RUNNING' ? 'ok' : ($w['status'] === 'MAINTENANCE' ? 'fail' : 'neutral')) ?>
    </div>
    <?php if ($stop): ?>
      <p>Stopped since <?= e(fmt_dt($stop['start_time'])) ?>: <?= e($reasons[$stop['reason_code']] ?? $stop['reason_code']) ?></p>
      <?php if ($canUpdate): ?>
        <form method="post" action="<?= e(url('/downtime/' . $stop['id'] . '/end')) ?>">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="back" value="/shop-floor">
          <button class="btn btn-primary btn-lg btn-block" type="submit">Back in service</button>
        </form>
      <?php endif; ?>
    <?php elseif ($canLog): ?>
      <form method="post" action="<?= e(url('/downtime/start')) ?>" class="log-row">
        <?= App\Core\Csrf::field() ?><input type="hidden" name="workstation_id" value="<?= e($w['id']) ?>"><input type="hidden" name="back" value="/shop-floor">
        <select name="reason_code" aria-label="Reason for the stop" required>
          <option value="">Reason for stop...</option>
          <?php foreach ($reasons as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-lg" type="submit">Report stop</button>
      </form>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
