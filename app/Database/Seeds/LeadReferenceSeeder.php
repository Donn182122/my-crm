<?php

declare(strict_types=1);
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;

class LeadReferenceSeeder extends Seeder
{
    public function run()
    {
        $now=date('Y-m-d H:i:s');
        foreach(['manual'=>'Manual','website'=>'Website','referral'=>'Referral','email'=>'Email','phone'=>'Phone','event'=>'Event'] as $slug=>$name){
            if(!$this->db->table('lead_sources')->where('slug',$slug)->countAllResults()){$this->db->table('lead_sources')->ignore(true)->insert(['slug'=>$slug,'name'=>$name,'active'=>1,'created_at'=>$now,'updated_at'=>$now]);}
        }
        foreach(['new'=>['New',10,'open',0],'contacted'=>['Contacted',20,'open',0],'qualified'=>['Qualified',30,'open',0],'negotiation'=>['Negotiation',40,'open',0],'lost'=>['Lost',50,'lost',0],'converted'=>['Converted',60,'converted',1]] as $slug=>[$name,$position,$outcome,$reserved]){
            if(!$this->db->table('lead_statuses')->where('slug',$slug)->countAllResults()){$this->db->table('lead_statuses')->ignore(true)->insert(compact('slug','name','position','outcome','reserved')+['created_at'=>$now,'updated_at'=>$now]);}
        }
    }
}
