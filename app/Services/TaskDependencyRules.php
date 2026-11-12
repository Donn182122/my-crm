<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
/** Internal integrity checks; callers hold the existing project/task transaction locks. */
final class TaskDependencyRules
{
    public static function guardStatus(BaseConnection $db,array $task,array $status):void
    {
        if($status['category']==='done'){
            $blocked=$db->table('task_dependencies d')->join('tasks p','p.id=d.predecessor_id')->join('task_statuses s','s.id=p.status_id')->where(['d.successor_id'=>$task['id'],'d.archived_at'=>null])->where('s.category !=','done')->countAllResults();
            if($blocked){throw new TaskInputException(['dependencies'=>'This task is blocked: complete all required prerequisites first. A project manager can review restricted prerequisites.']);}
        }
        if($task['category']==='done'&&$status['category']!=='done'){
            $done=$db->table('task_dependencies d')->join('tasks t','t.id=d.successor_id')->join('task_statuses s','s.id=t.status_id')->where(['d.predecessor_id'=>$task['id'],'d.archived_at'=>null,'s.category'=>'done'])->countAllResults();
            if($done){throw new TaskInputException(['dependencies'=>'A completed dependent still requires this task. Reopen dependent tasks or remove an eligible dependency before reopening this prerequisite.']);}
        }
    }
}