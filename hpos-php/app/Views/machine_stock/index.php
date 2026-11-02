<?php /** @var array<string,string> $machines @var array<string,string> $materials @var array<string,array> $store @var list<array> $levels @var list<array> $recent @var int $lotSacks */
$canMove = can('Stock', 'create');
$kinds = ['ISSUE' => ['Issued', 'info'], 'RETURN' => ['Returned', 'neutral'], 'CONSUME' => ['Used', 'ok']]; ?>
<div class="page-head">
  <h1>Machine stock</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/stock')) ?>">Stock</a>
    <a class="btn" href="<?= e(url('/shop-floor')) ?>">Shop floor</a>
  </div>
</div>
<p class="muted">Raw material arrives in the store. Issue it to a machine in lots (about <?= (int) $lotSacks ?> sacks at a time); the machine uses its own stock first, and HPOS warns when a machine is about to run out. The total stock does not change: this only tracks where it is.</p>

<h2>At the machines</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Machine</th><th>Material</th><th class="num">At the machine</th><th class="num">Uses per hour</th><th class="num">Hours left</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($levels as $l): ?>
    <tr>
      <td><?= e($l['workstation_code']) ?> <span class="muted"><?= e($l['workstation_name']) ?></span></td>
      <td><?= e($l['item_code']) ?> <span class="muted"><?= e($l['item_name']) ?></span></td>
      <td class="num"><?= e(fmt_num($l['on_machine'], 1)) ?> <?= e($l['uom']) ?></td>
      <td class="num"><?= (float) $l['per_hour'] > 0 ? e(fmt_num($l['per_hour'], 2)) : '-' ?></td>
      <td class="num"><?= $l['hours_left'] !== null ? e(trim_zeros($l['hours_left'])) : '-' ?></td>
      <td><?= $l['status'] === 'LOW' ? pill((float) $l['on_machine'] <= 0 ? 'Ran out' : 'Running low', 'fail') : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$levels): ?><tr><td colspan="6" class="muted">No material has been issued to a machine yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($canMove): ?>
<div class="grid-2 books-grid">
  <section class="card">
    <h3>Issue to a machine</h3>
    <form method="post" action="<?= e(url('/machine-stock/issue')) ?>">
      <?= App\Core\Csrf::field() ?>
      <?= select_field('workstation_id', 'Machine', '', $machines, ['required' => true, 'blank' => 'Choose...', 'empty' => ['/workstations/new', 'Add a machine']]) ?>
      <?= select_field('item_id', 'Material', '', $materials, ['required' => true, 'blank' => 'Choose...']) ?>
      <?= field('bags', 'Number of sacks', (string) $lotSacks, ['type' => 'number', 'step' => '1', 'min' => '1', 'max' => '5000', 'required' => true]) ?>
      <?= field('weight', 'Weight of one sack', '25', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'required' => true, 'hint' => 'In the material\'s unit, usually kg.']) ?>
      <?= field('note', 'Note (optional)', '', ['maxlength' => 200]) ?>
      <button class="btn btn-primary" type="submit">Issue</button>
    </form>
  </section>
  <section class="card">
    <h3>Return to the store</h3>
    <form method="post" action="<?= e(url('/machine-stock/return')) ?>">
      <?= App\Core\Csrf::field() ?>
      <?= select_field('workstation_id', 'Machine', '', $machines, ['required' => true, 'blank' => 'Choose...']) ?>
      <?= select_field('item_id', 'Material', '', $materials, ['required' => true, 'blank' => 'Choose...']) ?>
      <input type="hidden" name="bags" value="1">
      <?= field('weight', 'Quantity to return', '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'required' => true]) ?>
      <?= field('note', 'Note (optional)', '', ['maxlength' => 200]) ?>
      <button class="btn" type="submit">Return</button>
    </form>
  </section>
</div>
<?php endif; ?>

<h2>In the store</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Material</th><th class="num">Total stock</th><th class="num">At machines</th><th class="num">In the store</th></tr></thead>
  <tbody>
  <?php foreach ($store as $i): ?>
    <tr><td><?= e($i['code']) ?> <span class="muted"><?= e($i['name']) ?></span></td><td class="num"><?= e(fmt_num($i['total_stock'], 1)) ?> <?= e($i['uom']) ?></td>
      <td class="num"><?= (float) $i['at_machines'] != 0.0 ? e(fmt_num($i['at_machines'], 1)) : '' ?></td><td class="num"><?= e(fmt_num((float) $i['total_stock'] - (float) $i['at_machines'], 1)) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$store): ?><tr><td colspan="4" class="muted">No raw material or packaging items yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Latest movements</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>When</th><th>Machine</th><th>Material</th><th>What</th><th class="num">Quantity</th><th>By</th><th>Note</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $m): [$label, $kind] = $kinds[$m['kind']]; ?>
    <tr>
      <td><?= e(fmt_dt($m['created_at'])) ?></td>
      <td><?= e($m['workstation_code']) ?></td>
      <td><?= e($m['item_code']) ?></td>
      <td><?= pill($label, $kind) ?></td>
      <td class="num"><?= e(fmt_num($m['qty'], 2)) ?> <?= e($m['uom']) ?><?= $m['bags'] ? ' <span class="muted">(' . (int) $m['bags'] . ' sacks)</span>' : '' ?></td>
      <td><?= e($m['by_name']) ?></td>
      <td class="muted"><?= e($m['note']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="7" class="muted">Nothing yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
