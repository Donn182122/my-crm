<?php

declare(strict_types=1);
use App\Services\{TaskDependencyService,TaskCollaborationService,TaskService,TaskInputException,ProjectService,ClientService};
use App\Database\Migrations\{CreateClientManagement,CreateProjectManagement,CreateTaskManagement,CreateTaskCollaboration,CreateTaskDependencies};
use App\Database\Seeds\{ProjectReferenceSeeder,TaskReferenceSeeder};
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
final class TaskDependencyServiceTest extends CIUnitTestCase
{
    private $connection;private TaskService $tasks;private TaskDependencyService $dependencies;private TaskCollaborationService $collaboration;private ProjectService $projects;private int $project;private array $revoked=[];
    private function actor(int $id=2,?array $permissions=null):User
    {
        $actor=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();$actor->id=$id;
        $permissions??=['projects.view','projects.create','projects.edit','projects.archive','projects.members','clients.view','clients.create','tasks.dependencies','tasks.checklist','tasks.comment','tasks.view','tasks.create','tasks.edit','tasks.status','tasks.assign','tasks.archive'];
        $actor->method('can')->willReturnCallback(fn($p)=>!in_array($id,$this->revoked,true)&&($id===1||in_array($p,$permissions,true)));return $actor;
    }
    protected function setUp():void
    {
        parent::setUp();foreach(['2026-11-10-090000_CreateClientManagement','2026-11-11-090000_CreateProjectManagement','2026-11-11-100000_CreateTaskManagement','2026-11-11-110000_CreateTaskCollaboration','2026-11-11-120000_CreateTaskDependencies'] as $file){require_once APPPATH.'Database/Migrations/'.$file.'.php';}
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users(id INTEGER PRIMARY KEY,username VARCHAR(30),active INTEGER)');foreach(range(1,6) as $id){$this->connection->table('users')->insert(['id'=>$id,'username'=>'Fictional'.$id,'active'=>1]);}
        $forge=Database::forge($this->connection);(new CreateClientManagement($forge))->up();(new CreateProjectManagement($forge))->up();(new CreateTaskManagement($forge))->up();(new CreateTaskCollaboration($forge))->up();(new CreateTaskDependencies($forge))->up();
        (new ProjectReferenceSeeder(config('Database'),$this->connection))->run();(new TaskReferenceSeeder(config('Database'),$this->connection))->run();
        $resolver=fn($id)=>$this->actor($id);$this->projects=new ProjectService($this->connection,$resolver);$this->tasks=new TaskService($this->connection,$resolver);$this->collaboration=new TaskCollaborationService($this->connection,$resolver);$this->dependencies=new TaskDependencyService($this->connection,$resolver);
        $client=(new ClientService($this->connection))->save($this->actor(),['type'=>'company','company_name'=>'Fictional private client','status_id'=>'1']);
        $this->project=$this->projects->save($this->actor(),['client_id'=>(string)$client,'name'=>'Fictional project','status_id'=>'1']);
    }
    protected function tearDown():void{$this->connection->close();parent::tearDown();}
    private function create(array $data=[]):int{return $this->tasks->save($this->actor(),array_replace(['project_id'=>(string)$this->project,'title'=>'Fictional task'],$data));}
    private function version(int $id):string{return (string)$this->tasks->find($this->actor(1),$id)['version'];}
    private function member(int $id,string $role='member'):void{$this->projects->addMember($this->actor(),$this->project,['user_id'=>(string)$id,'role'=>$role,'version'=>(string)$this->projects->find($this->actor(),$this->project)['version']]);}
    private function invalid(callable $fn,int $status=422):void{try{$fn();$this->fail('Invalid task operation accepted');}catch(TaskInputException $e){$this->assertSame($status,$e->status);}}
    private function denied(callable $fn):void{try{$fn();$this->fail('Foreign task operation accepted');}catch(PageNotFoundException){$this->addToAssertionCount(1);}}
    private function item(int $task,string $label):int{return (int)$this->connection->table('task_checklist_items')->where(['task_id'=>$task,'label'=>$label])->get()->getRow('id');}
    private function addComment(int $id,string $body='Fictional comment',?User $actor=null):int{$this->collaboration->comment($actor??$this->actor(),$id,['body'=>$body,'version'=>$this->version($id)]);return (int)$this->connection->table('task_comments')->where(['task_id'=>$id,'body'=>$body])->get()->getRow('id');}
    private function link(int $pred,int $succ,?User $actor=null):void{$this->dependencies->add($actor??$this->actor(),$succ,['predecessor_id'=>(string)$pred,'version'=>$this->version($succ),'other_version'=>$this->version($pred)]);}
    private function edgeId(int $pred,int $succ):int{return (int)$this->connection->table('task_dependencies')->where(['predecessor_id'=>$pred,'successor_id'=>$succ])->get()->getRow('id');}
    private function unlink(int $pred,int $succ):void{$edge=$this->edgeId($pred,$succ);$this->dependencies->remove($this->actor(),$succ,$edge,['version'=>$this->version($succ),'other_version'=>$this->version($pred),'edge_version'=>(string)$this->connection->table('task_dependencies')->where('id',$edge)->get()->getRow('version')]);}
    public function testCreationUpdatesBothVersionsAndHistoryAndReadiness():void
    {
        $a=$this->create(['title'=>'Private predecessor']);$b=$this->create();$this->link($a,$b);$this->assertSame('2',$this->version($a));$this->assertSame('2',$this->version($b));$this->assertSame('blocked',$this->tasks->find($this->actor(),$b)['dependency_state']);$this->assertSame('ready',$this->tasks->find($this->actor(),$a)['dependency_state']);
        $d=$this->dependencies->details($this->actor(),$b);$this->assertSame($a,(int)$d['prerequisites']['rows'][0]['id']);$this->assertTrue($d['prerequisites']['rows'][0]['can_remove']);$this->assertSame([],$d['choices']);$this->assertSame($b,(int)$this->dependencies->details($this->actor(),$a)['dependents']['rows'][0]['id']);$this->assertStringNotContainsString('Private predecessor',json_encode($this->tasks->details($this->actor(),$b)['history']));
    }
    public function testRemovalRetainsAuditAndReactivationReusesUniquePair():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$id=$this->edgeId($a,$b);$this->unlink($a,$b);$this->assertSame('ready',$this->tasks->find($this->actor(),$b)['dependency_state']);$this->assertSame(0,$this->dependencies->details($this->actor(),$b)['prerequisites']['total']);$this->assertNotNull($this->connection->table('task_dependencies')->where('id',$id)->get()->getRow('archived_at'));
        $this->link($a,$b);$this->assertSame($id,$this->edgeId($a,$b));$this->assertSame(1,$this->connection->table('task_dependencies')->countAllResults());$this->assertSame(3,(int)$this->connection->table('task_dependencies')->where('id',$id)->get()->getRow('version'));$this->assertSame('4',$this->version($b));
    }
    public function testDuplicateAndSelfDependenciesLeaveVersionsUnchanged():void
    {
        $a=$this->create();$b=$this->create();$this->invalid(fn()=>$this->link($a,$a));$this->link($a,$b);$this->invalid(fn()=>$this->link($a,$b));$this->assertSame('2',$this->version($a));$this->assertSame('2',$this->version($b));
    }
    public function testDirectAndMultistepCyclesAreRejected():void
    {
        $a=$this->create();$b=$this->create();$c=$this->create();$this->link($a,$b);$this->invalid(fn()=>$this->link($b,$a));$this->link($b,$c);$this->invalid(fn()=>$this->link($c,$a));$this->assertSame(2,$this->connection->table('task_dependencies')->where('archived_at',null)->countAllResults());
    }
    public function testCrossProjectAndUnauthorizedReferencesAreRejected():void
    {
        $a=$this->create();$p=$this->projects->find($this->actor(),$this->project);$other=$this->projects->save($this->actor(),['name'=>'Other','client_id'=>(string)$p['client_id'],'status_id'=>'1']);$b=$this->create(['project_id'=>(string)$other]);$this->invalid(fn()=>$this->link($a,$b));$this->denied(fn()=>$this->link($a,$b,$this->actor(4)));$this->denied(fn()=>$this->dependencies->add($this->actor(),$a,['predecessor_id'=>'999999','version'=>'1','other_version'=>'1']));
    }
    public function testSubtasksAreExcludedAndDoNotInheritEdges():void
    {
        $a=$this->create();$b=$this->create();$child=$this->create(['parent_id'=>(string)$b]);$this->invalid(fn()=>$this->link($a,$child));$this->invalid(fn()=>$this->link($child,$a));$this->link($a,$b);$this->tasks->status($this->actor(),$child,'4','1');$this->assertSame('not-applicable',$this->tasks->find($this->actor(),$child)['dependency_state']);$this->assertNull($this->tasks->find($this->actor(),$b)['completed_at']);
    }
    public function testAssignedMembersAndManagersWithoutCapabilityCannotManageEdges():void
    {
        $this->member(3);$a=$this->create(['assignee_id'=>'3']);$b=$this->create(['assignee_id'=>'3']);$this->denied(fn()=>$this->link($a,$b,$this->actor(3)));$this->denied(fn()=>$this->link($a,$b,$this->actor(2,['projects.view','tasks.view'])));$this->assertFalse($this->dependencies->details($this->actor(3),$b)['canManage']);
    }
    public function testMembershipRevocationRejectsPreviouslyAuthorizedManager():void
    {
        $this->member(3,'manager');$a=$this->create();$b=$this->create();$this->link($a,$b,$this->actor(3));$this->connection->table('project_members')->where(['project_id'=>$this->project,'user_id'=>3])->delete();$this->denied(fn()=>$this->dependencies->details($this->actor(3),$a));$this->denied(fn()=>$this->link($b,$a,$this->actor(3)));
    }
    public function testHiddenPrerequisitesRemainBlockingWithoutLeakingListsOrCounts():void
    {
        $this->member(3);$a=$this->create(['title'=>'Restricted prerequisite']);$b=$this->create(['assignee_id'=>'3']);$this->link($a,$b);$task=$this->tasks->find($this->actor(3),$b);$d=$this->dependencies->details($this->actor(3),$b);$this->assertSame('blocked',$task['dependency_state']);$this->assertSame(0,$d['prerequisites']['total']);$this->assertSame([],$d['prerequisites']['rows']);$this->assertSame([],$d['choices']);$this->assertStringNotContainsString('Restricted prerequisite',json_encode($d));$this->invalid(fn()=>$this->tasks->status($this->actor(3),$b,'4',$this->version($b)));
    }
    public function testCompletionGuardCoversStatusAndCrudSave():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$this->invalid(fn()=>$this->tasks->status($this->actor(),$b,'4',$this->version($b)));$this->invalid(fn()=>$this->tasks->save($this->actor(),['status_id'=>'4','version'=>$this->version($b)],$b));$this->tasks->status($this->actor(),$a,'4',$this->version($a));$this->assertSame('ready',$this->tasks->find($this->actor(),$b)['dependency_state']);$this->assertNull($this->tasks->find($this->actor(),$b)['completed_at']);$this->tasks->save($this->actor(),['status_id'=>'4','version'=>$this->version($b)],$b);$this->assertNotNull($this->tasks->find($this->actor(),$b)['completed_at']);
    }
    public function testCompletedPredecessorCannotReopenUntilCompletedDependentReopens():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);foreach([$a,$b] as $id){$this->tasks->status($this->actor(),$id,'4',$this->version($id));}$this->invalid(fn()=>$this->tasks->status($this->actor(),$a,'1',$this->version($a)));$this->invalid(fn()=>$this->tasks->save($this->actor(),['status_id'=>'5','version'=>$this->version($a)],$a));$this->tasks->status($this->actor(),$b,'1',$this->version($b));$this->tasks->status($this->actor(),$a,'1',$this->version($a));$this->assertSame('blocked',$this->tasks->find($this->actor(),$b)['dependency_state']);
    }
    public function testCompletedDependentCannotAcquireIncompletePredecessor():void
    {
        $a=$this->create();$b=$this->create();$this->tasks->status($this->actor(),$b,'4','1');$this->invalid(fn()=>$this->link($a,$b));$this->assertSame(0,$this->connection->table('task_dependencies')->countAllResults());$this->tasks->status($this->actor(),$a,'4','1');$this->link($a,$b);$this->assertNotNull($this->tasks->find($this->actor(),$b)['completed_at']);
    }
    public function testArchivedIncompletePrerequisiteStillBlocksAndMustBeRestoredToRemove():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$this->tasks->archive($this->actor(),$a,true,$this->version($a));$this->invalid(fn()=>$this->tasks->status($this->actor(),$b,'4',$this->version($b)));$this->invalid(fn()=>$this->unlink($a,$b));$this->tasks->archive($this->actor(),$a,false,$this->version($a));$this->unlink($a,$b);$this->assertSame('ready',$this->tasks->find($this->actor(),$b)['dependency_state']);
    }
    public function testArchivedDonePredecessorSatisfiesAndArchivedDoneDependentPreventsReopen():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$this->tasks->status($this->actor(),$a,'4',$this->version($a));$this->tasks->archive($this->actor(),$a,true,$this->version($a));$this->tasks->status($this->actor(),$b,'4',$this->version($b));$this->tasks->archive($this->actor(),$a,false,$this->version($a));$this->tasks->archive($this->actor(),$b,true,$this->version($b));$this->invalid(fn()=>$this->tasks->status($this->actor(),$a,'1',$this->version($a)));
    }
    public function testReadonlyProjectsRetainGraphAndRejectMutations():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->archive($this->actor(),$this->project,true,$pv);$this->invalid(fn()=>$this->unlink($a,$b));$this->assertFalse($this->dependencies->details($this->actor(),$b)['canManage']);$this->assertSame(1,$this->connection->table('task_dependencies')->countAllResults());
    }
    public function testStaleTaskAndEdgeVersionsReturn409WithoutPartialWrites():void
    {
        $a=$this->create();$b=$this->create();$this->tasks->status($this->actor(),$a,'2','1');$this->invalid(fn()=>$this->dependencies->add($this->actor(),$b,['predecessor_id'=>(string)$a,'version'=>'1','other_version'=>'1']),409);$this->link($a,$b);$this->invalid(fn()=>$this->dependencies->remove($this->actor(),$b,$this->edgeId($a,$b),['version'=>$this->version($b),'other_version'=>$this->version($a),'edge_version'=>'2']),409);$this->invalid(fn()=>$this->tasks->status($this->actor(),$b,'2','1'),409);$this->assertSame(1,$this->connection->table('task_dependencies')->where('archived_at',null)->countAllResults());
    }
    public function testEdgeIdMustBelongToCurrentAuthorizedTask():void
    {
        $a=$this->create();$b=$this->create();$c=$this->create();$this->link($a,$b);$this->denied(fn()=>$this->dependencies->remove($this->actor(),$c,$this->edgeId($a,$b),['version'=>'1','other_version'=>'2','edge_version'=>'1']));
    }
    public function testSecondHistoryFailureRollsBackEdgeAndBothTaskVersions():void
    {
        $a=$this->create();$b=$this->create();$this->connection->query("CREATE TRIGGER reject_dependency BEFORE INSERT ON task_history WHEN NEW.event='dependency_added' AND NEW.task_id=".$b." BEGIN SELECT RAISE(ABORT,'Fictional dependency failure'); END");
        try{$this->link($a,$b);$this->fail('History failure must abort edge');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame(0,$this->connection->table('task_dependencies')->countAllResults());$this->assertSame('1',$this->version($a));$this->assertSame('1',$this->version($b));$this->assertSame(2,$this->connection->table('task_history')->countAllResults());
    }
    public function testFailedRemovalAndOuterRollbackRetainCompleteState():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$this->connection->transBegin();$this->unlink($a,$b);$this->assertSame(1,$this->connection->transDepth);$this->connection->transRollback();$this->assertSame('2',$this->version($a));$this->assertSame('blocked',$this->tasks->find($this->actor(),$b)['dependency_state']);$this->assertNull($this->connection->table('task_dependencies')->where('id',$this->edgeId($a,$b))->get()->getRow('archived_at'));
    }
    public function testDatabaseRejectsCrossProjectForeignKeysAndSelfRelationship():void
    {
        $a=$this->create();$data=['project_id'=>$this->project,'predecessor_id'=>$a,'successor_id'=>$a,'created_by'=>2,'version'=>1,'created_at'=>'2026-11-11 10:00:00','updated_at'=>'2026-11-11 10:00:00'];try{$this->connection->table('task_dependencies')->insert($data);$this->fail('Self relationship accepted');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
        $data['successor_id']=999999;try{$this->connection->table('task_dependencies')->insert($data);$this->fail('Orphan accepted');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
    }
    public function testMigrationRoundTripPreservesTaskRowsAndCollaboration():void
    {
        $a=$this->create();$this->addComment($a);$before=$this->connection->table('tasks')->where('id',$a)->get()->getRowArray();$forge=Database::forge($this->connection);(new CreateTaskDependencies($forge))->down();$this->assertSame($before,$this->connection->table('tasks')->where('id',$a)->get()->getRowArray());$this->assertSame(1,$this->connection->table('task_comments')->countAllResults());(new CreateTaskDependencies($forge))->up();$this->assertSame('ready',$this->tasks->find($this->actor(),$a)['dependency_state']);
    }
    public function testRelatedListsAndSearchAreBoundedAndPaginated():void
    {
        $root=$this->create();for($i=0;$i<22;$i++){$this->link($this->create(['title'=>'Prerequisite '.$i]),$root);}for($i=0;$i<22;$i++){$this->create(['title'=>'Available '.$i]);}$first=$this->dependencies->details($this->actor(),$root);$next=$this->dependencies->details($this->actor(),$root,['prerequisites_page'=>'2','dependency_q'=>'Available 21']);$this->assertCount(20,$first['prerequisites']['rows']);$this->assertCount(2,$next['prerequisites']['rows']);$this->assertCount(20,$first['choices']);$this->assertCount(1,$next['choices']);
    }
    public function testInvalidIdsAreRejectedAndCancelledPredecessorsDoNotSatisfy():void
    {
        $a=$this->create();foreach([null,'0','-1','1.2',[],str_repeat('9',30)] as $id){$this->invalid(fn()=>$this->dependencies->add($this->actor(),$a,['predecessor_id'=>$id,'version'=>'1','other_version'=>'1']));}$b=$this->create();$this->link($a,$b);$this->tasks->status($this->actor(),$a,'5',$this->version($a));$this->invalid(fn()=>$this->tasks->status($this->actor(),$b,'4',$this->version($b)));$this->assertSame('blocked',$this->tasks->find($this->actor(),$b)['dependency_state']);
    }
    public function testCompositeForeignKeyRejectsAnExistingTaskFromAnotherProject():void
    {
        $a=$this->create();$p=$this->projects->find($this->actor(),$this->project);$other=$this->projects->save($this->actor(),['name'=>'Foreign project','client_id'=>(string)$p['client_id'],'status_id'=>'1']);$b=$this->create(['project_id'=>(string)$other]);$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('task_dependencies')->insert(['project_id'=>$this->project,'predecessor_id'=>$a,'successor_id'=>$b,'created_by'=>2,'version'=>1,'created_at'=>'2026-11-11 10:00:00','updated_at'=>'2026-11-11 10:00:00']);
    }
    public function testCompletedAndCancelledProjectsRejectGraphAndCompletionWrites():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);foreach(['4','5'] as $status){$this->projects->save($this->actor(),['name'=>'Fictional project','status_id'=>$status,'version'=>(string)$this->projects->find($this->actor(),$this->project)['version']],$this->project);$this->invalid(fn()=>$this->unlink($a,$b));$this->invalid(fn()=>$this->tasks->status($this->actor(),$a,'4',$this->version($a)));$this->assertFalse($this->dependencies->details($this->actor(),$b)['canManage']);}
    }
    public function testFailedRemovalRollsBackBothHistoryAndSoftArchive():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$this->connection->query("CREATE TRIGGER reject_dependency_removal BEFORE INSERT ON task_history WHEN NEW.event='dependency_removed' AND NEW.task_id=".$b." BEGIN SELECT RAISE(ABORT,'Fictional removal failure'); END");try{$this->unlink($a,$b);$this->fail('Removal history failure accepted');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame('2',$this->version($a));$this->assertSame('2',$this->version($b));$this->assertNull($this->connection->table('task_dependencies')->where('id',$this->edgeId($a,$b))->get()->getRow('archived_at'));$this->assertSame(4,$this->connection->table('task_history')->countAllResults());
    }
    public function testGraphCapacityFailsClosedWithoutWritingTheEdge():void
    {
        // Direct fictional setup keeps the capacity test bounded; application writes are exercised separately.
        $ids=[];for($i=0;$i<64;$i++){$ids[]=$this->create();}$rows=[];foreach($ids as $i=>$a){foreach(array_slice($ids,$i+1) as $b){$rows[]=['project_id'=>$this->project,'predecessor_id'=>$a,'successor_id'=>$b,'created_by'=>2,'version'=>1,'created_at'=>'2026-11-11 10:00:00','updated_at'=>'2026-11-11 10:00:00'];}}$this->connection->table('task_dependencies')->insertBatch(array_slice($rows,0,2000));$this->invalid(fn()=>$this->link($ids[62],$ids[63]));$this->assertSame(2000,$this->connection->table('task_dependencies')->countAllResults());$this->assertSame('1',$this->version($ids[63]));
    }
    public function testRemovalFromPredecessorContextUsesBothEndpointVersions():void
    {
        $a=$this->create();$b=$this->create();$this->link($a,$b);$row=$this->dependencies->details($this->actor(),$a)['dependents']['rows'][0];$this->assertTrue($row['can_remove']);$this->dependencies->remove($this->actor(),$a,(int)$row['edge_id'],['version'=>$this->version($a),'other_version'=>$this->version($b),'edge_version'=>(string)$row['edge_version']]);$this->assertSame('3',$this->version($a));$this->assertSame('3',$this->version($b));$this->assertSame('ready',$this->tasks->find($this->actor(),$b)['dependency_state']);$this->assertSame(0,$this->connection->table('task_dependencies')->where('archived_at',null)->countAllResults());
    }
}
