<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="page-heading"><h1><?= esc($title) ?></h1></div><?= view('clients/alerts') ?><section class="crm-card client-panel"><p>No conflicting or invalid change was saved. Review current task and project state before retrying.</p><div class="client-actions"><?php if($taskId??null): ?><a class="btn btn-teal" href="<?= site_url('tasks/'.$taskId) ?>">Reload task</a><?php elseif($projectId??null): ?><a class="btn btn-teal" href="<?= site_url('projects/'.$projectId) ?>">View parent project</a><?php endif ?><a class="btn btn-outline-light" href="<?= site_url('tasks') ?>">All tasks</a></div></section>
<?= $this->endSection() ?>
