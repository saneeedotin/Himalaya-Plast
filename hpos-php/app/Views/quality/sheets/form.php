<?php /** @var array $row @var list<array> $jobCards @var array<string,array> $labels @var ?string $error */ ?>
<div class="page-head"><h1>Start a QC sheet</h1><a class="btn" href="<?= e(url('/quality/sheets')) ?>">QC sheets</a></div>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/quality/sheets')) ?>">
    <?= App\Core\Csrf::field() ?>
    <label for="f_job_card_id">Job card <span class="req" aria-hidden="true">*</span></label>
    <select id="f_job_card_id" name="job_card_id" required>
      <option value="">Choose the running job...</option>
      <?php foreach ($jobCards as $j): ?>
        <option value="<?= e($j['job_card_id']) ?>"<?= ($row['job_card_id'] ?? '') === $j['job_card_id'] ? ' selected' : '' ?>><?= e($j['workstation_code']) ?> · <?= e($j['item_code']) ?> · <?= e($j['batch_number'] ?: 'no batch yet') ?> · <?= e(ucfirst(strtolower($j['status']))) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint">The machine, die, product code and batch come from the job. A job gets its batch when the first metres are logged on the shop floor.</p>
    <?php if ($labels['MFG_DATE']['visible']): ?><?= field('mfg_date', $labels['MFG_DATE']['label'], $row['mfg_date'] ?? date('Y-m-d'), ['type' => 'date', 'max' => date('Y-m-d')]) ?><?php endif; ?>
    <?php if ($labels['DTD']['visible']): ?><?= field('dtd', $labels['DTD']['label'], $row['dtd'] ?? '', ['type' => 'date']) ?><?php endif; ?>
    <?php if ($labels['M_NO']['visible']): ?><?= field('m_no', $labels['M_NO']['label'], $row['m_no'] ?? '', ['maxlength' => 20, 'hint' => 'Leave empty to use the machine\'s code.']) ?><?php endif; ?>
    <?php if ($labels['METRES']['visible']): ?><?= field('metres', $labels['METRES']['label'], $row['metres'] ?? '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'hint' => 'Leave empty to use the work order quantity.']) ?><?php endif; ?>
    <?php if ($labels['COLOUR']['visible']): ?><?= field('colour', $labels['COLOUR']['label'], $row['colour'] ?? '', ['maxlength' => 40, 'hint' => 'Leave empty to use the colour set on the item.']) ?><?php endif; ?>
    <?php foreach (['X1', 'X2', 'X3'] as $k): if ($labels[$k]['visible']): ?><?= field(strtolower($k), $labels[$k]['label'], $row[strtolower($k)] ?? '', ['maxlength' => 80]) ?><?php endif; endforeach; ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Start sheet</button>
      <a class="btn" href="<?= e(url('/quality/sheets')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php if (!$jobCards): ?><p class="muted">There is no running or queued job to start a sheet on. Create a job card on a work order first.</p><?php endif; ?>
