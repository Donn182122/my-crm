<?php

declare(strict_types=1);
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;
class TaskReferenceSeeder extends Seeder
{
    public function run()
    {
        $now=date('Y-m-d H:i:s');$position=0;
        foreach(['todo'=>['To Do','open'],'in-progress'=>['In Progress','open'],'review'=>['Review','open'],'done'=>['Done','done'],'cancelled'=>['Cancelled','cancelled']] as $slug=>[$name,$category]){$position+=10;if(!$this->db->table('task_statuses')->where('slug',$slug)->countAllResults()){$this->db->table('task_statuses')->ignore(true)->insert(compact('slug','name','category','position')+['active'=>1,'created_at'=>$now,'updated_at'=>$now]);}}
        $position=0;foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $slug=>$name){$position+=10;if(!$this->db->table('task_priorities')->where('slug',$slug)->countAllResults()){$this->db->table('task_priorities')->ignore(true)->insert(compact('slug','name','position')+['active'=>1,'created_at'=>$now,'updated_at'=>$now]);}}
    }
}
