<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><div class="eyebrow">YOUR WORKSPACE</div><h1>My account</h1><p>Your current authenticated identity.</p></div></div>
<section class="crm-card account-summary"><span class="avatar avatar-user avatar-large"><?= esc(strtoupper(substr($user->username, 0, 1))) ?></span><h2><?= esc($user->username) ?></h2><p><?= $user->inGroup('admin') ? 'Administrator' : 'Team member' ?></p><p class="text-secondary">Profile editing and password changes are not part of this phase.</p><a class="btn btn-teal" href="<?= site_url('dashboard') ?>">Return to dashboard</a></section>
<?= $this->endSection() ?>
