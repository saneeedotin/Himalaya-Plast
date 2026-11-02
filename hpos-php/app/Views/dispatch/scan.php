<?php /** @var ?array $result @var ?string $refused @var string $code */ ?>
<h1>Dispatch scan</h1>
<section class="card card-narrow scan">
  <p class="muted">Scan each carton as it goes onto the vehicle. Stock leaves when a carton is scanned for the first time.</p>
  <?php if ($result): ?>
    <div class="alert alert-success scan-result" role="status">
      <?= e($result['message']) ?><br>
      <?= (int) $result['scanned_count'] ?> of <?= (int) $result['total_count'] ?> cartons scanned
      <?php if ((int) $result['is_complete'] === 1): ?><br><strong>Delivery note complete. The order is dispatched.</strong><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($refused): ?>
    <?php $friendly = ucfirst(preg_replace('/^[A-Z_]{4,}:\s*/', '', $refused)); /* "ALREADY_SCANNED: this carton..." -> "This carton..." */ ?>
    <div class="alert alert-error scan-result" role="alert"><?= e($friendly) ?><?= $code !== '' ? ' (' . e($code) . ')' : '' ?></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/scan/dispatch')) ?>">
    <?= App\Core\Csrf::field() ?>
    <label for="f_carton_code">Carton code</label>
    <input id="f_carton_code" name="carton_code" type="text" value="<?= e($code) ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus class="scan-input">
    <?php if ($refused && str_starts_with($refused, 'ALREADY_SCANNED')): ?>
      <fieldset>
        <legend>Supervisor re-scan</legend>
        <label class="check"><input type="checkbox" name="override" value="1"> Scan it again anyway</label>
        <?= field('override_note', 'Reason', '', ['maxlength' => 500]) ?>
      </fieldset>
    <?php endif; ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Scan</button>
  </form>
</section>
