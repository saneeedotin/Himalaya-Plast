<?php /** @var string $q @var array<string,list<array>> $groups @var ?string $message @var int $count @var array<string,string> $labels */ ?>
<h1>Search</h1>
<form method="get" action="<?= e(url('/search')) ?>" class="filter-row">
  <div><?= field('q', 'Find', $q, ['maxlength' => 100, 'hint' => 'An order or invoice number, customer, item code, supplier, batch...']) ?></div>
  <button class="btn btn-primary" type="submit">Search</button>
</form>
<?php if ($message): ?><div class="alert alert-error" role="alert"><?= e($message) ?></div><?php endif; ?>
<?php if ($q !== '' && !$message): ?>
  <p class="muted"><?= (int) $count ?> result<?= $count === 1 ? '' : 's' ?> for <strong><?= e($q) ?></strong>. Only what you are allowed to see is searched.</p>
  <?php foreach ($groups as $kind => $items): ?>
    <h2><?= e($labels[$kind] ?? $kind) ?></h2>
    <div class="card flush">
    <ul class="alert-list">
      <?php foreach ($items as $r): ?>
        <li><a href="<?= e(url($r['url'])) ?>"><?= e($r['title']) ?></a> <span class="muted"><?= e($r['subtitle']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
