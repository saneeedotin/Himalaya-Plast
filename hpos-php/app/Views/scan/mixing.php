<?php /** @var array $row @var array<string,string> $cards @var array<string,string> $lots @var ?array $result */ ?>
<h1>Mixing check</h1>
<section class="card card-narrow">
  <p class="muted">Choose the two raw-material lots going into the hopper. Both must be on the job's bill of materials. Every check is recorded.</p>
  <?php if ($result !== null): ?>
    <?php if ((int) $result['success'] === 1): ?>
      <div class="alert alert-success" role="status">Match. The lots are on the BOM.</div>
    <?php else: ?>
      <div class="alert alert-error" role="alert"><?= e($result['error']) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/scan/mixing')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('job_card_id', 'Job card', $row['job_card_id'] ?? '', $cards, ['blank' => 'Choose...', 'required' => true]) ?>
    <?= select_field('barcode_1', 'First lot', '', $lots, ['blank' => 'Choose a lot...', 'required' => true, 'empty' => ['/stock/receive', 'Receive some raw material']]) ?>
    <?= select_field('barcode_2', 'Second lot', '', $lots, ['blank' => 'Choose a lot...', 'required' => true]) ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Check</button>
  </form>
</section>
