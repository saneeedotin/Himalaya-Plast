<section class="card card-narrow">
  <h1>No access</h1>
  <p><?= e($message ?? 'You do not have permission to open this page.') ?></p>
  <p><a class="btn" href="<?= e(url('/')) ?>">Back to home</a></p>
</section>
