<?php
/** @var string $content @var string $title */
$user = App\Core\Auth::user();
$nav = require HPOS_ROOT . '/app/nav.php';
$here = '/' . trim((string) preg_replace('#^' . preg_quote(base_path(), '#') . '#', '', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
$isActive = static function (array $item) use ($here): bool {
    $under = static fn (string $p): bool => $here === $p || str_starts_with($here, $p . '/');
    // 'exclude' = paths that belong to a neighbouring item even though they start with this item's path
    if (array_filter($item['exclude'] ?? [], $under)) {
        return false;
    }
    $prefixes = array_merge([$item['path']], $item['match'] ?? []);
    return ($item['path'] === '/' || !empty($item['exact']))
        ? $here === $item['path']
        : (bool) array_filter($prefixes, $under);
};
$dock = array_values(array_filter([
    ['Home', '/', 'layout-grid', null],
    ['Orders', '/orders', 'shopping-cart', ['SalesOrders', 'read']],
    ['Floor', '/shop-floor', 'cpu', ['Production', 'read']],
    ['QC', '/quality', 'shield-check', ['QC', 'read']],
    ['Dispatch', '/dispatch', 'truck', ['Dispatch', 'read']],
], static fn (array $d): bool => $d[3] === null || can($d[3][0], $d[3][1])));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · HPOS</title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body<?php if ($user): ?> data-base="<?= e(base_path()) ?>" data-csrf="<?= e(App\Core\Csrf::token()) ?>" data-poll="<?= e(url('/notifications/poll')) ?>" data-sw="<?= e(url('/sw.js')) ?>" data-push-status="<?= e(url('/push/status')) ?>" data-push-subscribe="<?= e(url('/push/subscribe')) ?>" data-push-unsubscribe="<?= e(url('/push/unsubscribe')) ?>" data-push-test="<?= e(url('/push/test')) ?>"<?php endif; ?>>
<nav class="sidebar" id="sidebar" aria-label="Main">
  <a class="sidebar-brand" href="<?= e(url('/')) ?>">
    <span class="brand-mark"><?= icon('layers') ?></span>
    <span><span class="brand-name">himalaya plast</span><span class="brand-sub">Factory OS</span></span>
  </a>
  <div class="sidebar-nav">
    <?php foreach ($nav as $group):
        $visible = array_values(array_filter($group['items'], static fn (array $i): bool => $i['perm'] === null || can($i['perm'][0], $i['perm'][1])));
        if (!$visible) { continue; } ?>
      <?php if ($group['group'] !== null): ?><p class="nav-group"><?= e($group['group']) ?></p><?php endif; ?>
      <ul>
      <?php foreach ($visible as $item): $active = $isActive($item); ?>
        <li><a href="<?= e(url($item['path'])) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= icon($item['icon'] ?? 'box') ?><span><?= e($item['label']) ?></span></a></li>
      <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </div>
  <?php if ($user): ?>
  <div class="sidebar-foot">
    <div class="user-card">
      <a class="who-link" href="<?= e(url('/account')) ?>" title="My profile">
        <span class="avatar"><?= e(initials($user['name'])) ?></span>
        <span class="who-text"><span class="who-name"><?= e($user['name']) ?></span><span class="who-role"><?= e($user['roles'] ?? 'Member') ?></span></span>
      </a>
      <form method="post" action="<?= e(url('/logout')) ?>">
        <?= App\Core\Csrf::field() ?>
        <button class="icon-btn icon-btn-quiet" type="submit" title="Sign out" aria-label="Sign out"><?= icon('log-out') ?></button>
      </form>
    </div>
    <div class="foot-row">
      <button class="icon-btn theme-toggle" type="button" data-theme-toggle title="Switch light or dark" aria-label="Switch light or dark theme"><?= icon('moon', 'icon-moon') ?><?= icon('sun', 'icon-sun') ?></button>
    </div>
  </div>
  <?php endif; ?>
</nav>

<div class="main">
  <header class="topbar">
    <div class="topbar-inner">
      <?php if ($user): ?>
      <a class="who-top" href="<?= e(url('/account')) ?>" title="My profile">
        <span class="avatar avatar-lg"><?= e(initials($user['name'])) ?></span>
        <span class="who-text"><span class="who-name"><?= e($user['name']) ?></span><span class="who-role"><?= e($user['roles'] ?? '') ?></span></span>
      </a>
      <div class="topbar-right">
        <form class="topsearch" method="get" action="<?= e(url('/search')) ?>" role="search">
          <input type="search" name="q" placeholder="Search orders, customers, items..." aria-label="Search" maxlength="100">
          <button type="submit" aria-label="Search"><?= icon('search') ?></button>
        </form>
        <?php $unread = unread_notifications(); ?>
        <a class="icon-btn bell" href="<?= e(url('/notifications')) ?>" title="Alerts" aria-label="Notifications<?= $unread > 0 ? ', ' . $unread . ' unread' : '' ?>"><?= icon('bell') ?><span class="bell-count" data-bell-count<?= $unread > 0 ? '' : ' hidden' ?>><?= $unread > 99 ? '99+' : (int) $unread ?></span></a>
      </div>
      <?php endif; ?>
    </div>
  </header>
  <main class="content">
    <?php foreach (App\Core\Flash::take() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>

<?php if ($user): ?>
<nav class="dock" aria-label="Quick">
  <?php foreach ($dock as [$label, $path, $ico]):
      $on = $isActive(['path' => $path]); ?>
    <a href="<?= e(url($path)) ?>"<?= $on ? ' class="active" aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
  <button type="button" class="menu-toggle" aria-label="All menus" aria-expanded="false" aria-controls="sidebar"><?= icon('menu') ?><span>Menu</span></button>
</nav>
<?php endif; ?>
</body>
</html>
