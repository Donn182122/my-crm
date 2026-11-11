<?php

declare(strict_types=1);
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;
class ProjectReferenceSeeder extends Seeder
{
    public function run()
    {
        $now=date('Y-m-d H:i:s');$position=0;
        foreach(['planning'=>'Planning','active'=>'Active','on-hold'=>'On Hold','completed'=>'Completed','cancelled'=>'Cancelled'] as $slug=>$name){$position+=10;
            if(!$this->db->table('project_statuses')->where('slug',$slug)->countAllResults()){$this->db->table('project_statuses')->ignore(true)->insert(compact('slug','name','position')+['active'=>1,'created_at'=>$now,'updated_at'=>$now]);}
        }
    }
}
