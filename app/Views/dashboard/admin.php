<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><div class="eyebrow">WORKSPACE SECURITY</div><h1>Administrator access verified</h1><p>Your account has the administrator group and admin.access permission.</p></div></div>
<section class="crm-card account-summary"><span class="metric-icon teal"><?= crm_icon('check') ?></span><h2 class="mt-3">You're in the right place.</h2><p>This page verifies authorization only. Business settings are coming in a later phase.</p><a class="btn btn-teal" href="<?= site_url('dashboard') ?>">Back to dashboard</a></section>
<?= $this->endSection() ?>
