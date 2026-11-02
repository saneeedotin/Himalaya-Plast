<?php /** @var array $user @var ?array $kpi @var list<array> $alerts @var list<array> $todo */
$tile = static function (string $label, mixed $value, ?string $href = null, string $tone = ''): string {
    $inner = '<span class="tile-label">' . e($label) . '</span><span class="tile-value">' . e($value) . '</span>';
    $cls = 'tile' . ($tone !== '' ? ' tile-' . $tone : '');
    return $href !== null ? '<a class="' . $cls . '" href="' . e(url($href)) . '">' . $inner . '</a>' : '<div class="' . $cls . '">' . $inner . '</div>';
};
$stat = static function (string $label, string $value, string $note, ?string $href = null, bool $dark = false): string {
    $inner = '<span class="stat-label">' . e($label) . '</span><span class="stat-value">' . e($value) . '</span><span class="stat-note">' . e($note) . '</span>';
    $cls = 'stat' . ($dark ? ' stat-dark' : '');
    return $href !== null ? '<a class="' . $cls . '" href="' . e(url($href)) . '">' . $inner . '</a>' : '<div class="' . $cls . '">' . $inner . '</div>';
};
$first = explode(' ', trim((string) $user['name']))[0];
$shortcuts = array_values(array_filter([
    ['New order', 'Create a proforma', '/orders/new', 'shopping-cart', 'green', ['SalesOrders', 'create']],
    ['Shop floor', 'Log metres', '/shop-floor', 'cpu', 'purple', ['Production', 'read']],
    ['Quality', 'Inspect a batch', '/quality', 'shield-check', 'blue', ['QC', 'read']],
    ['Dispatch', 'Load cartons', '/dispatch', 'truck', 'amber', ['Dispatch', 'read']],
    ['Receive material', 'Add raw material', '/stock/receive', 'scan-line', 'teal', ['Stock', 'create']],
    ['Exports', 'Book Keeper, Tally', '/exports', 'download', 'rose', ['Accounts', 'read']],
], static fn (array $s): bool => can($s[5][0], $s[5][1])));
?>
<section class="hero">
  <div class="hero-eyebrow"><span class="chip">Factory</span><?php if ($kpi): ?><span><?= (int) $kpi['machines_running'] ?> machine<?= (int) $kpi['machines_running'] === 1 ? '' : 's' ?> running</span><?php endif; ?></div>
  <div class="hero-row">
    <div>
      <h1>Welcome back, <?= e($first) ?></h1>
      <?php if ($kpi): ?>
      <ul class="hero-list">
        <li><span class="bullet <?= (int) $kpi['machines_down'] > 0 ? 'bullet-bad' : 'bullet-ok' ?>"></span><span><b>Machines:</b> <?= (int) $kpi['machines_running'] ?> running, <?= (int) $kpi['machines_down'] ?> down</span></li>
        <li><span class="bullet bullet-run"></span><span><b>In production:</b> <a href="<?= e(url('/orders?status=IN_PRODUCTION')) ?>"><?= (int) $kpi['orders_in_production'] ?> order<?= (int) $kpi['orders_in_production'] === 1 ? '' : 's' ?></a> on the floor</span></li>
        <li><span class="bullet bullet-warn"></span><span><b>Waiting for QC:</b> <a href="<?= e(url('/quality?status=PENDING')) ?>"><?= (int) $kpi['qc_pending'] ?> inspection<?= (int) $kpi['qc_pending'] === 1 ? '' : 's' ?></a></span></li>
        <li><span class="bullet <?= (int) $kpi['overdue_orders'] > 0 ? 'bullet-bad' : 'bullet-info' ?>"></span><span><b>Overdue:</b> <a href="<?= e(url('/orders')) ?>"><?= (int) $kpi['overdue_orders'] ?> order<?= (int) $kpi['overdue_orders'] === 1 ? '' : 's' ?></a></span></li>
      </ul>
      <?php else: ?>
      <p class="hero-sub">Signed in as <?= e($user['email']) ?><?= !empty($user['roles']) ? ' · ' . e($user['roles']) : '' ?>.</p>
      <?php endif; ?>
    </div>
    <div class="hero-actions">
      <?php if (can('SalesOrders', 'create')): ?><a class="btn btn-white" href="<?= e(url('/orders/new')) ?>"><?= icon('plus') ?>New order</a><?php endif; ?>
      <?php if (can('Dispatch', 'read')): ?><a class="btn btn-ghost" href="<?= e(url('/dispatch')) ?>"><?= icon('truck') ?>Dispatch</a><?php endif; ?>
    </div>
  </div>
</section>

