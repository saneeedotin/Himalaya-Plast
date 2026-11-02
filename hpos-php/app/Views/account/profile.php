<?php /** @var array $profile @var list<string> $roles @var list<array> $signIns @var int $minLength @var ?string $passwordError */ ?>
<div class="page-head">
  <h1>My profile</h1>
</div>

<section class="card profile-head">
  <span class="avatar avatar-xl"><?= e(initials($profile['name'])) ?></span>
  <div class="profile-id">
    <h2><?= e($profile['name']) ?></h2>
    <p class="muted"><?= e($profile['email']) ?></p>
    <p class="profile-roles"><?php foreach ($roles as $r): ?><?= pill($r, 'ok') ?> <?php endforeach; ?><?php if (!$roles): ?><span class="muted">No role assigned</span><?php endif; ?></p>
  </div>
  <dl class="facts profile-facts">
    <div><dt>Employee code</dt><dd><?= e($profile['employee_code'] ?: '-') ?></dd></div>
    <div><dt>Last sign-in</dt><dd><?= e(fmt_dt($profile['last_login_at']) ?: '-') ?></dd></div>
    <div><dt>Member since</dt><dd><?= e(substr((string) $profile['created_at'], 0, 10)) ?></dd></div>
  </dl>
</section>

<div class="grid-2 profile-grid">
  <section class="card">
    <h2>Your details</h2>
    <form method="post" action="<?= e(url('/account')) ?>">
      <?= App\Core\Csrf::field() ?>
      <?= field('name', 'Display name', $profile['name'], ['required' => true, 'maxlength' => 190, 'autocomplete' => 'name']) ?>
      <?= field('phone', 'Phone', $profile['phone'], ['maxlength' => 40, 'autocomplete' => 'tel', 'hint' => 'Digits, spaces and + - ( ) only.']) ?>
      <label for="f_email">Email</label>
      <input id="f_email" type="email" value="<?= e($profile['email']) ?>" readonly>
      <p class="hint">Your email and role are set by an administrator.</p>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Save profile</button></div>
    </form>
  </section>

  <section class="card" id="password">
    <h2>Change password</h2>
    <?php if (!empty($passwordError)): ?>
      <div class="alert alert-error" role="alert"><?= e($passwordError) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/account/password')) ?>">
      <?= App\Core\Csrf::field() ?>
      <label for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
      <label for="new_password">New password</label>
      <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="<?= (int) $minLength ?>" required>
      <p class="hint">At least <?= (int) $minLength ?> characters. Signing in on other devices will end.</p>
      <label for="confirm_password">Repeat new password</label>
      <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="<?= (int) $minLength ?>" required>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
    </form>
  </section>
</div>

<section class="card">
  <h2>Appearance</h2>
  <p class="muted">Saved in this browser only.</p>
  <div class="seg" role="group" aria-label="Theme">
    <button type="button" class="seg-btn" data-set-theme="light"><?= icon('sun') ?>Light</button>
    <button type="button" class="seg-btn" data-set-theme="dark"><?= icon('moon') ?>Dark</button>
  </div>
</section>

<h2>Recent sign-ins</h2>
<div class="card flush">
  <table class="table">
    <thead><tr><th>When</th><th>Device</th><th>IP address</th></tr></thead>
    <tbody>
    <?php foreach ($signIns as $s): ?>
      <tr><td><?= e(fmt_dt($s['created_at'])) ?></td><td><?= e(device_label($s['user_agent'])) ?></td><td><?= e($s['ip'] ?: '-') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$signIns): ?><tr><td colspan="3" class="muted">No sign-ins recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
