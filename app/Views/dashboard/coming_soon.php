<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><div class="eyebrow">WORKSPACE / <?= esc(strtoupper($title)) ?></div><h1><?= esc($title) ?></h1></div><span class="phase-badge">Coming Soon</span></div>
<section class="coming-soon-panel"><div class="coming-soon-icon"><?= crm_icon($module['icon'] ?? 'layers') ?></div><span class="eyebrow">A LITTLE FURTHER DOWN THE ROAD</span><h2>Your <?= esc(strtolower($title)) ?> workspace is taking shape.</h2><p>This module is planned for a later phase. No records, actions, or business data are available here yet.</p><a class="btn btn-teal" href="<?= site_url('dashboard') ?>">Back to dashboard <?= crm_icon('arrow') ?></a><div class="coming-soon-note"><?= crm_icon('info') ?>The dashboard currently uses clearly labeled demonstration data.</div></section>
<?= $this->endSection() ?>
