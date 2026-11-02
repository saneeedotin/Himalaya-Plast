<?php /** @var list<array> $users */ ?>
<div class="page-head">
  <h1>Users</h1>
  <?php if (can('users', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/users/new')) ?>">New user</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Name</th><th>Email</th><th>Employee code</th><th>Roles</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['name']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e($u['employee_code']) ?></td>
      <td><?= e($u['roles']) ?></td>
      <td><?= (int) $u['is_active'] ? pill('Active', 'ok') : pill('Inactive', 'fail') ?></td>
      <td><?= e(fmt_dt($u['last_login_at'])) ?></td>
      <td class="actions"><?php if (can('users', 'update')): ?><a href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>">Edit</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="7" class="muted">No users yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
