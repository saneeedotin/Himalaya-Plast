<?php /**
 * @var array $order @var list<array> $lines @var list<array> $material @var bool $hasDeficit
 * @var list<array> $workOrders @var list<array> $jobCards @var list<array> $reservations
 * @var list<array> $deliveries @var list<array> $cartons @var list<array> $qc @var list<array> $invoices @var bool $editable
 */
$id = $order['id'];
$canUpdate = can('SalesOrders', 'update');
$started = (float) $order['produced_qty'] > 0; ?>
<div class="page-head">
  <h1><?= e($order['order_number']) ?> <?= status_pill($order['status']) ?><?= (int) $order['is_overdue'] === 1 ? ' ' . pill('Overdue', 'fail') : '' ?></h1>
  <div class="head-actions">
    <?php if ($editable && $canUpdate): ?><a class="btn" href="<?= e(url("/orders/$id/edit")) ?>">Edit</a><?php endif; ?>
    <?php if (in_array($order['status'], ['DRAFT', 'PROFORMA_SENT', 'CANCELLED'], true) && can('SalesOrders', 'delete')): ?>
      <?= action_form("/orders/$id/delete", 'Delete', 'Delete this order?', 'btn btn-danger') ?>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/orders')) ?>">All orders</a>
  </div>
</div>

<section class="card">
  <dl class="facts">
    <div><dt>Customer</dt><dd><?php if ($order['customer_id']): ?><a href="<?= e(url('/customers/' . $order['customer_id'])) ?>"><?= e($order['customer_name']) ?></a><?php else: ?><?= e($order['customer_name']) ?><?php endif; ?></dd></div>
    <div><dt>GSTIN</dt><dd><?= e($order['customer_gstin'] ?: '-') ?></dd></div>
    <div><dt>Order date</dt><dd><?= e(fmt_dt($order['transaction_date'])) ?></dd></div>
    <div><dt>Delivery by</dt><dd><?= e(substr((string) $order['delivery_date'], 0, 10)) ?></dd></div>
    <div><dt>Approved by</dt><dd><?= e(App\Controllers\OrderController::APPROVAL[$order['approval_method']] ?? $order['approval_method']) ?></dd></div>
    <div><dt>Total</dt><dd><?= e(fmt_num($order['total_amount'])) ?></dd></div>
    <?php if ($order['confirmed_at']): ?><div><dt>Confirmed</dt><dd><?= e(fmt_dt($order['confirmed_at'])) ?> by <?= e($order['confirmed_by_name']) ?></dd></div><?php endif; ?>
    <?php if ($order['proforma_ref']): ?><div><dt>Proforma</dt><dd><?= e($order['proforma_ref']) ?></dd></div><?php endif; ?>
  </dl>
  <?php if (!empty($projection)): $p = $projection; ?>
    <p class="delivery-check"><strong>Delivery check</strong> <?= health_pill($p['health']) ?>
      <?php if ($p['basis'] !== 'DONE'): ?>
        Production can finish about <strong><?= e(substr((string) $p['projected_finish'], 0, 10)) ?></strong>; earliest realistic delivery <strong><?= e(substr((string) $p['suggested_delivery'], 0, 10)) ?></strong>
        (<?= (int) $p['slack_days'] >= 0 ? (int) $p['slack_days'] . ' day' . ((int) $p['slack_days'] === 1 ? '' : 's') . ' spare' : abs((int) $p['slack_days']) . ' day' . (abs((int) $p['slack_days']) === 1 ? '' : 's') . ' late' ?> against the date above).
      <?php endif; ?>
      <span class="muted"><?= e(projection_reason($p)) ?> This is advice from the order history and the planning board; the delivery date is yours to set.</span></p>
  <?php endif; ?>
  <?php if ($order['notes']): ?><p class="notes"><?= nl2br(e($order['notes'])) ?></p><?php endif; ?>
</section>

<h2>Items</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Item</th><th>Unit</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> <?= e($l['name']) ?></td><td><?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="4" class="num">Total</td><td class="num"><?= e(fmt_num($order['total_amount'])) ?></td></tr></tfoot>
</table>
</div>

