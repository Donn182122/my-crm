<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateTaskManagement extends Migration
{
    private function ref(bool $null=false):array{return ['type'=>'INT','unsigned'=>true,'null'=>$null];}
    private function text(int $length,bool $null=false):array{return ['type'=>'VARCHAR','constraint'=>$length,'null'=>$null];}
    private function times():array{return ['created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME']];}
    private function create(string $name):void{$this->forge->createTable($name,false,$this->db->DBDriver==='MySQLi'?['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']:[]);}
    public function up()
    {
        $id=$this->ref()+['auto_increment'=>true];
        foreach(['task_statuses','task_priorities'] as $table){$fields=['id'=>$id,'slug'=>$this->text(50),'name'=>$this->text(80),'position'=>['type'=>'INT'],'active'=>['type'=>'TINYINT','default'=>1]]+$this->times();if($table==='task_statuses'){$fields['category']=$this->text(12);}$this->forge->addField($fields);$this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('slug');$this->create($table);}
        $this->forge->addField(['id'=>$id,'code'=>$this->text(24),'project_id'=>$this->ref(),'title'=>$this->text(180),'description'=>['type'=>'TEXT','null'=>true],
            'status_id'=>$this->ref(),'priority_id'=>$this->ref(),'assignee_id'=>$this->ref(true),'created_by'=>$this->ref(),
            'start_date'=>['type'=>'DATE','null'=>true],'due_date'=>['type'=>'DATE','null'=>true],'completed_at'=>['type'=>'DATETIME','null'=>true],
            'archived_at'=>['type'=>'DATETIME','null'=>true],'version'=>$this->ref()+['default'=>1]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('code');foreach([['project_id','archived_at','status_id'],['assignee_id','archived_at','status_id'],['priority_id','archived_at'],['due_date'],['title']] as $key){$this->forge->addKey($key);}
        foreach(['project_id'=>'projects','status_id'=>'task_statuses','priority_id'=>'task_priorities','assignee_id'=>'users','created_by'=>'users'] as $field=>$table){$this->forge->addForeignKey($field,$table,'id','RESTRICT','RESTRICT');}$this->create('tasks');
        $this->forge->addField(['id'=>$id,'task_id'=>$this->ref(),'actor_id'=>$this->ref(),'version'=>$this->ref(),'event'=>$this->text(40),'summary'=>$this->text(255),'metadata'=>['type'=>'TEXT','null'=>true]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey(['task_id','version']);$this->forge->addKey(['task_id','created_at']);
        $this->forge->addForeignKey('task_id','tasks','id','RESTRICT','CASCADE');$this->forge->addForeignKey('actor_id','users','id','RESTRICT','RESTRICT');$this->create('task_history');
    }
    public function down(){foreach(['task_history','tasks','task_priorities','task_statuses'] as $table){$this->forge->dropTable($table);}}
}
