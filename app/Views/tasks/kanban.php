<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $base=['q'=>$q,'project'=>$project,'status'=>$status,'priority'=>$priority,'assignee'=>$assignee,'archive'=>$archive,'project_q'=>$projectQuery,'assignee_q'=>$assigneeQuery]; ?>
<div class="page-heading"><div><div class="eyebrow">TASKS / PIPELINE</div><h1>Tasks Kanban</h1><p>Move permitted tasks between statuses, or use the keyboard-accessible Move controls.</p></div><?php if($user->can('tasks.create')): ?><a class="btn btn-teal" href="<?= site_url('tasks/new'.($project?'?project='.$project:'')) ?>">+ Create task</a><?php endif ?></div>
<?= view('clients/alerts') ?>
<?= view('tasks/view_switch',['boardView'=>true]) ?>
<?= view('tasks/filters',['filterAction'=>site_url('tasks/kanban')]) ?>
<p class="text-secondary task-board-note">Up to 20 tasks per column, ordered by title then task code. Dragging changes status only. Inactive statuses cannot receive tasks. Archived records and tasks in archived or Completed/Cancelled projects are read-only.</p>
<noscript><p class="alert alert-info">Use each card's status selector and Move button. These forms also work without JavaScript.</p></noscript>
<div id="task-board-feedback" role="status" aria-live="polite" aria-atomic="true" class="alert" hidden></div>
<a id="task-board-reload" class="subtle-link d-inline-block mb-3" href="<?= esc(site_url('tasks/kanban').'?'.http_build_query($base)) ?>" hidden>Reload current board</a>
<div class="kanban-board task-board" data-task-board tabindex="0" role="region" aria-label="Tasks Kanban. Scroll horizontally to view statuses.">
<?php foreach($columns as $column):$stage=$column['status'];$listing=$column['listing']; ?>
<section class="kanban-column" data-status="<?= (int)$stage['id'] ?>" data-drop="<?= (int)$stage['active']&&$archive==='active'?'1':'0' ?>" aria-labelledby="task-stage-<?= (int)$stage['id'] ?>">
<div class="kanban-heading"><h2 id="task-stage-<?= (int)$stage['id'] ?>"><?= esc($stage['name']) ?></h2><span class="state-pill <?= $stage['category']==='done'?'teal':'violet' ?>"><?= (int)$listing['total'] ?></span></div>
<p class="kanban-hint"><?= !(int)$stage['active']?'Inactive status: existing records retained.':($stage['category']==='done'?'Completion time is maintained by the server.':($stage['category']==='cancelled'?'Authorized tasks can be reopened.':'Active task status.')) ?></p>
<div class="kanban-cards">
<?php foreach($listing['rows'] as $task):$movable=$task['can_move']; ?>
<article class="kanban-card task-card" data-task="<?= (int)$task['id'] ?>" data-version="<?= (int)$task['version'] ?>" data-status="<?= (int)$task['status_id'] ?>" draggable="<?= $movable?'true':'false' ?>">
<div class="task-card-meta"><span><?= esc($task['code']) ?></span><span class="state-pill <?= $task['priority_slug']==='urgent'?'amber':'violet' ?>"><?= esc($task['priority_name']) ?></span></div>
<h3><a href="<?= site_url('tasks/'.$task['id']) ?>"><?= esc($task['title']) ?></a></h3>
<p>Project: <a class="subtle-link" href="<?= site_url('projects/'.$task['project_id']) ?>"><?= esc($task['project_name']) ?></a></p>
<p>Assignee: <?= esc($task['assignee_name']?:'Unassigned') ?></p><p>Due: <?= esc($task['due_date']?:'Not set') ?></p>
<?php if($movable): ?>
<form class="task-move" method="post" action="<?= site_url('tasks/'.$task['id'].'/status') ?>">
<?= csrf_field() ?><input type="hidden" name="version" value="<?= (int)$task['version'] ?>">
<label for="task-move-<?= (int)$task['id'] ?>">Move <?= esc($task['code']) ?> to</label>
<div><select class="form-select" id="task-move-<?= (int)$task['id'] ?>" name="status_id" required>
<?php foreach($statuses as $target):if(!(int)$target['active']&&(int)$target['id']!==(int)$task['status_id'])continue; ?>
<option value="<?= (int)$target['id'] ?>" <?= (int)$target['id']===(int)$task['status_id']?'selected':'' ?>><?= esc($target['name']) ?><?= !(int)$target['active']?' (inactive current status)':'' ?></option>
<?php endforeach ?></select><button type="submit" class="btn btn-outline-light">Move</button></div></form>
<?php else: ?><p class="task-readonly">Read-only: task access, record state or available statuses prevent movement.</p><?php endif ?>
</article>
<?php endforeach ?>
<?php if(!$listing['rows']): ?><p class="kanban-empty">No authorized tasks match this status and filters.</p><?php endif ?>
</div>
<nav class="kanban-pagination" aria-label="<?= esc($stage['name']) ?> tasks pagination"><span>Page <?= (int)$listing['page'] ?> / <?= (int)$listing['pages'] ?></span>
<?php foreach([-1=>'Previous',1=>'Next'] as $offset=>$label):$pageNumber=$listing['page']+$offset;if($pageNumber<1||$pageNumber>$listing['pages'])continue;$pageArgs=[];foreach($columns as $other){$pageArgs[$other['status']['id']]=$other['listing']['page'];}$pageArgs[$stage['id']]=$pageNumber; ?>
<a class="subtle-link" href="<?= esc(site_url('tasks/kanban').'?'.http_build_query($base+['columns'=>$pageArgs])) ?>"><?= $label ?></a>
<?php endforeach ?></nav></section>
<?php endforeach ?>
</div>
<?php if(!$columns): ?><div class="crm-card client-panel"><h2>No matching statuses</h2><p>Choose a configured status or reset the filters.</p></div><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/tasks-kanban.js') ?>" defer></script><?= $this->endSection() ?>
