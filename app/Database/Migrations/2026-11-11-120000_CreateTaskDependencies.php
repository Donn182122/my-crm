<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateTaskDependencies extends Migration
{
    public function up()
    {
        $tasks=$this->db->protectIdentifiers($this->db->prefixTable('tasks'));
        $this->db->query('CREATE UNIQUE INDEX tasks_project_identity ON '.$tasks.' (project_id,id)');
        $ref=['type'=>'INT','unsigned'=>true];$time=['type'=>'DATETIME'];
        $this->forge->addField(['id'=>$ref+['auto_increment'=>true],'project_id'=>$ref,'predecessor_id'=>$ref,'successor_id'=>$ref,'created_by'=>$ref,'archived_at'=>$time+['null'=>true],'archived_by'=>$ref+['null'=>true],'version'=>$ref+['default'=>1],'created_at'=>$time,'updated_at'=>$time]);
        $this->forge->addField('CONSTRAINT task_dependencies_distinct CHECK (predecessor_id <> successor_id)');
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey(['predecessor_id','successor_id']);
        $this->forge->addKey(['project_id','archived_at','predecessor_id','successor_id']);$this->forge->addKey(['project_id','archived_at','successor_id','predecessor_id']);
        $this->forge->addForeignKey('project_id','projects','id','RESTRICT','RESTRICT');
        $this->forge->addForeignKey(['project_id','predecessor_id'],'tasks',['project_id','id'],'RESTRICT','RESTRICT');
        $this->forge->addForeignKey(['project_id','successor_id'],'tasks',['project_id','id'],'RESTRICT','RESTRICT');
        foreach(['created_by','archived_by'] as $field){$this->forge->addForeignKey($field,'users','id','RESTRICT','RESTRICT');}
        $this->forge->createTable('task_dependencies',false,$this->db->DBDriver==='MySQLi'?['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']:[]);
    }
    public function down()
    {
        $this->forge->dropTable('task_dependencies');
        $this->db->query($this->db->DBDriver==='MySQLi'?'DROP INDEX tasks_project_identity ON '.$this->db->protectIdentifiers($this->db->prefixTable('tasks')):'DROP INDEX tasks_project_identity');
    }
}