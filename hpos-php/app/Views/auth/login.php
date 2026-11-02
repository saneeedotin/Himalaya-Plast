<?php /** @var string $email @var ?string $error */ ?>
<section class="card card-narrow">
  <h1>Himalaya Plast</h1>
  <p class="muted">Sign in to your account</p>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
    <?= App\Core\Csrf::field() ?>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" placeholder="operator@himalayaplast.com" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="••••••••" required>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
</section>
