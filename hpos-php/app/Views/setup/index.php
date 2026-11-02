<?php /** @var list<array{label:string,status:string,detail:string}> $results @var bool $failed */ ?>
<section class="card">
  <h1>Server check</h1>
  <p class="<?= $failed ? 'alert alert-error' : 'alert alert-success' ?>">
    <?= $failed ? 'Something needs fixing before HPOS can run.' : 'This server can run HPOS.' ?>
  </p>
  <table class="table">
    <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td><?= e($r['label']) ?></td>
        <td><span class="pill pill-<?= e($r['status']) ?>"><?= e(strtoupper($r['status'])) ?></span></td>
        <td><?= e($r['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted">Set <code>setup_enabled</code> to <code>false</code> in config.php once the server is ready.</p>
</section>
