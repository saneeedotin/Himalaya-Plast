<?php /** @var ?array $lead @var array $row @var ?string $error */
$isNew = $lead === null; ?>
<h1><?= $isNew ? 'New lead' : 'Edit lead' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/customer-leads' : '/customer-leads/' . $lead['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= field('company_name', 'Company', $row['company_name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
    <?= field('contact_name', 'Contact person', $row['contact_name'] ?? '', ['maxlength' => 190]) ?>
    <?= field('phone', 'Phone', $row['phone'] ?? '', ['maxlength' => 40]) ?>
    <?= field('email', 'Email', $row['email'] ?? '', ['type' => 'email', 'maxlength' => 190]) ?>
    <?= field('source', 'Source', $row['source'] ?? '', ['maxlength' => 100, 'hint' => 'Where the lead came from: referral, trade fair, website, WhatsApp...']) ?>
    <?= select_field('status', 'Status', $row['status'] ?? 'NEW', App\Controllers\CustomerLeadController::EDITABLE) ?>
    <?= field('next_follow_up', 'Next follow-up', dt_input($row['next_follow_up'] ?? null), ['type' => 'datetime-local', 'hint' => 'HPOS raises an alert when this time comes.']) ?>
    <?= textarea_field('notes', 'Notes', $row['notes'] ?? '') ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url('/customer-leads')) ?>">Cancel</a>
    </div>
  </form>
</section>