<h2>Raw material check</h2>
<?php if (!$material): ?>
  <p class="muted">None of the items on this order has a bill of materials, so there is nothing to check or reserve.
    <?php if (can('BOMs', 'create')): ?><a href="<?= e(url('/boms/new')) ?>">Add a BOM</a>.<?php endif; ?></p>
<?php else: ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Raw material</th><th class="num">Needed</th><th class="num">In stock</th><th class="num">Reserved for others</th><th class="num">Available</th><th class="num">Short by</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($material as $m): ?>
    <tr>
      <td><?= e($m['rm_code']) ?> <?= e($m['rm_name']) ?></td>
      <td class="num"><?= e(fmt_num($m['required_qty'])) ?> <?= e($m['rm_uom']) ?></td>
      <td class="num"><?= e(fmt_num($m['physical_stock'])) ?></td>
      <td class="num"><?= e(fmt_num($m['reserved_stock'])) ?></td>
      <td class="num"><?= e(fmt_num($m['available_stock'])) ?></td>
      <td class="num"><?= (float) $m['deficit_qty'] > 0 ? e(fmt_num($m['deficit_qty'], 0)) : '' ?></td>
      <td><?= status_pill($m['status']) ?><?php if ($m['status'] === 'DEFICIT' && isset($incoming[$m['rm_item_id']])): $in = $incoming[$m['rm_item_id']]; ?>
        <br><span class="muted"><?= e(fmt_num($in['incoming_qty'], 0)) ?> on order<?= $in['po_number'] ? ' (' . e($in['po_number']) . ')' : '' ?><?= (float) $in['incoming_qty'] >= (float) $m['deficit_qty'] ? ': covers the shortage once received' : '' ?></span>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($editable && $canUpdate): ?>
<h2>Confirm</h2>
<section class="card">
  <?php if ($hasDeficit): ?>
    <div class="alert alert-error" role="alert">There is not enough raw material to confirm this order. Order what is missing first.</div>
    <?php if (can('PurchaseOrders', 'create')): ?>
      <?= action_form("/orders/$id/shortage-po", 'Create purchase order for the shortage', null, 'btn btn-primary') ?>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">Confirming reserves the raw material and creates a work order for each finished-good line.</p>
    <form method="post" action="<?= e(url("/orders/$id/confirm")) ?>" data-confirm="Confirm this order and reserve the materials?">
      <?= App\Core\Csrf::field() ?>
      <div class="grid-2">
        <div><?= select_field('approval_method', 'Approved by', $order['approval_method'], App\Controllers\OrderController::APPROVAL) ?></div>
        <div><?= field('notes', 'Note (optional)', '', ['maxlength' => 500]) ?></div>
      </div>
      <button class="btn btn-primary" type="submit">Confirm order</button>
    </form>
  <?php endif; ?>

  <div class="form-actions">
    <?php if ($order['status'] === 'DRAFT'): ?>
      <form method="post" action="<?= e(url("/orders/$id/status")) ?>" class="inline-form">
        <?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="PROFORMA_SENT">
        <?= field('proforma_ref', 'Proforma reference', '', ['maxlength' => 80]) ?>
        <button class="btn" type="submit">Mark proforma sent</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($canUpdate && !$started && in_array($order['status'], ['DRAFT', 'PROFORMA_SENT', 'CONFIRMED'], true)): ?>
  <div class="form-actions">
    <form method="post" action="<?= e(url("/orders/$id/status")) ?>" class="inline" data-confirm="Cancel this order? Reserved material is released.">
      <?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="CANCELLED">
      <button class="btn btn-danger" type="submit">Cancel order</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($workOrders): ?>
<h2>Work orders</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Work order</th><th>Item</th><th>Status</th><th>Batch</th><th class="num">Planned</th><th class="num">Produced</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($workOrders as $w): ?>
    <tr><td><?php if (can('WorkOrders', 'read')): ?><a href="<?= e(url('/work-orders/' . $w['id'])) ?>"><?= e($w['work_order_number']) ?></a><?php else: ?><?= e($w['work_order_number']) ?><?php endif; ?></td><td><?= e($w['fg_code']) ?> <?= e($w['fg_name']) ?></td><td><?= status_pill($w['status']) ?></td><td><?= e($w['fg_batch_number']) ?></td><td class="num"><?= e(fmt_num($w['planned_qty'], 0)) ?></td><td class="num"><?= e(fmt_num($w['produced_qty'], 0)) ?></td>
      <td class="actions"><?php if ($w['fg_batch_number'] && can('QC', 'create')): ?><a href="<?= e(url('/quality/new?batch=' . rawurlencode($w['fg_batch_number']))) ?>">Inspect</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($jobCards): ?>
