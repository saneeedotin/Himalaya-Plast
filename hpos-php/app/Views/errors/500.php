<?php /** @var ?string $detail */ ?>
<section class="card card-narrow">
  <h1>Something went wrong</h1>
  <p>The error has been logged. Try again, and tell your administrator if it keeps happening.</p>
  <?php if (!empty($detail)): ?><pre class="debug"><?= e($detail) ?></pre><?php endif; ?>
  <p><a class="btn" href="<?= e(url('/')) ?>">Back to home</a></p>
</section>
