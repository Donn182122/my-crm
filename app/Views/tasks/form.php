<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $val=static fn($key,$default='')=>is_scalar($values[$key]??$default)?(string)($values[$key]??$default):''; ?>
<div class="page-heading"><div><div class="eyebrow">TASKS / DETAILS</div><h1><?= esc($title) ?></h1><p>Required fields are marked *. Project and parent association are fixed after creation.</p></div><a class="subtle-link" href="<?= site_url($id?'tasks/'.$id:'tasks') ?>">Cancel</a></div>
<?= view('clients/alerts') ?>
<form method="get" class="crm-card client-panel mb-4" action="<?= site_url($id?'tasks/'.$id.'/edit':'tasks/new') ?>">
<?php if(!$id&&$parent): ?><input type="hidden" name="parent" value="<?= (int)$parent['id'] ?>"><?php endif ?>
<?php if(!$id): ?>
<label for="project_q">Find projects you can create tasks in</label><input id="project_q" name="project_q" maxlength="100" class="form-control mt-2" value="<?= esc($projectQuery) ?>">
<label class="d-block mt-3" for="project">Project</label><select id="project" name="project" class="form-select mt-2" <?= $parent?'disabled':'' ?>><option value="">Choose a project</option>
<?php foreach($projects as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $project&&(int)$project['id']===(int)$item['id']?'selected':'' ?>><?= esc($item['name']) ?></option><?php endforeach ?></select>
<?php endif ?>
<?php if($project&&$policy->allows($user,'assign',$project,$task)): ?><label class="d-block mt-3" for="assignee_q">Find eligible participants by username</label><input id="assignee_q" name="assignee_q" class="form-control mt-2" maxlength="60" value="<?= esc($assigneeQuery) ?>"><?php endif ?>
<button type="submit" class="btn btn-outline-light mt-3">Load project options</button><p class="text-secondary mt-2 mb-0">Searches show at most 20 choices. Loading options reloads this form; do it before entering task details.</p></form>
<?php if(!$project): ?>
<div class="alert alert-info">Choose an authorized writable project and load its options before creating a task. Only project owners/managers with task creation permission can create tasks.</div>
<?php else: ?>
<form class="crm-card client-form" method="post" action="<?= esc(site_url($id?'tasks/'.$id:'tasks').'?'.http_build_query(['project_q'=>$projectQuery,'assignee_q'=>$assigneeQuery])) ?>">
<?= csrf_field() ?><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
<?php if(!$id&&$parent): ?><input type="hidden" name="parent_id" value="<?= (int)$parent['id'] ?>"><?php endif ?>
<?php if($id): ?><input type="hidden" name="version" value="<?= esc($val('version')) ?>"><p class="text-secondary">Stale changes are rejected. <a class="subtle-link" href="<?= site_url('tasks/'.$id.'/edit') ?>">Reload latest information</a></p><?php endif ?>
<h2>Task information</h2>
<?php if($parent): ?><p>Parent task: <a class="subtle-link" href="<?= site_url('tasks/'.$parent['id']) ?>"><?= esc($parent['title']) ?></a>. Parent and project are fixed after creation.</p><?php elseif($task&&$task['parent_id']): ?><p>Parent task details require independent access.</p><?php endif ?>
<p>Project: <a class="subtle-link" href="<?= site_url('projects/'.$project['id']) ?>"><?= esc($project['name']) ?></a></p>
<div class="client-form-grid"><div class="client-field-wide"><label for="title">Task title *</label><input class="form-control" id="title" name="title" maxlength="180" required value="<?= esc($val('title')) ?>"></div>
<div><label for="status_id">Status *</label>
<?php if($policy->allows($user,'status',$project,$task)||!$id): ?><select id="status_id" name="status_id" class="form-select" required>
<?php foreach($statuses as $item):if(!(int)$item['active']&&(!$id||$val('status_id')!==(string)$item['id']))continue;if(!$user->can('tasks.status')&&$val('status_id')!==(string)$item['id'])continue; ?><option value="<?= (int)$item['id'] ?>" <?= $val('status_id')===(string)$item['id']?'selected':'' ?>><?= esc($item['name']) ?></option><?php endforeach ?></select>
<?php else: ?><p><?= esc($task['status_name']) ?> (status permission required to change)</p><?php endif ?></div>
<div><label for="priority_id">Priority *</label><select id="priority_id" name="priority_id" class="form-select" required>
<?php foreach($priorities as $item):if(!(int)$item['active']&&(!$id||$val('priority_id')!==(string)$item['id']))continue; ?><option value="<?= (int)$item['id'] ?>" <?= $val('priority_id')===(string)$item['id']?'selected':'' ?>><?= esc($item['name']) ?></option><?php endforeach ?></select></div>
<?php if($policy->allows($user,'assign',$project,$task)): ?><div><label for="assignee_id">Assigned participant</label><select id="assignee_id" name="assignee_id" class="form-select"><option value="">Unassigned</option>
<?php foreach($assignees as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $val('assignee_id')===(string)$item['id']?'selected':'' ?>><?= esc($item['username']) ?></option><?php endforeach ?>
<?php if($task&&$task['assignee_id']&&!in_array((int)$task['assignee_id'],array_map('intval',array_column($assignees,'id')),true)): ?><option selected value="<?= (int)$task['assignee_id'] ?>"><?= esc($task['assignee_name']) ?> (existing assignment retained)</option><?php endif ?></select></div><?php endif ?>
<?php foreach(['start_date'=>'Start date','due_date'=>'Due date'] as $key=>$label): ?><div><label for="<?= $key ?>"><?= $label ?></label><input id="<?= $key ?>" name="<?= $key ?>" type="date" class="form-control" min="1000-01-01" max="9999-12-31" value="<?= esc($val($key)) ?>"></div><?php endforeach ?>
<div class="client-field-wide"><label for="description">Description</label><textarea id="description" name="description" class="form-control" maxlength="10000" rows="5"><?= esc($val('description')) ?></textarea></div></div>
<div class="client-actions mt-4"><button type="submit" class="btn btn-teal"><?= $id?'Save changes':($parent?'Create subtask':'Create task') ?></button><a class="btn btn-outline-light" href="<?= site_url($id?'tasks/'.$id:'tasks') ?>">Cancel</a></div></form>
<?php endif ?>
<?= $this->endSection() ?>
