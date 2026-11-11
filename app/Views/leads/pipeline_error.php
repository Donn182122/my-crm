<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><div class="eyebrow">LEADS / REVIEW REQUIRED</div><h1><?= esc($title) ?></h1></div></div><?= view('clients/alerts') ?>
<section class="crm-card client-panel"><p>No conflicting or incomplete operation was applied. Reload the latest information and review it before retrying.</p><div class="client-actions"><a class="btn btn-teal" href="<?= site_url(isset($leadId)?'leads/'.$leadId.'/convert':'leads/kanban') ?>"><?= isset($leadId)?'Prepare a new preview':'Reload pipeline' ?></a><a class="btn btn-outline-light" href="<?= site_url('leads') ?>">All leads</a></div></section>
<?= $this->endSection() ?>
