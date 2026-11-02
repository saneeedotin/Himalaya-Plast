<?php /** @var array $dn @var list<array> $cartons */
$id = $dn['id'];
$scanned = count(array_filter($cartons, static fn (array $c): bool => (int) $c['scanned'] === 1));
$qty = array_sum(array_map(static fn (array $c): float => (float) $c['quantity'], $cartons));
$draft = $dn['status'] === 'DRAFT'; ?>
<div class="page-head">
  <h1><?= e($dn['dn_number']) ?> <?= status_pill($dn['status']) ?></h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url("/dispatch/$id/labels")) ?>" target="_blank" rel="noopener">Print labels</a>
    <a class="btn" href="<?= e(url("/dispatch/$id/print")) ?>" target="_blank" rel="noopener">Print delivery note</a>
    <a class="btn" href="<?= e(url('/dispatch')) ?>">All delivery notes</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Order</dt><dd><a href="<?= e(url('/orders/' . $dn['sales_order_id'])) ?>"><?= e($dn['order_number']) ?></a></dd></div>
    <div><dt>Customer</dt><dd><?= e($dn['customer_name']) ?></dd></div>
    <div><dt>Cartons loaded</dt><dd><?= $scanned ?> / <?= count($cartons) ?></dd></div>
    <div><dt>Total quantity</dt><dd><?= e(fmt_num($qty, 0)) ?> m</dd></div>
    <div><dt>Transporter</dt><dd><?= e($dn['transporter_name'] ?: '-') ?></dd></div>
    <div><dt>Vehicle</dt><dd><?= e($dn['vehicle_number'] ?: '-') ?></dd></div>
    <div><dt>LR number</dt><dd><?= e($dn['lr_number'] ?: '-') ?></dd></div>
    <?php if ($dn['dispatched_at']): ?><div><dt>Dispatched</dt><dd><?= e(fmt_dt($dn['dispatched_at'])) ?></dd></div><?php endif; ?>
  </dl>
</section>

<?php $canLoad = can('Dispatch', 'scan') && $scanned < count($cartons); ?>
<?php if ($canLoad): ?><form method="post" action="<?= e(url("/dispatch/$id/load")) ?>"><?= App\Core\Csrf::field() ?><?php endif; ?>
<div class="card flush">
<table class="table">
  <thead><tr><?php if ($canLoad): ?><th>Load</th><?php endif; ?><th>Carton</th><th>Batch</th><th>Item</th><th class="num">Metres</th><th>Loaded</th></tr></thead>
  <tbody>
  <?php foreach ($cartons as $c): $isLoaded = (int) $c['scanned'] === 1; ?>
    <tr>
      <?php if ($canLoad): ?><td><?php if (!$isLoaded): ?><input type="checkbox" name="cartons[]" value="<?= e($c['carton_code']) ?>" aria-label="Load carton <?= e($c['carton_code']) ?>"><?php endif; ?></td><?php endif; ?>
      <td><?= e($c['carton_code']) ?></td><td><?= e($c['batch_number']) ?></td><td><?= e($c['item_name']) ?></td>
      <td class="num"><?= e(fmt_num($c['quantity'], 3)) ?></td>
      <td><?= $isLoaded ? e(fmt_dt($c['scanned_at'])) . ((int) $c['dispatch_override'] === 1 ? ' ' . pill('Re-scan', 'warn') : '') : pill('Not yet', 'neutral') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$cartons): ?><tr><td colspan="6" class="muted">No cartons yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($canLoad): ?>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Mark ticked cartons as loaded</button>
    <button class="btn" type="submit" name="all" value="1">Mark all as loaded</button>
  </div>
  <p class="hint">Loading a carton takes its metres out of stock. When the last carton is loaded the delivery note and the order become Dispatched.</p>
</form>
<?php endif; ?>

<?php if ($draft && can('Dispatch', 'create')): ?>
<section class="card">
  <h3>Add one carton</h3>
  <form method="post" action="<?= e(url("/dispatch/$id/cartons")) ?>" class="inline-form">
    <?= App\Core\Csrf::field() ?>
    <div><?= field('quantity', 'Metres', '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'required' => true]) ?></div>
    <div><?= field('carton_code', 'Carton code (optional)', '', ['maxlength' => 80, 'hint' => 'Leave empty for the next serial number.']) ?></div>
    <button class="btn" type="submit">Add carton</button>
  </form>
</section>
<?php endif; ?>
<?php if (can('Dispatch', 'create')): ?>
<section class="card">
  <h3>Transport</h3>
  <form method="post" action="<?= e(url("/dispatch/$id/transport")) ?>" class="inline-form">
    <?= App\Core\Csrf::field() ?>
    <div><?= field('transporter', 'Transporter', $dn['transporter_name'] ?? '', ['maxlength' => 190]) ?></div>
    <div><?= field('vehicle', 'Vehicle number', $dn['vehicle_number'] ?? '', ['maxlength' => 40]) ?></div>
    <div><?= field('lr', 'LR number', $dn['lr_number'] ?? '', ['maxlength' => 60]) ?></div>
    <button class="btn" type="submit">Save</button>
  </form>
</section>
<?php endif; ?>
