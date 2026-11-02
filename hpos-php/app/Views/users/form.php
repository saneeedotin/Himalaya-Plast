<?php /** @var ?array $user @var array $row @var array<string,string> $roles @var list<string> $roleIds @var bool $isSelf @var ?string $error */
$isNew = $user === null; ?>
<h1><?= $isNew ? 'New user' : 'Edit user' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/users' : '/users/' . $user['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?php if ($isNew): ?>
      <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
      <?= field('email', 'Email', $row['email'] ?? '', ['type' => 'email', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'off']) ?>
      <?= field('employee_code', 'Employee code', $row['employee_code'] ?? '', ['maxlength' => 40]) ?>
      <?= field('password', 'Initial password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'At least 10 characters. The user can change it after signing in.']) ?>
    <?php else: ?>
      <p><strong><?= e($user['name']) ?></strong><br><span class="muted"><?= e($user['email']) ?></span></p>
    <?php endif; ?>

    <fieldset>
      <legend>Roles</legend>
      <?php foreach ($roles as $id => $name): ?>
        <label class="check"><input type="checkbox" name="roles[]" value="<?= e($id) ?>"<?= in_array($id, $roleIds, true) ? ' checked' : '' ?>> <?= e($name) ?></label>
      <?php endforeach; ?>
    </fieldset>

    <?php if (!$isNew): ?>
      <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $user['is_active'] === 1 ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>> Active</label>
      <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><p class="hint">You cannot deactivate your own account.</p><?php endif; ?>
      <p class="hint">Deactivating a user signs them out immediately.</p>
    <?php endif; ?>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url('/users')) ?>">Cancel</a>
    </div>
  </form>
</section>

<?php if (!$isNew && !$isSelf): ?>
<h2>Forgot password</h2>
<section class="card card-narrow">
  <form method="post" action="<?= e(url('/users/' . $user['id'] . '/password')) ?>" data-confirm="Set a new password for this user? They will be signed out everywhere.">
    <?= App\Core\Csrf::field() ?>
    <?= field('new_password', 'New password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'At least 10 characters. Tell the user in person; they can change it after signing in.']) ?>
    <?= field('confirm_password', 'Repeat new password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <button class="btn" type="submit">Set password</button>
  </form>
</section>
<?php endif; ?>
