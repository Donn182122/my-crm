<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><h1><?= esc($title) ?></h1></div><?= view('clients/alerts') ?>
<section class="crm-card client-panel"><p>No conflicting or invalid change was saved. Reload and review the latest project information.</p><div class="client-actions"><a class="btn btn-teal" href="<?= site_url('projects/'.$projectId) ?>">Reload project</a><a class="btn btn-outline-light" href="<?= site_url('projects') ?>">All projects</a></div></section>
<?= $this->endSection() ?>
