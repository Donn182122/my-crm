<?php

declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;

/** Forward-only repair. ALTER TABLE commits implicitly on MariaDB. */
class EnsureTaskDependencyDistinctConstraint extends Migration
{
    public function up()
    {
        $table=$this->db->prefixTable('task_dependencies');
        if(!$this->db->tableExists('task_dependencies')){throw new RuntimeException('Task dependency table is required; no repair performed.');}
        foreach(['predecessor_id','successor_id'] as $column){if(!$this->db->fieldExists($column,'task_dependencies')){throw new RuntimeException('Expected dependency endpoint column missing; no repair performed.');}}
        $quoted=$this->db->protectIdentifiers($table);
        if((int)$this->db->query('SELECT COUNT(*) AS n FROM '.$quoted.' WHERE predecessor_id = successor_id')->getRow('n')!==0){throw new RuntimeException('Existing self-dependencies require separate data review; no records or schema changed.');}
        if($this->db->DBDriver==='SQLite3'){
            $sql=(string)$this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=?",[$table])->getRow('sql');
            if(preg_match('/CONSTRAINT\s+task_dependencies_distinct\s+CHECK\s*\(\s*predecessor_id\s*<>\s*successor_id\s*\)/i',$sql)){return;}
            throw new RuntimeException('SQLite repair needs a separate table-rebuild review; no changes performed.');
        }
        if($this->db->DBDriver!=='MySQLi'){throw new RuntimeException('Unsupported database driver for guarded constraint repair.');}
        if($this->db->transDepth!==0){throw new RuntimeException('MariaDB DDL must run outside an ordinary transaction.');}
        if((int)$this->db->query('SELECT @@SESSION.check_constraint_checks AS enabled')->getRow('enabled')!==1){throw new RuntimeException('CHECK enforcement must be enabled; no repair performed.');}
        $checks=$this->db->query('SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->getResultArray();
        $named=false;$equivalents=[];
        foreach($checks as $check){
            $normalized=strtolower(preg_replace('/[`\s()]+/','',(string)$check['CHECK_CLAUSE']));
            $equivalent=in_array($normalized,['predecessor_id<>successor_id','successor_id<>predecessor_id','predecessor_id!=successor_id','successor_id!=predecessor_id'],true);
            if(strcasecmp($check['CONSTRAINT_NAME'],'task_dependencies_distinct')===0){if(!$equivalent){throw new RuntimeException('Named constraint has an unexpected expression; compatibility review required.');}$named=true;}
            elseif($equivalent){$equivalents[]=$check['CONSTRAINT_NAME'];}
        }
        if($equivalents){throw new RuntimeException('Equivalent CHECK exists under another name; compatibility review required, no duplicate added.');}
        if($named){return;}
        if($checks){throw new RuntimeException('Unrecognized CHECK requires compatibility review; no duplicate added.');}
        $this->db->query('ALTER TABLE '.$quoted.' ADD CONSTRAINT task_dependencies_distinct CHECK (predecessor_id <> successor_id)');
        $row=$this->db->query('SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE=?',[$table,'task_dependencies_distinct','CHECK'])->getRow('n');
        if((int)$row!==1){throw new RuntimeException('Post-DDL verification failed; inspect schema before retrying.');}
    }
    public function down()
    {
        throw new RuntimeException('Forward-only integrity repair: automatic constraint removal is prohibited.');
    }
}