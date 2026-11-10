<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateLeadManagement extends Migration
{
    private function id():array{return ['type'=>'INT','unsigned'=>true,'auto_increment'=>true];}
    private function ref():array{return ['type'=>'INT','unsigned'=>true];}
    private function text(int $size,bool $null=false):array{return ['type'=>'VARCHAR','constraint'=>$size,'null'=>$null];}
    private function times():array{return ['created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME']];}
    private function create(string $table):void{$this->forge->createTable($table,false,$this->db->DBDriver==='MySQLi'?['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']:[]);}
    public function up()
    {
        $this->forge->addField(['id'=>$this->id(),'slug'=>$this->text(50),'name'=>$this->text(80),'active'=>['type'=>'TINYINT','default'=>1]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('slug');$this->create('lead_sources');
        $this->forge->addField(['id'=>$this->id(),'slug'=>$this->text(50),'name'=>$this->text(80),'position'=>['type'=>'INT'],'outcome'=>$this->text(12),'reserved'=>['type'=>'TINYINT','default'=>0]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('slug');$this->forge->addKey('position');$this->create('lead_statuses');
        $this->forge->addField(['id'=>$this->id(),'code'=>$this->text(24),'type'=>$this->text(12),'display_name'=>$this->text(180),'company_name'=>$this->text(180,true),
            'first_name'=>$this->text(80,true),'last_name'=>$this->text(80,true),'email'=>$this->text(190,true),'phone'=>$this->text(40,true),'website'=>$this->text(255,true),
            'notes'=>['type'=>'TEXT','null'=>true],'source_id'=>$this->ref(),'status_id'=>$this->ref(),'owner_id'=>$this->ref(),'created_by'=>$this->ref(),
            'version'=>['type'=>'INT','unsigned'=>true,'default'=>1],'archived_at'=>['type'=>'DATETIME','null'=>true]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('code');$this->forge->addUniqueKey('email');
        foreach([['owner_id','archived_at','status_id'],['status_id','archived_at'],['source_id','archived_at'],['display_name']] as $key){$this->forge->addKey($key);}
        foreach(['owner_id'=>'users','created_by'=>'users','source_id'=>'lead_sources','status_id'=>'lead_statuses'] as $field=>$table){$this->forge->addForeignKey($field,$table,'id','RESTRICT','RESTRICT');}$this->create('leads');
        $this->forge->addField(['id'=>$this->id(),'lead_id'=>$this->ref(),'first_name'=>$this->text(80),'last_name'=>$this->text(80),'job_title'=>$this->text(100,true),'email'=>$this->text(190,true),'phone'=>$this->text(40,true)]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey(['lead_id','email']);$this->forge->addKey('lead_id');$this->forge->addForeignKey('lead_id','leads','id','RESTRICT','CASCADE');$this->create('lead_contacts');
        $this->forge->addField(['id'=>$this->id(),'lead_id'=>$this->ref(),'kind'=>$this->text(12),'line1'=>$this->text(180),'line2'=>$this->text(180,true),'city'=>$this->text(100),'region'=>$this->text(100,true),'postal_code'=>$this->text(24,true),'country'=>$this->text(100)]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addKey('lead_id');$this->forge->addForeignKey('lead_id','leads','id','RESTRICT','CASCADE');$this->create('lead_addresses');
        $this->forge->addField(['id'=>$this->id(),'lead_id'=>$this->ref(),'actor_id'=>$this->ref(),'version'=>$this->ref(),'event'=>$this->text(40),'summary'=>$this->text(255),'metadata'=>['type'=>'TEXT','null'=>true]]+$this->times());
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey(['lead_id','version']);$this->forge->addKey(['lead_id','created_at']);
        $this->forge->addForeignKey('lead_id','leads','id','RESTRICT','CASCADE');$this->forge->addForeignKey('actor_id','users','id','RESTRICT','RESTRICT');$this->create('lead_history');
    }
    public function down(){foreach(['lead_history','lead_addresses','lead_contacts','leads','lead_statuses','lead_sources'] as $table){$this->forge->dropTable($table);}}
}