<h2>Job cards</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Line</th><th>Status</th><th>Operator</th><th>Die</th><th>Started</th><th class="num">Good</th><th class="num">Scrap</th></tr></thead>
  <tbody>
  <?php foreach ($jobCards as $j): ?>
    <tr><td><?= e($j['workstation_code']) ?></td><td><?= status_pill($j['status']) ?></td><td><?= e($j['operator_name']) ?></td><td><?= e($j['die_code']) ?></td><td><?= e(fmt_dt($j['started_at'])) ?></td><td class="num"><?= e(fmt_num($j['good_qty'], 3)) ?></td><td class="num"><?= e(fmt_num($j['scrap_qty'], 3)) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($reservations): ?>
<h2>Reserved material</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Raw material</th><th class="num">Quantity</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($reservations as $r): ?>
    <tr><td><?= e($r['code']) ?> <?= e($r['name']) ?></td><td class="num"><?= e(fmt_num($r['quantity'], 3)) ?> <?= e($r['uom']) ?></td><td><?= status_pill($r['status']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if (can('Dispatch', 'create') && in_array($order['status'], ['IN_PRODUCTION', 'READY_TO_DISPATCH', 'DISPATCH_PREP', 'CONFIRMED', 'APPROVED'], true)): ?>
  <p><a class="btn" href="<?= e(url('/dispatch/new?order=' . $id)) ?>">Pack this order</a></p>
<?php endif; ?>
<?php if ($deliveries || $cartons): ?>
<h2>Dispatch</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Delivery note</th><th>Status</th><th>Transporter</th><th>Vehicle</th><th>LR</th><th>Dispatched</th></tr></thead>
  <tbody>
  <?php foreach ($deliveries as $d): ?>
    <tr><td><?= e($d['dn_number']) ?></td><td><?= status_pill($d['status']) ?></td><td><?= e($d['transporter_name']) ?></td><td><?= e($d['vehicle_number']) ?></td><td><?= e($d['lr_number']) ?></td><td><?= e(fmt_dt($d['dispatched_at'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($cartons): ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Carton</th><th>Batch</th><th class="num">Quantity</th><th>Scanned</th></tr></thead>
  <tbody>
  <?php foreach ($cartons as $c): ?>
    <tr><td><?= e($c['carton_code']) ?></td><td><?= e($c['batch_number']) ?></td><td class="num"><?= e(fmt_num($c['quantity'], 3)) ?></td><td><?= (int) $c['scanned'] === 1 ? e(fmt_dt($c['scanned_at'])) . ' ' . e($c['scanned_by_name']) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($qc): ?>
<h2>Quality reports</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Report</th><th>Batch</th><th>Result</th><th>Inspector</th><th>When</th></tr></thead>
  <tbody>
  <?php foreach ($qc as $q): ?>
    <tr><td><?= e($q['report_number']) ?></td><td><?= e($q['batch_number']) ?></td><td><?= status_pill($q['status']) ?></td><td><?= e($q['inspector_name']) ?></td><td><?= e(fmt_dt($q['inspected_at'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($invoices): ?>
<h2>Invoices</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Invoice</th><th>Status</th><th>Due</th><th class="num">Total</th><th class="num">Outstanding</th></tr></thead>
  <tbody>
  <?php foreach ($invoices as $i): ?>
    <tr><td><?= e($i['invoice_number']) ?></td><td><?= status_pill($i['status']) ?></td><td><?= e(substr((string) $i['due_date'], 0, 10)) ?></td><td class="num"><?= e(fmt_num($i['total_amount'])) ?></td><td class="num"><?= e(fmt_num($i['outstanding'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