<?php if ($shortcuts): ?>
<div class="shortcuts">
  <?php foreach ($shortcuts as [$label, $sub, $path, $ico, $tone]): ?>
    <a class="shortcut" href="<?= e(url($path)) ?>"><span class="ico ico-<?= e($tone) ?>"><?= icon($ico) ?></span><span><b><?= e($label) ?></b><small class="muted"><?= e($sub) ?></small></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2>What to do today</h2>
<div class="card flush">
<?php if ($todo): ?>
<ul class="today-list">
  <?php foreach ($todo as $t): ?>
    <li>
      <span class="bullet bullet-<?= e($t['tone']) ?>" aria-hidden="true"></span>
      <span class="today-text">
        <a href="<?= e(url($t['url'])) ?>"><?= e($t['headline']) ?></a>
        <?php if ($t['detail']): ?><span class="muted"><?= e($t['detail']) ?></span><?php endif; ?>
      </span>
    </li>
  <?php endforeach; ?>
</ul>
<?php else: ?>
<p class="today-empty muted">Nothing needs doing right now.</p>
<?php endif; ?>
</div>
<p class="muted">These are suggestions worked out from your orders, stock, machines and quality sheets. Nothing is ordered or sent until a person does it.</p>

<?php if ($kpi): ?>
<div class="stats">
  <?= $stat('Metres made today', fmt_num($kpi['meters_today'], 0) . ' m', (int) $kpi['machines_running'] . ' machines running', '/shop-floor', true) ?>
  <?= $stat('Sales this month', '₹' . fmt_num($kpi['sales_this_month'], 0), (int) $kpi['open_orders'] . ' open orders', '/orders') ?>
  <?= $stat('In production', (string) (int) $kpi['orders_in_production'], 'orders on the floor now', '/orders?status=IN_PRODUCTION') ?>
  <?= $stat('Needs attention', (string) ((int) $kpi['overdue_orders'] + (int) $kpi['low_stock_items'] + (int) $kpi['qc_pending']), 'overdue, low stock and QC waiting', null) ?>
</div>

<h2>Today</h2>
<div class="tiles">
  <?= $tile('Open orders', (int) $kpi['open_orders'], '/orders') ?>
  <?= $tile('In production', (int) $kpi['orders_in_production'], '/orders?status=IN_PRODUCTION') ?>
  <?= $tile('Overdue orders', (int) $kpi['overdue_orders'], '/orders', (int) $kpi['overdue_orders'] > 0 ? 'bad' : '') ?>
  <?= $tile('Sales this month', fmt_num($kpi['sales_this_month'], 0), '/orders') ?>
  <?= $tile('Metres made today', fmt_num($kpi['meters_today'], 0), '/shop-floor') ?>
  <?= $tile('Machines running', (int) $kpi['machines_running'], '/shop-floor') ?>
  <?= $tile('Machines down', (int) $kpi['machines_down'], '/downtime', (int) $kpi['machines_down'] > 0 ? 'bad' : '') ?>
  <?= $tile('Low-stock items', (int) $kpi['low_stock_items'], '/stock?low=1', (int) $kpi['low_stock_items'] > 0 ? 'warn' : '') ?>
  <?= $tile('QC waiting', (int) $kpi['qc_pending'], '/quality?status=PENDING', (int) $kpi['qc_pending'] > 0 ? 'warn' : '') ?>
  <?= $tile('Receivables', fmt_num($kpi['receivables'], 0), can('Accounts', 'read') ? '/receivables' : null) ?>
  <?= $tile('Payables', fmt_num($kpi['payables'], 0), can('Accounts', 'read') ? '/payables' : null) ?>
  <?= $tile('Unread alerts', (int) $kpi['unread_notifications'], '/notifications', (int) $kpi['unread_notifications'] > 0 ? 'warn' : '') ?>
</div>
<?php endif; ?>

<?php if ($alerts): ?>
<h2>Needs your attention</h2>
<div class="card flush">
<ul class="alert-list">
  <?php foreach ($alerts as $n): ?>
    <li>
      <?= pill(ucfirst(strtolower(str_replace('_', ' ', $n['priority']))), $n['priority'] === 'CRITICAL' ? 'fail' : 'warn') ?>
      <?php if ($n['action_url']): ?><a href="<?= e(url($n['action_url'])) ?>"><?= e($n['title']) ?></a><?php else: ?><?= e($n['title']) ?><?php endif; ?>
      <span class="muted"><?= e($n['message']) ?></span>
    </li>
  <?php endforeach; ?>
</ul>
</div>
<p><a href="<?= e(url('/notifications')) ?>">All notifications</a></p>
<?php endif; ?>

<p class="muted">Use the search box at the top to find an order, customer, item, invoice or batch.<?php if (!empty($user['last_login_at'])): ?> Last sign-in: <?= e($user['last_login_at']) ?>.<?php endif; ?></p>
