<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateLeadConversions extends Migration
{
    public function up()
    {
        $id=['type'=>'INT','unsigned'=>true];
        $this->forge->addField(['id'=>$id+['auto_increment'=>true],'lead_id'=>$id,'client_id'=>$id,'actor_id'=>$id,
            'request_key'=>['type'=>'VARCHAR','constraint'=>64],'mode'=>['type'=>'VARCHAR','constraint'=>12],
            'lead_version'=>$id,'preview_digest'=>['type'=>'VARCHAR','constraint'=>64],'details'=>['type'=>'TEXT'],
            'converted_at'=>['type'=>'DATETIME'],'created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME']]);
        $this->forge->addPrimaryKey('id');$this->forge->addUniqueKey('lead_id');$this->forge->addUniqueKey('request_key');$this->forge->addKey('client_id');
        foreach(['lead_id'=>'leads','client_id'=>'clients','actor_id'=>'users'] as $field=>$table){$this->forge->addForeignKey($field,$table,'id','RESTRICT','RESTRICT');}
        $this->forge->createTable('lead_conversions',false,$this->db->DBDriver==='MySQLi'?['ENGINE'=>'InnoDB','CHARACTER SET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']:[]);
        $this->db->table('lead_statuses')->where(['slug'=>'converted','name'=>'Converted (reserved)'])->update(['name'=>'Converted']);
    }
    public function down(){$this->forge->dropTable('lead_conversions');}
}
