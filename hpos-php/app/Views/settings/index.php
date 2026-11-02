<?php /** @var list<array> $settings */
$canEdit = can('users', 'update'); ?>
<h1>Settings</h1>
<section class="card card-narrow">
  <form method="post" action="<?= e(url('/settings')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?php foreach ($settings as $s): ?>
      <?= field('setting[' . $s['setting_key'] . ']', ucfirst(str_replace('_', ' ', $s['setting_key'])), $s['setting_value'], ['hint' => $s['description'], 'maxlength' => 255] + ($canEdit ? [] : ['readonly' => true])) ?>
    <?php endforeach; ?>
    <?php if ($canEdit): ?><button class="btn btn-primary" type="submit">Save settings</button><?php endif; ?>
  </form>
</section>
