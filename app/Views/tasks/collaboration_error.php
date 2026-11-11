<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><h1><?= esc($title) ?></h1></div><?= view('clients/alerts') ?>
<section class="crm-card client-panel"><p>No conflicting or invalid change was saved. Reload the task to review its current state before trying again.</p>
<?php if($draft!==''): ?><label for="unsaved-draft">Your unsaved text — copy it before reloading</label><textarea id="unsaved-draft" class="form-control mb-3" rows="5" readonly><?= esc($draft) ?></textarea><?php endif ?>
<a class="btn btn-teal" href="<?= site_url('tasks/'.$taskId) ?>">Reload task</a></section>
<?= $this->endSection() ?>
