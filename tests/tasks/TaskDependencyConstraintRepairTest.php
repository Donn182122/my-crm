<?php

declare(strict_types=1);
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use App\Database\Migrations\{CreateTaskDependencies,EnsureTaskDependencyDistinctConstraint};
final class TaskDependencyConstraintRepairTest extends CIUnitTestCase
{
    private $repairDb;private $repair;
    protected function setUp():void
    {
        parent::setUp();$path=getenv('CRM_REPAIR_TEST_CONFIG');if(!$path||!is_file($path)){$this->markTestSkipped('Explicit disposable MariaDB configuration required.');}
        $config=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(!preg_match('/^my_crm_repair_test_[0-9a-f]{8}$/D',$config['database']??'')||($config['DBDriver']??'')!=='MySQLi'){throw new RuntimeException('Disposable database guard failed.');}
        $this->repairDb=Database::connect($config,false);$this->assertSame($config['database'],$this->repairDb->getDatabase());
        foreach(['task_dependencies','tasks','projects','users'] as $table){$this->repairDb->query('DROP TABLE IF EXISTS '.$table);}
        $this->repairDb->query('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$this->repairDb->query('CREATE TABLE projects(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$this->repairDb->query('CREATE TABLE tasks(id INT UNSIGNED PRIMARY KEY,project_id INT UNSIGNED NOT NULL,parent_id INT UNSIGNED NULL) ENGINE=InnoDB');
        $this->repairDb->query('INSERT INTO users VALUES(1)');$this->repairDb->query('INSERT INTO projects VALUES(1)');$this->repairDb->query('INSERT INTO tasks VALUES(1,1,NULL),(2,1,NULL),(3,1,NULL)');
        require_once APPPATH.'Database/Migrations/2026-11-11-120000_CreateTaskDependencies.php';require_once APPPATH.'Database/Migrations/2026-11-12-153000_EnsureTaskDependencyDistinctConstraint.php';
        (new CreateTaskDependencies(Database::forge($this->repairDb)))->up();$this->repair=new EnsureTaskDependencyDistinctConstraint(Database::forge($this->repairDb));
    }
    protected function tearDown():void{if($this->repairDb){if($this->repairDb->transDepth){$this->repairDb->transRollback();}foreach(['task_dependencies','tasks','projects','users'] as $table){$this->repairDb->query('DROP TABLE IF EXISTS '.$table);}$this->repairDb->close();}parent::tearDown();}
    private function checks():array{return $this->repairDb->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='task_dependencies'")->getResultArray();}
    private function missing():void{if($this->checks()){$this->repairDb->query('ALTER TABLE task_dependencies DROP CONSTRAINT task_dependencies_distinct');}}
    private function insert(int $a=1,int $b=2):void{$this->repairDb->table('task_dependencies')->insert(['project_id'=>1,'predecessor_id'=>$a,'successor_id'=>$b,'created_by'=>1,'created_at'=>'2026-11-12 00:00:00','updated_at'=>'2026-11-12 00:00:00']);}
    private function refused(callable $call,string $fragment):void{try{$call();$this->fail('Expected safe refusal.');}catch(RuntimeException $e){$this->assertStringContainsString($fragment,$e->getMessage());}}
    public function testFreshOriginalMigrationCreatesNamedCheckAndRepairIsNoOp():void{$this->assertCount(1,$this->checks());$this->assertSame('task_dependencies_distinct',$this->checks()[0]['CONSTRAINT_NAME']);$this->insert();$before=$this->repairDb->table('task_dependencies')->get()->getResultArray();$this->repair->up();$this->repair->up();$this->assertSame($before,$this->repairDb->table('task_dependencies')->get()->getResultArray());$this->assertCount(1,$this->checks());}
    public function testMissingConstraintRepairedWithoutChangingRecordsAndIdempotent():void{$this->missing();$this->insert();$before=$this->repairDb->table('task_dependencies')->get()->getResultArray();$this->repair->up();$this->repair->up();$this->assertSame($before,$this->repairDb->table('task_dependencies')->get()->getResultArray());$this->assertCount(1,$this->checks());}
    public function testSelfInsertAndUpdateRejectedByMariaDbAfterRepair():void{$this->missing();$this->repair->up();foreach(['insert','update'] as $operation){if($operation==='update'){$this->insert();}try{if($operation==='insert'){$this->insert(1,1);}else{$this->repairDb->query('UPDATE task_dependencies SET successor_id=predecessor_id');}$this->fail('CHECK enforcement failed.');}catch(\CodeIgniter\Database\Exceptions\DatabaseException $e){$this->assertStringContainsString('task_dependencies_distinct',$e->getMessage());}}$this->assertSame('2',(string)$this->repairDb->table('task_dependencies')->get()->getRow('successor_id'));}
    public function testExistingSelfLinkAbortsAndPreservesAllData():void{$this->missing();$this->insert(1,1);$before=$this->repairDb->table('task_dependencies')->get()->getResultArray();$this->refused(fn()=>$this->repair->up(),'Existing self-dependencies');$this->assertSame($before,$this->repairDb->table('task_dependencies')->get()->getResultArray());$this->assertCount(0,$this->checks());}
    public function testEquivalentOtherNameRequiresCompatibilityReview():void{$this->missing();$this->repairDb->query('ALTER TABLE task_dependencies ADD CONSTRAINT alternate_distinct CHECK (successor_id != predecessor_id)');$this->refused(fn()=>$this->repair->up(),'another name');$this->assertCount(1,$this->checks());$this->assertSame('alternate_distinct',$this->checks()[0]['CONSTRAINT_NAME']);}
    public function testIncorrectNamedExpressionRefusedWithoutChanges():void{$this->missing();$this->repairDb->query('ALTER TABLE task_dependencies ADD CONSTRAINT task_dependencies_distinct CHECK (predecessor_id > 0)');$this->refused(fn()=>$this->repair->up(),'unexpected expression');$this->assertStringContainsString('> 0',$this->checks()[0]['CHECK_CLAUSE']);}
    public function testUnrecognizedCheckRequiresCompatibilityReview():void{$this->missing();$this->repairDb->query('ALTER TABLE task_dependencies ADD CONSTRAINT other_expression CHECK (predecessor_id > 0)');$this->refused(fn()=>$this->repair->up(),'Unrecognized CHECK');$this->assertCount(1,$this->checks());}
    public function testDisabledEnforcementRefused():void{$this->missing();$this->repairDb->query('SET SESSION check_constraint_checks=0');try{$this->refused(fn()=>$this->repair->up(),'enforcement must be enabled');$this->assertCount(0,$this->checks());}finally{$this->repairDb->query('SET SESSION check_constraint_checks=1');}}
    public function testOrdinaryTransactionRefusedWithoutImplicitCommit():void{$this->missing();$this->repairDb->transBegin();$this->refused(fn()=>$this->repair->up(),'outside an ordinary transaction');$this->assertSame(1,$this->repairDb->transDepth);$this->repairDb->transRollback();$this->assertCount(0,$this->checks());}
    public function testDownCannotRemoveIntegrityConstraint():void{$this->refused(fn()=>$this->repair->down(),'automatic constraint removal');$this->assertCount(1,$this->checks());}
}