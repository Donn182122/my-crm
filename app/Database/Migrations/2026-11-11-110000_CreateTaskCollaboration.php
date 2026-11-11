<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateTaskCollaboration extends Migration
{
    private function ref(bool $null=false):array{return ['type'=>'INT','unsigned'=>true,'null'=>$null];}
    private function time(bool $null=false):array{return ['type'=>'DATETIME','null'=>$null];}
    public function up()
    {
        $tasks=$this->db->protectIdentifiers($this->db->prefixTable('tasks'));
        if($this->db->DBDriver==='MySQLi'){$this->forge->addColumn('tasks',['parent_id'=>$this->ref(true)]);$this->db->query('ALTER TABLE '.$tasks.' ADD CONSTRAINT tasks_parent_fk FOREIGN KEY (parent_id) REFERENCES '.$tasks.'(id) ON UPDATE RESTRICT ON DELETE RESTRICT');}
        else{$this->db->query('ALTER TABLE '.$tasks.' ADD COLUMN parent_id INTEGER NULL REFERENCES '.$tasks.'(id) ON UPDATE RESTRICT ON DELETE RESTRICT');}
        $this->db->query('CREATE INDEX tasks_parent_archive ON '.$tasks.' (parent_id,archived_at,id)');
        $id=$this->ref()+['auto_increment'=>true];$version=$this->ref()+['default'=>1];$times=['created_at'=>$this->time(),'updated_at'=>$this->time()];
        $this->forge->addField(['id'=>$id,'task_id'=>$this->ref(),'label'=>['type'=>'VARCHAR','constraint'=>240],'position'=>$this->ref(),'created_by'=>$this->ref(),'checked_at'=>$this->time(true),'checked_by'=>$this->ref(true),'deleted_at'=>$this->time(true),'deleted_by'=>$this->ref(true),'version'=>$version]+$times);
        $this->forge->addPrimaryKey('id');$this->forge->addKey(['task_id','deleted_at','position','id']);$this->forge->addForeignKey('task_id','tasks','id','RESTRICT','CASCADE');foreach(['created_by','checked_by','deleted_by'] as $field){$this->forge->addForeignKey($field,'users','id','RESTRICT','RESTRICT');}$this->create('task_checklist_items');
        $this->forge->addField(['id'=>$id,'task_id'=>$this->ref(),'author_id'=>$this->ref(),'body'=>['type'=>'TEXT'],'edited_at'=>$this->time(true),'edited_by'=>$this->ref(true),'deleted_at'=>$this->time(true),'deleted_by'=>$this->ref(true),'version'=>$version]+$times);
        $this->forge->addPrimaryKey('id');$this->forge->addKey(['task_id','deleted_at','created_at','id']);$this->forge->addForeignKey('task_id','tasks','id','RESTRICT','CASCADE');foreach(['author_id','edited_by','deleted_by'] as $field){$this->forge->addForeignKey($field,'users','id','RESTRICT','RESTRICT');}$this->create('task_comments');
    }
    private function create(string $table):void{$this->forge->createTable($table,false,$this->db->DBDriver==='MySQLi'?['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']:[]);}
    public function down()
    {
        $this->forge->dropTable('task_comments');$this->forge->dropTable('task_checklist_items');$tasks=$this->db->protectIdentifiers($this->db->prefixTable('tasks'));
        if($this->db->DBDriver==='MySQLi'){$this->db->query('ALTER TABLE '.$tasks.' DROP FOREIGN KEY tasks_parent_fk');$this->db->query('ALTER TABLE '.$tasks.' DROP INDEX tasks_parent_archive');}
        else{$this->db->query('DROP INDEX tasks_parent_archive');}
        if($this->db->DBDriver==='MySQLi'){$this->forge->dropColumn('tasks','parent_id');}else{$this->db->query('ALTER TABLE '.$tasks.' DROP COLUMN parent_id');}
    }
}
