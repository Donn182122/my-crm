<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateClientManagement extends Migration
{
    private function id(): array { return ['type'=>'INT','unsigned'=>true,'auto_increment'=>true]; }
    private function ref(bool $nullable = false): array { return ['type'=>'INT','unsigned'=>true,'null'=>$nullable]; }
    private function text(int $length, bool $nullable = false): array { return ['type'=>'VARCHAR','constraint'=>$length,'null'=>$nullable]; }
    private function timestamps(): array { return ['created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME']]; }
    private function create(string $name): void
    {
        $this->forge->createTable($name, false, $this->db->DBDriver === 'MySQLi' ? ['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci'] : []);
    }
    public function up()
    {
        foreach (['client_statuses','client_groups'] as $table) {
            $this->forge->addField(['id'=>$this->id(),'name'=>$this->text(80),'name_key'=>$this->text(80)] + $this->timestamps());
            $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey('name_key'); $this->create($table);
        }
        $now = date('Y-m-d H:i:s');
        foreach (['Active','Prospect','Inactive'] as $name) {
            $this->db->table('client_statuses')->insert(['name'=>$name,'name_key'=>strtolower($name),'created_at'=>$now,'updated_at'=>$now]);
        }
        $this->forge->addField([
            'id'=>$this->id(),'code'=>$this->text(24),'type'=>$this->text(12),'company_name'=>$this->text(180,true),
            'first_name'=>$this->text(80,true),'last_name'=>$this->text(80,true),'display_name'=>$this->text(180),
            'email'=>$this->text(190,true),'phone'=>$this->text(40,true),'website'=>$this->text(255,true),
            'notes'=>['type'=>'TEXT','null'=>true],'status_id'=>$this->ref(),'owner_id'=>$this->ref(),
            'archived_at'=>['type'=>'DATETIME','null'=>true],
        ] + $this->timestamps());
        $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey('code'); $this->forge->addUniqueKey('email');
        $this->forge->addKey(['owner_id','archived_at']); $this->forge->addKey(['status_id','archived_at']); $this->forge->addKey('display_name');
        $this->forge->addForeignKey('status_id','client_statuses','id','RESTRICT','RESTRICT');
        $this->forge->addForeignKey('owner_id','users','id','RESTRICT','RESTRICT'); $this->create('clients');
        $this->forge->addField(['client_id'=>$this->ref(),'group_id'=>$this->ref()]);
        $this->forge->addPrimaryKey(['client_id','group_id']); $this->forge->addKey('group_id');
        $this->forge->addForeignKey('client_id','clients','id','RESTRICT','CASCADE');
        $this->forge->addForeignKey('group_id','client_groups','id','RESTRICT','RESTRICT'); $this->create('client_group_memberships');
        $this->forge->addField(['id'=>$this->id(),'client_id'=>$this->ref(),'first_name'=>$this->text(80),'last_name'=>$this->text(80),
            'job_title'=>$this->text(100,true),'email'=>$this->text(190,true),'phone'=>$this->text(40,true)] + $this->timestamps());
        $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey(['client_id','email']); $this->forge->addKey('client_id');
        $this->forge->addForeignKey('client_id','clients','id','RESTRICT','CASCADE'); $this->create('client_contacts');
        $this->forge->addField(['id'=>$this->id(),'client_id'=>$this->ref(),'kind'=>$this->text(12),
            'line1'=>$this->text(180),'line2'=>$this->text(180,true),'city'=>$this->text(100),'region'=>$this->text(100,true),
            'postal_code'=>$this->text(24,true),'country'=>$this->text(100)] + $this->timestamps());
        $this->forge->addPrimaryKey('id'); $this->forge->addKey('client_id');
        $this->forge->addForeignKey('client_id','clients','id','RESTRICT','CASCADE'); $this->create('client_addresses');
    }
    public function down()
    {
        foreach (['client_addresses','client_contacts','client_group_memberships','clients','client_groups','client_statuses'] as $table) { $this->forge->dropTable($table); }
    }
}
