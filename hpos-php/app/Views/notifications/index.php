<?php /** @var list<array> $rows @var string $show */
$prio = ['CRITICAL' => 'fail', 'ACTION_REQUIRED' => 'warn', 'UPDATE' => 'info', 'SUCCESS' => 'ok'];
$tone = ['CRITICAL' => 'rose', 'ACTION_REQUIRED' => 'amber', 'UPDATE' => 'blue', 'SUCCESS' => 'green'];
$ico = ['ORDER' => 'shopping-cart', 'MATERIAL' => 'package', 'MACHINE' => 'cpu', 'QC' => 'shield-check', 'FINANCE' => 'wallet', 'CUSTOMER' => 'users'];
$unread = array_values(array_filter($rows, static fn (array $n): bool => $n['status'] === 'UNREAD'));
$shown = $show === 'all' ? $rows : $unread;
$back = '/notifications' . ($show === 'all' ? '?show=all' : ''); ?>
<div class="page-head">
  <h1>Notifications</h1>
  <?php if ($unread): ?><?= action_form('/notifications/read-all', 'Mark all as read', null, 'btn') ?><?php endif; ?>
</div>

<section class="card push-card" data-push-card>
  <div class="push-text">
    <h2>Push notifications</h2>
    <p class="muted" data-push-state>Checking this browser...</p>
    <p class="muted" data-push-result role="status"></p>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="button" data-push-enable hidden>Turn on for this browser</button>
    <button class="btn" type="button" data-push-test hidden>Send me a test</button>
    <button class="btn" type="button" data-push-disable hidden>Turn off</button>
  </div>
</section>

<nav class="tabs" aria-label="Filter">
  <a href="<?= e(url('/notifications')) ?>"<?= $show === 'open' ? ' class="active" aria-current="page"' : '' ?>>Unread<?= $unread ? ' (' . count($unread) . ')' : '' ?></a>
  <a href="<?= e(url('/notifications?show=all')) ?>"<?= $show === 'all' ? ' class="active" aria-current="page"' : '' ?>>All open</a>
</nav>

<div class="card flush">
<?php if (!$shown): ?>
  <p class="empty">Nothing needs your attention.</p>
<?php else: ?>
<ul class="notif-list">
  <?php foreach ($shown as $n): $isUnread = $n['status'] === 'UNREAD'; ?>
  <li class="notif<?= $isUnread ? ' is-unread' : '' ?>">
    <span class="ico ico-<?= e($tone[$n['priority']] ?? 'blue') ?>"><?= icon($ico[$n['source_type']] ?? 'bell') ?></span>
    <div class="notif-body">
      <?php if ($n['action_url']): ?><a href="<?= e(url($n['action_url'])) ?>"><strong><?= e($n['title']) ?></strong></a><?php else: ?><strong><?= e($n['title']) ?></strong><?php endif; ?>
      <span class="notif-msg"><?= e($n['message']) ?></span>
      <small class="muted"><?= pill(ucfirst(strtolower(str_replace('_', ' ', $n['priority']))), $prio[$n['priority']] ?? 'neutral') ?> <?= e(fmt_dt($n['created_at'])) ?></small>
    </div>
    <div class="notif-actions">
      <?php if ($isUnread): ?>
        <form method="post" action="<?= e(url('/notifications/' . $n['id'] . '/status')) ?>" class="inline"><?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="READ"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="link" type="submit">Mark read</button></form>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/notifications/' . $n['id'] . '/status')) ?>" class="inline"><?= App\Core\Csrf::field() ?><input type="hidden" name="status" value="RESOLVED"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="link" type="submit">Dismiss</button></form>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
</div>
<p class="muted">You see the alerts that fit your role. Reading one marks it read for you only; dismissing it closes it for everyone.</p>
