<?php

declare(strict_types=1);
use App\Services\{TaskService,TaskInputException,ProjectService,ClientService};
use App\Database\Migrations\{CreateClientManagement,CreateProjectManagement,CreateTaskManagement,CreateTaskCollaboration,CreateTaskDependencies};
use App\Database\Seeds\{ProjectReferenceSeeder,TaskReferenceSeeder};
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
final class TaskServiceTest extends CIUnitTestCase
{
    private $connection;private TaskService $tasks;private ProjectService $projects;private int $project;private array $revoked=[];
    private function actor(int $id=2,?array $permissions=null):User
    {
        $actor=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();$actor->id=$id;
        $permissions??=['projects.view','projects.create','projects.edit','projects.archive','projects.members','clients.view','clients.create','tasks.view','tasks.create','tasks.edit','tasks.status','tasks.assign','tasks.archive'];
        $actor->method('can')->willReturnCallback(fn($p)=>!in_array($id,$this->revoked,true)&&($id===1||in_array($p,$permissions,true)));return $actor;
    }
    protected function setUp():void
    {
        parent::setUp();foreach(['2026-11-10-090000_CreateClientManagement','2026-11-11-090000_CreateProjectManagement','2026-11-11-100000_CreateTaskManagement','2026-11-11-110000_CreateTaskCollaboration','2026-11-11-120000_CreateTaskDependencies'] as $file){require_once APPPATH.'Database/Migrations/'.$file.'.php';}
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users(id INTEGER PRIMARY KEY,username VARCHAR(30),active INTEGER)');foreach(range(1,6) as $id){$this->connection->table('users')->insert(['id'=>$id,'username'=>'Fictional'.$id,'active'=>1]);}
        $forge=Database::forge($this->connection);(new CreateClientManagement($forge))->up();(new CreateProjectManagement($forge))->up();(new CreateTaskManagement($forge))->up();(new CreateTaskCollaboration($forge))->up();(new CreateTaskDependencies($forge))->up();
        (new ProjectReferenceSeeder(config('Database'),$this->connection))->run();(new TaskReferenceSeeder(config('Database'),$this->connection))->run();
        $resolver=fn($id)=>$this->actor($id);$this->projects=new ProjectService($this->connection,$resolver);$this->tasks=new TaskService($this->connection,$resolver);
        $client=(new ClientService($this->connection))->save($this->actor(),['type'=>'company','company_name'=>'Fictional private client','status_id'=>'1']);
        $this->project=$this->projects->save($this->actor(),['client_id'=>(string)$client,'name'=>'Fictional project','status_id'=>'1']);
    }
    protected function tearDown():void{$this->connection->close();parent::tearDown();}
    private function create(array $data=[]):int{return $this->tasks->save($this->actor(),array_replace(['project_id'=>(string)$this->project,'title'=>'Fictional task'],$data));}
    private function version(int $id):string{return (string)$this->tasks->find($this->actor(1),$id)['version'];}
    private function member(int $id,string $role='member'):void{$this->projects->addMember($this->actor(),$this->project,['user_id'=>(string)$id,'role'=>$role,'version'=>(string)$this->projects->find($this->actor(),$this->project)['version']]);}
    private function invalid(callable $fn,int $status=422):void{try{$fn();$this->fail('Invalid task operation accepted');}catch(TaskInputException $e){$this->assertSame($status,$e->status);}}
    private function denied(callable $fn):void{try{$fn();$this->fail('Foreign task operation accepted');}catch(PageNotFoundException){$this->addToAssertionCount(1);}}
    public function testCreationDerivesIdentityAndCompletion():void
    {
        $id=$this->create(['code'=>'FORGED','created_by'=>'1','version'=>'90','completed_at'=>'2020-01-01']);$row=$this->tasks->find($this->actor(),$id);
        $this->assertStringStartsWith('TK-',$row['code']);$this->assertSame(2,(int)$row['created_by']);$this->assertSame(1,(int)$row['version']);$this->assertNull($row['completed_at']);$this->assertCount(1,$this->tasks->details($this->actor(),$id)['history']);
    }
    public function testMembersSeeOnlyAssignedTasksAndCannotAdminister():void
    {
        $this->member(3);$hidden=$this->create();$id=$this->create(['assignee_id'=>'3']);$actor=$this->actor(3);
        $this->denied(fn()=>$this->tasks->find($actor,$hidden));$this->assertSame(1,$this->tasks->listing($actor,[])['total']);
        $this->tasks->save($actor,['title'=>'Assigned edit','version'=>'1'],$id);$this->assertSame('Assigned edit',$this->tasks->find($actor,$id)['title']);
        $this->denied(fn()=>$this->tasks->assign($actor,$id,'2',$this->version($id)));$this->denied(fn()=>$this->tasks->archive($actor,$id,true,$this->version($id)));
        $this->denied(fn()=>$this->tasks->save($actor,['project_id'=>(string)$this->project,'title'=>'Unauthorized creation']));
        $this->assertNull($this->projects->details($actor,$this->project)['linkedClient']);
    }
    public function testManagerAndGlobalPermissionsAreIndependent():void
    {
        $this->member(3,'manager');$id=$this->create();$this->tasks->save($this->actor(3),['title'=>'Manager update','version'=>'1'],$id);
        $reader=$this->actor(3,['projects.view','tasks.view']);$this->assertNotNull($this->tasks->find($reader,$id));$this->denied(fn()=>$this->tasks->save($reader,['title'=>'Forbidden','version'=>'2'],$id));
        $this->denied(fn()=>$this->tasks->find($this->actor(4,['projects.view','tasks.view','tasks.manageall']),$id));
    }
    public function testForeignProjectsAndForgedProjectChangesAreRejected():void
    {
        $id=$this->create();$this->denied(fn()=>$this->tasks->find($this->actor(4),$id));$this->denied(fn()=>$this->tasks->listing($this->actor(4),['project'=>(string)$this->project]));
        $this->invalid(fn()=>$this->tasks->save($this->actor(),['project_id'=>'999','version'=>'1'],$id));$this->denied(fn()=>$this->create(['project_id'=>'999']));
    }
    public function testAssignmentRequiresLiveEligibleMembership():void
    {
        $this->invalid(fn()=>$this->create(['assignee_id'=>'3']));$this->member(3);$id=$this->create(['assignee_id'=>'3']);
        $this->connection->table('project_members')->where(['project_id'=>$this->project,'user_id'=>3])->delete();$this->denied(fn()=>$this->tasks->find($this->actor(3),$id));
        $this->assertFalse($this->tasks->details($this->actor(),$id)['assigneeEligible']);$this->tasks->save($this->actor(),['title'=>'Retain historical assignee','version'=>'1'],$id);
        $this->assertSame(3,(int)$this->tasks->find($this->actor(),$id)['assignee_id']);$this->invalid(fn()=>$this->create(['assignee_id'=>'1']));
    }
    public function testInactiveAndPermissionRevokedAssigneesAreRejected():void
    {
        $this->member(3);$this->connection->table('users')->where('id',3)->update(['active'=>0]);$this->invalid(fn()=>$this->create(['assignee_id'=>'3']));
        $this->connection->table('users')->where('id',3)->update(['active'=>1]);$this->revoked[]=3;$this->invalid(fn()=>$this->create(['assignee_id'=>'3']));
    }
    public function testInvalidDatesReferencesAndNestedInputs():void
    {
        foreach([['title'=>''],['title'=>['bad']],['description'=>['bad']],['due_date'=>['bad']],['status_id'=>'999'],['priority_id'=>'999'],['assignee_id'=>['2']],['start_date'=>'2026-02-30'],['start_date'=>'0000-01-01'],['start_date'=>'2026-1-01'],['start_date'=>'2026-12-01','due_date'=>'2026-11-01'],['description'=>str_repeat('x',10001)]] as $input){$this->invalid(fn()=>$this->create($input));}
        $this->connection->table('task_priorities')->where('id',1)->update(['active'=>0]);$this->invalid(fn()=>$this->create(['priority_id'=>'1']));
    }
    public function testCompletionAndReopeningAreServerDerived():void
    {
        $id=$this->create();$this->tasks->status($this->actor(),$id,'4','1');$done=$this->tasks->find($this->actor(),$id);$this->assertNotNull($done['completed_at']);
        $this->tasks->save($this->actor(),['description'=>'Keep completion','version'=>'2'],$id);$this->assertSame($done['completed_at'],$this->tasks->find($this->actor(),$id)['completed_at']);
        $this->tasks->status($this->actor(),$id,'1','3');$this->assertNull($this->tasks->find($this->actor(),$id)['completed_at']);
    }
    public function testStaleWritesNeverChangeHistory():void
    {
        $id=$this->create();$this->tasks->status($this->actor(),$id,'2','1');
        $this->invalid(fn()=>$this->tasks->status($this->actor(),$id,'3','1'),409);$this->invalid(fn()=>$this->tasks->assign($this->actor(),$id,'2','1'),409);
        $this->invalid(fn()=>$this->tasks->save($this->actor(),['title'=>'Stale','version'=>'1'],$id),409);$this->invalid(fn()=>$this->tasks->archive($this->actor(),$id,true,'1'),409);
        $this->assertCount(2,$this->tasks->details($this->actor(),$id)['history']);$this->assertSame('Fictional task',$this->tasks->find($this->actor(),$id)['title']);
    }
    public function testTaskArchiveAndParentArchivePreserveState():void
    {
        $this->member(3);$id=$this->create(['assignee_id'=>'3']);$this->tasks->archive($this->actor(),$id,true,'1');$this->denied(fn()=>$this->tasks->find($this->actor(3),$id));
        $this->invalid(fn()=>$this->tasks->status($this->actor(),$id,'4','2'));$this->assertSame(1,$this->tasks->listing($this->actor(),['archive'=>'archived'])['total']);
        $pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->archive($this->actor(),$this->project,true,$pv);
        $this->invalid(fn()=>$this->tasks->archive($this->actor(),$id,false,'2'));$this->invalid(fn()=>$this->create());
        $pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->archive($this->actor(),$this->project,false,$pv);
        $this->assertNotNull($this->tasks->find($this->actor(),$id)['archived_at']);$this->tasks->archive($this->actor(),$id,false,'2');$this->assertNotNull($this->tasks->find($this->actor(3),$id));
    }
    public function testTerminalProjectsBlockTaskChanges():void
    {
        $id=$this->create();$pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->save($this->actor(),['name'=>'Fictional project','status_id'=>'4','version'=>$pv],$this->project);
        $this->invalid(fn()=>$this->tasks->save($this->actor(),['title'=>'Closed','version'=>'1'],$id));$this->invalid(fn()=>$this->create());$this->assertSame('1',$this->version($id));
    }
    public function testStatusOnlyActorCannotEditOrAssign():void
    {
        $this->member(3);$id=$this->create(['assignee_id'=>'3']);$actor=$this->actor(3,['projects.view','tasks.view','tasks.status']);$this->tasks->status($actor,$id,'2','1');
        $this->denied(fn()=>$this->tasks->save($actor,['title'=>'No edit','version'=>'2'],$id));$this->denied(fn()=>$this->tasks->assign($actor,$id,'2','2'));
    }
    public function testSearchFiltersAndBoundedPagination():void
    {
        for($i=0;$i<22;$i++){$this->create(['title'=>'Sample '.str_pad((string)$i,2,'0',STR_PAD_LEFT),'priority_id'=>$i%2?'1':'2','assignee_id'=>'2']);}
        $result=$this->tasks->listing($this->actor(),['q'=>'Sample','project'=>(string)$this->project,'assignee'=>'2']);$this->assertSame(22,$result['total']);$this->assertCount(20,$result['rows']);
        $this->assertCount(2,$this->tasks->listing($this->actor(),['page'=>'2'])['rows']);$this->assertSame(11,$this->tasks->listing($this->actor(),['priority'=>'1'])['total']);$this->assertSame(0,$this->tasks->listing($this->actor(4),[])['total']);
    }
    public function testHistoryFailureRollsBackTaskAndOuterTransactionOwnsCommit():void
    {
        $this->connection->query("CREATE TRIGGER reject_task_history BEFORE INSERT ON task_history BEGIN SELECT RAISE(ABORT,'Fictional failure'); END");
        try{$this->create();$this->fail('History failure accepted');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame(0,$this->connection->table('tasks')->countAllResults());
        $this->connection->query('DROP TRIGGER reject_task_history');$this->connection->resetTransStatus();$this->connection->transBegin();$this->create();$this->assertSame(1,$this->connection->transDepth);$this->connection->transRollback();$this->assertSame(0,$this->connection->table('tasks')->countAllResults());
    }
    public function testSeederAndDisposableMigrationIntegrity():void
    {
        $this->connection->table('task_priorities')->where('id',1)->update(['name'=>'Custom low']);(new TaskReferenceSeeder(config('Database'),$this->connection))->run();$this->assertSame('Custom low',$this->connection->table('task_priorities')->where('id',1)->get()->getRow('name'));$this->assertCount(5,$this->tasks->lookups()['statuses']);$this->assertCount(4,$this->tasks->lookups()['priorities']);
        $forge=Database::forge($this->connection);(new CreateTaskDependencies($forge))->down();(new CreateTaskCollaboration($forge))->down();(new CreateTaskManagement($forge))->down();$this->assertFalse($this->connection->tableExists('tasks'));$this->assertNotNull($this->projects->find($this->actor(),$this->project));(new CreateTaskManagement($forge))->up();(new CreateTaskCollaboration($forge))->up();(new CreateTaskDependencies($forge))->up();(new TaskReferenceSeeder(config('Database'),$this->connection))->run();$this->assertGreaterThan(0,$this->create());
    }
    public function testForeignKeysAndHistoryVersionUniqueness():void
    {
        $id=$this->create();$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('tasks')->where('id',$id)->update(['project_id'=>999999]);
    }
    public function testFailedHistoryUpdateRollsBackAndDoesNotBumpProject():void
    {
        $id=$this->create();$projectVersion=$this->projects->find($this->actor(),$this->project)['version'];
        $this->connection->query("CREATE TRIGGER reject_task_update BEFORE INSERT ON task_history WHEN NEW.version>1 BEGIN SELECT RAISE(ABORT,'Fictional update failure'); END");
        try{$this->tasks->save($this->actor(),['title'=>'Must roll back','version'=>'1'],$id);$this->fail('History failure accepted');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
        $this->assertSame('Fictional task',$this->tasks->find($this->actor(),$id)['title']);$this->assertSame('1',$this->version($id));$this->assertCount(1,$this->tasks->details($this->actor(),$id)['history']);$this->assertSame($projectVersion,$this->projects->find($this->actor(),$this->project)['version']);
    }
    public function testCreatorVisibilityDoesNotGrantEditingAfterProjectTransfer():void
    {
        $id=$this->create();$this->member(3,'manager');
        $project=$this->projects->find($this->actor(),$this->project);$this->projects->save($this->actor(1),['name'=>$project['name'],'status_id'=>'1','owner_id'=>'3','version'=>(string)$project['version']],$this->project);
        $this->projects->addMember($this->actor(1),$this->project,['user_id'=>'2','role'=>'member','version'=>(string)$this->projects->find($this->actor(1),$this->project)['version']]);
        $this->assertNotNull($this->tasks->find($this->actor(),$id));$this->denied(fn()=>$this->tasks->save($this->actor(),['title'=>'Creator is not assignee','version'=>'1'],$id));
        $this->connection->table('project_members')->where(['project_id'=>$this->project,'user_id'=>2])->delete();$this->denied(fn()=>$this->tasks->find($this->actor(),$id));
    }
    public function testUniqueTaskCodesCannotBeDuplicated():void
    {
        $first=$this->create();$second=$this->create();$code=$this->tasks->find($this->actor(),$first)['code'];$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('tasks')->where('id',$second)->update(['code'=>$code]);
    }    public function testDuplicateTitlesAreAllowedButHistoryVersionsAreUnique():void
    {
        $first=$this->create();$second=$this->create();$this->assertNotSame($this->tasks->find($this->actor(),$first)['code'],$this->tasks->find($this->actor(),$second)['code']);
        $event=$this->connection->table('task_history')->where('task_id',$first)->get()->getRowArray();unset($event['id']);$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('task_history')->insert($event);
    }
    public function testExistingInactiveReferencesCanBeRetainedButNotNewlySelected():void
    {
        $id=$this->create();$row=$this->tasks->find($this->actor(),$id);$this->connection->table('task_priorities')->where('id',$row['priority_id'])->update(['active'=>0]);
        $this->tasks->save($this->actor(),['description'=>'Retain inactive reference','version'=>'1'],$id);$this->assertSame((int)$row['priority_id'],(int)$this->tasks->find($this->actor(),$id)['priority_id']);
        $this->invalid(fn()=>$this->create(['priority_id'=>(string)$row['priority_id']]));
    }
    public function testBoardUsesConfiguredStatusesAndBoundedIndependentPagination():void
    {
        for($i=0;$i<22;$i++){$this->create(['title'=>'Identical fictional title']);}
        $this->create(['title'=>'Other stage','status_id'=>'2']);$board=$this->tasks->board($this->actor(),[]);
        $this->assertCount(5,$board['columns']);$this->assertSame(22,$board['columns'][0]['listing']['total']);$this->assertCount(20,$board['columns'][0]['listing']['rows']);
        $codes=array_column($board['columns'][0]['listing']['rows'],'code');$sorted=$codes;sort($sorted);$this->assertSame($sorted,$codes);
        $next=$this->tasks->board($this->actor(),['columns'=>['1'=>'2','2'=>'1']]);$this->assertCount(2,$next['columns'][0]['listing']['rows']);$this->assertSame(1,$next['columns'][1]['listing']['total']);
        $this->assertSame(array_column($this->tasks->listing($this->actor(),['status'=>'1','page'=>'2'])['rows'],'id'),array_column($next['columns'][0]['listing']['rows'],'id'));
    }
    public function testBoardCombinesAllFiltersAndRejectsForeignProjectProbes():void
    {
        $this->member(3);$id=$this->create(['title'=>'Find fictional needle','assignee_id'=>'3','priority_id'=>'3','status_id'=>'2']);$this->create();
        $board=$this->tasks->board($this->actor(),['q'=>'needle','project'=>(string)$this->project,'assignee'=>'3','priority'=>'3','status'=>'2']);$this->assertCount(1,$board['columns']);$this->assertSame($id,(int)$board['columns'][0]['listing']['rows'][0]['id']);
        $this->assertSame(1,$board['columns'][0]['listing']['total']);$this->denied(fn()=>$this->tasks->board($this->actor(4),['project'=>(string)$this->project]));
        $this->assertSame([],$this->tasks->board($this->actor(),['status'=>'99999'])['columns']);
    }
    public function testBoardCountsAndControlsRespectLiveMembershipAndCapabilities():void
    {
        $this->member(3);$id=$this->create(['assignee_id'=>'3']);$this->create();$board=$this->tasks->board($this->actor(3),[]);$this->assertSame(1,$board['columns'][0]['listing']['total']);$this->assertTrue($board['columns'][0]['listing']['rows'][0]['can_move']);
        $reader=$this->actor(3,['projects.view','tasks.view','tasks.edit']);$this->assertFalse($this->tasks->board($reader,[])['columns'][0]['listing']['rows'][0]['can_move']);$this->denied(fn()=>$this->tasks->status($reader,$id,'2','1'));
        $this->connection->table('project_members')->where(['project_id'=>$this->project,'user_id'=>3])->delete();foreach($this->tasks->board($this->actor(3),[])['columns'] as $column){$this->assertSame(0,$column['listing']['total']);}
        $this->denied(fn()=>$this->tasks->board($this->actor(3,['tasks.view']),[]));
    }
    public function testBoardArchivedTasksAndArchivedOrCompletedProjectsAreReadOnly():void
    {
        $id=$this->create();$this->tasks->archive($this->actor(),$id,true,'1');$archived=$this->tasks->board($this->actor(),['archive'=>'archived']);$this->assertFalse($archived['columns'][0]['listing']['rows'][0]['can_move']);
        $this->tasks->archive($this->actor(),$id,false,'2');$pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->archive($this->actor(),$this->project,true,$pv);
        $this->assertFalse($this->tasks->board($this->actor(),[])['columns'][0]['listing']['rows'][0]['can_move']);$pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->archive($this->actor(),$this->project,false,$pv);
        $pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->save($this->actor(),['name'=>'Fictional project','status_id'=>'4','version'=>$pv],$this->project);$this->assertFalse($this->tasks->board($this->actor(),[])['columns'][0]['listing']['rows'][0]['can_move']);
    }
    public function testInactiveBoardColumnsPreserveTasksWithoutAcceptingNewTransitions():void
    {
        $id=$this->create();$other=$this->create(['status_id'=>'2']);$this->connection->table('task_statuses')->whereIn('id',[1,3])->update(['active'=>0]);$board=$this->tasks->board($this->actor(),[]);$this->assertCount(4,$board['columns']);
        $this->assertSame('todo',$board['columns'][0]['status']['slug']);$this->assertSame(1,$board['columns'][0]['listing']['total']);$this->assertTrue($board['columns'][0]['listing']['rows'][0]['can_move']);$this->invalid(fn()=>$this->tasks->status($this->actor(),$other,'1','1'));
        $this->tasks->status($this->actor(),$id,'2','1');$this->assertCount(3,$this->tasks->board($this->actor(),[])['columns']);$this->assertCount(1,$this->tasks->board($this->actor(),['status'=>'3'])['columns']);
    }
    public function testBoardHandlesMalformedPagesWithoutMutatingDataOrExposingClientFields():void
    {
        $id=$this->create();$before=$this->tasks->details($this->actor(),$id)['history'];foreach([['columns'=>'bad'],['columns'=>['1'=>['bad']]],['columns'=>['1'=>'-999']],['q'=>['bad'],'status'=>['bad']]] as $input){$board=$this->tasks->board($this->actor(),$input);$this->assertSame(1,$board['columns'][0]['listing']['page']);}
        $row=$this->tasks->board($this->actor(),[])['columns'][0]['listing']['rows'][0];$this->assertArrayNotHasKey('client_id',$row);$this->assertArrayNotHasKey('email',$row);$this->assertSame($before,$this->tasks->details($this->actor(),$id)['history']);
    }
    public function testBoardAndListReflectCommittedStatusAndRejectStaleMoves():void
    {
        $id=$this->create();$this->tasks->status($this->actor(),$id,'4','1');$this->invalid(fn()=>$this->tasks->status($this->actor(),$id,'2','1'),409);
        $board=$this->tasks->board($this->actor(),['status'=>'4']);$this->assertSame($id,(int)$board['columns'][0]['listing']['rows'][0]['id']);$this->assertNotNull($board['columns'][0]['listing']['rows'][0]['completed_at']);$this->assertSame(1,$this->tasks->listing($this->actor(),['status'=>'4'])['total']);$this->assertCount(2,$this->tasks->details($this->actor(),$id)['history']);
    }
    public function testBoardDoesNotOfferMovementWithoutAnAlternativeActiveDestination():void
    {
        $this->create();$this->connection->table('task_statuses')->update(['active'=>0]);$board=$this->tasks->board($this->actor(),[]);$this->assertCount(1,$board['columns']);$this->assertFalse($board['columns'][0]['listing']['rows'][0]['can_move']);
        $this->connection->table('task_statuses')->where('id',1)->update(['active'=>1]);$this->assertFalse($this->tasks->board($this->actor(),[])['columns'][0]['listing']['rows'][0]['can_move']);
        $this->connection->table('task_statuses')->where('id',2)->update(['active'=>1]);$this->assertTrue($this->tasks->board($this->actor(),[])['columns'][0]['listing']['rows'][0]['can_move']);
    }
    public function testSelectedAssigneeFilterRemainsVisibleWithoutLeakingForeignUsers():void
    {
        $this->member(3);$this->create(['assignee_id'=>'3']);$choices=$this->tasks->assigneeFilters($this->actor(),'No matching username',3);$this->assertCount(1,$choices);$this->assertSame(3,(int)$choices[0]['id']);
        $this->assertSame([],$this->tasks->assigneeFilters($this->actor(),'No matching username',4));
    }
}
