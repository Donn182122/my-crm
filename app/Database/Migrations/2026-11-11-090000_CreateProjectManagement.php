<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateProjectManagement extends Migration
{
    private function ref():array{return ['type'=>'INT','unsigned'=>true];}
    private function text(int $length,bool $null=false):array{return ['type'=>'VARCHAR','constraint'=>$length,'null'=>$null];}
    private function times():array{return ['created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME']];}
    private function create(string $name):void{$this->forge->createTable($name,false,$this->db->DBDriver==='MySQLi'?['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']:[]);}
    public function up()
    {
        $id=$this->ref()+['auto_increment'=>true];
        $this->forge->addField(['id'=>$id,'slug'=>$this->text(50),'name'=>$this->text(80),'position'=>['type'=>'INT'],'active'=>['type'=>'TINYINT','default'=>1]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('slug');$this->create('project_statuses');
        $this->forge->addField(['id'=>$id,'code'=>$this->text(24),'name'=>$this->text(180),'description'=>['type'=>'TEXT','null'=>true],
            'client_id'=>$this->ref(),'owner_id'=>$this->ref(),'created_by'=>$this->ref(),'status_id'=>$this->ref(),
            'start_date'=>['type'=>'DATE','null'=>true],'end_date'=>['type'=>'DATE','null'=>true],'version'=>$this->ref()+['default'=>1],
            'archived_at'=>['type'=>'DATETIME','null'=>true]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('code');
        foreach([['owner_id','archived_at'],['client_id','archived_at'],['status_id','archived_at'],['name']] as $key){$this->forge->addKey($key);}
        foreach(['client_id'=>'clients','owner_id'=>'users','created_by'=>'users','status_id'=>'project_statuses'] as $field=>$table){$this->forge->addForeignKey($field,$table,'id','RESTRICT','RESTRICT');}$this->create('projects');
        $this->forge->addField(['project_id'=>$this->ref(),'user_id'=>$this->ref(),'role'=>$this->text(12),'assigned_by'=>$this->ref()]+$this->times());
        $this->forge->addPrimaryKey(['project_id','user_id']);$this->forge->addKey(['user_id','project_id']);
        $this->forge->addForeignKey('project_id','projects','id','RESTRICT','CASCADE');foreach(['user_id','assigned_by'] as $field){$this->forge->addForeignKey($field,'users','id','RESTRICT','RESTRICT');}$this->create('project_members');
        $this->forge->addField(['id'=>$id,'project_id'=>$this->ref(),'actor_id'=>$this->ref(),'version'=>$this->ref(),'event'=>$this->text(40),'summary'=>$this->text(255),'metadata'=>['type'=>'TEXT','null'=>true]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey(['project_id','version']);$this->forge->addKey(['project_id','created_at']);
        $this->forge->addForeignKey('project_id','projects','id','RESTRICT','CASCADE');$this->forge->addForeignKey('actor_id','users','id','RESTRICT','RESTRICT');$this->create('project_history');
    }
    public function down(){foreach(['project_history','project_members','projects','project_statuses'] as $table){$this->forge->dropTable($table);}}
}
