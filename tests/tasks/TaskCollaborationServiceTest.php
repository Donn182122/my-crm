<?php

declare(strict_types=1);
use App\Services\{TaskCollaborationService,TaskService,TaskInputException,ProjectService,ClientService};
use App\Database\Migrations\{CreateClientManagement,CreateProjectManagement,CreateTaskManagement,CreateTaskCollaboration};
use App\Database\Seeds\{ProjectReferenceSeeder,TaskReferenceSeeder};
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
final class TaskCollaborationServiceTest extends CIUnitTestCase
{
    private $connection;private TaskService $tasks;private TaskCollaborationService $collaboration;private ProjectService $projects;private int $project;private array $revoked=[];
    private function actor(int $id=2,?array $permissions=null):User
    {
        $actor=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();$actor->id=$id;
        $permissions??=['projects.view','projects.create','projects.edit','projects.archive','projects.members','clients.view','clients.create','tasks.checklist','tasks.comment','tasks.view','tasks.create','tasks.edit','tasks.status','tasks.assign','tasks.archive'];
        $actor->method('can')->willReturnCallback(fn($p)=>!in_array($id,$this->revoked,true)&&($id===1||in_array($p,$permissions,true)));return $actor;
    }
    protected function setUp():void
    {
        parent::setUp();foreach(['2026-11-10-090000_CreateClientManagement','2026-11-11-090000_CreateProjectManagement','2026-11-11-100000_CreateTaskManagement','2026-11-11-110000_CreateTaskCollaboration'] as $file){require_once APPPATH.'Database/Migrations/'.$file.'.php';}
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users(id INTEGER PRIMARY KEY,username VARCHAR(30),active INTEGER)');foreach(range(1,6) as $id){$this->connection->table('users')->insert(['id'=>$id,'username'=>'Fictional'.$id,'active'=>1]);}
        $forge=Database::forge($this->connection);(new CreateClientManagement($forge))->up();(new CreateProjectManagement($forge))->up();(new CreateTaskManagement($forge))->up();(new CreateTaskCollaboration($forge))->up();
        (new ProjectReferenceSeeder(config('Database'),$this->connection))->run();(new TaskReferenceSeeder(config('Database'),$this->connection))->run();
        $resolver=fn($id)=>$this->actor($id);$this->projects=new ProjectService($this->connection,$resolver);$this->tasks=new TaskService($this->connection,$resolver);$this->collaboration=new TaskCollaborationService($this->connection,$resolver);
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
    public function testSubtasksReuseTaskWorkflowWithoutCompletingParent():void
    {
        $root=$this->create();$child=$this->create(['parent_id'=>(string)$root,'title'=>'Child','due_date'=>'2026-12-01']);$this->assertSame($root,(int)$this->tasks->find($this->actor(),$child)['parent_id']);$this->tasks->status($this->actor(),$child,'4','1');$this->assertNotNull($this->tasks->find($this->actor(),$child)['completed_at']);$this->assertNull($this->tasks->find($this->actor(),$root)['completed_at']);$this->assertSame('1',$this->version($root));$this->assertSame(1,$this->tasks->children($this->actor(),$root)['total']);
    }
    public function testParentCannotBeChangedOrNestedAndMustShareProject():void
    {
        $root=$this->create();$child=$this->create(['parent_id'=>(string)$root]);$this->invalid(fn()=>$this->create(['parent_id'=>(string)$child]));$this->invalid(fn()=>$this->tasks->save($this->actor(),['parent_id'=>(string)$root,'version'=>'1'],$root));$this->invalid(fn()=>$this->tasks->save($this->actor(),['parent_id'=>(string)$child,'version'=>'1'],$child));$this->denied(fn()=>$this->create(['parent_id'=>'999999']));
        $project=$this->projects->find($this->actor(),$this->project);$other=$this->projects->save($this->actor(),['name'=>'Other project','client_id'=>(string)$project['client_id'],'status_id'=>'1']);$this->invalid(fn()=>$this->create(['project_id'=>(string)$other,'parent_id'=>(string)$root]));
    }
    public function testChildAccessDoesNotExposeParentOrOtherChildren():void
    {
        $this->member(3);$this->member(4);$root=$this->create();$child=$this->create(['parent_id'=>(string)$root,'assignee_id'=>'3']);$details=$this->collaboration->details($this->actor(3),$child);$this->assertNull($details['parent']);$this->denied(fn()=>$this->tasks->find($this->actor(3),$root));$this->tasks->status($this->actor(3),$child,'2','1');
        $visibleRoot=$this->create(['assignee_id'=>'3']);$this->create(['parent_id'=>(string)$visibleRoot,'assignee_id'=>'4']);$this->assertSame(0,$this->tasks->children($this->actor(3),$visibleRoot)['total']);
        $this->denied(fn()=>$this->tasks->save($this->actor(3),['project_id'=>(string)$this->project,'parent_id'=>(string)$visibleRoot,'title'=>'Unauthorized child']));
    }
    public function testParentArchiveSuspendsChildMutationsWithoutCascadingFlags():void
    {
        $this->member(3);$root=$this->create();$child=$this->create(['parent_id'=>(string)$root,'assignee_id'=>'3']);$this->tasks->archive($this->actor(),$root,true,'1');$this->denied(fn()=>$this->tasks->find($this->actor(3),$child));$this->assertNull($this->tasks->find($this->actor(),$child)['archived_at']);
        $this->invalid(fn()=>$this->tasks->status($this->actor(),$child,'4','1'));$this->invalid(fn()=>$this->tasks->archive($this->actor(),$child,true,'1'));$this->invalid(fn()=>$this->collaboration->comment($this->actor(),$child,['body'=>'Blocked','version'=>'1']));$this->assertFalse($this->collaboration->details($this->actor(),$child)['canComment']);
        $this->tasks->archive($this->actor(),$root,false,'2');$this->assertSame('1',$this->version($child));$this->tasks->status($this->actor(3),$child,'4','1');$this->assertNotNull($this->tasks->find($this->actor(3),$child)['completed_at']);
    }
    public function testDoneParentDoesNotAutomaticallyBlockChildrenOrCompleteChecklists():void
    {
        $root=$this->create();$child=$this->create(['parent_id'=>(string)$root]);$this->tasks->status($this->actor(),$root,'4','1');$this->tasks->save($this->actor(),['title'=>'Still editable','version'=>'1'],$child);$this->collaboration->checklist($this->actor(),$child,['label'=>'Incomplete','version'=>'2']);$this->assertNull($this->tasks->find($this->actor(),$child)['completed_at']);$this->assertSame(0,$this->collaboration->details($this->actor(),$child)['checked']);
    }
    public function testChecklistCRUDProgressAndAuditAreAtomic():void
    {
        $id=$this->create();$this->collaboration->checklist($this->actor(),$id,['label'=>'First','created_by'=>'1','version'=>'1']);$item=$this->item($id,'First');$row=$this->connection->table('task_checklist_items')->where('id',$item)->get()->getRowArray();$this->assertSame(2,(int)$row['created_by']);
        $this->collaboration->checklist($this->actor(),$id,['label'=>'Updated','version'=>'2'],$item);$this->collaboration->checklist($this->actor(),$id,['completed'=>'1','version'=>'3'],$item,'toggle');$this->assertSame(1,$this->collaboration->details($this->actor(),$id)['checked']);
        $this->collaboration->checklist($this->actor(),$id,['completed'=>'0','version'=>'4'],$item,'toggle');$this->collaboration->checklist($this->actor(),$id,['version'=>'5'],$item,'archive');$this->assertSame([],$this->collaboration->details($this->actor(),$id)['items']);$this->assertSame('6',$this->version($id));$this->assertCount(6,$this->tasks->details($this->actor(),$id)['history']);
        $this->assertNotNull($this->connection->table('task_checklist_items')->where('id',$item)->get()->getRow('deleted_at'));$this->assertNull($this->tasks->find($this->actor(),$id)['completed_at']);
    }
    public function testPersistentChecklistOrderingRejectsStaleReorders():void
    {
        $id=$this->create();foreach(['A','B','C'] as $label){$this->collaboration->checklist($this->actor(),$id,['label'=>$label,'version'=>$this->version($id)]);}$c=$this->item($id,'C');$old=$this->version($id);$this->collaboration->checklist($this->actor(),$id,['direction'=>'up','version'=>$old],$c,'move');$this->invalid(fn()=>$this->collaboration->checklist($this->actor(),$id,['direction'=>'up','version'=>$old],$c,'move'),409);$this->assertSame(['A','C','B'],array_column($this->collaboration->details($this->actor(),$id)['items'],'label'));
        $this->invalid(fn()=>$this->collaboration->checklist($this->actor(),$id,['direction'=>'up','version'=>$this->version($id)],$this->item($id,'A'),'move'));$this->assertCount(5,$this->tasks->details($this->actor(),$id)['history']);
    }
    public function testRelatedIdsCannotBeUsedAcrossTasksOrByForeignUsers():void
    {
        $first=$this->create();$second=$this->create();$this->collaboration->checklist($this->actor(),$first,['label'=>'Private','version'=>'1']);$item=$this->item($first,'Private');$comment=$this->addComment($first);
        $this->denied(fn()=>$this->collaboration->checklist($this->actor(),$second,['label'=>'Foreign','version'=>'1'],$item));$this->denied(fn()=>$this->collaboration->comment($this->actor(),$second,['body'=>'Foreign','version'=>'1'],$comment));
        $this->denied(fn()=>$this->collaboration->details($this->actor(4),$first));$this->denied(fn()=>$this->collaboration->comment($this->actor(4),$first,['body'=>'Foreign','version'=>$this->version($first)]));
    }
    public function testCommentsPreserveAuthorshipAndSupportOwnEditAndHide():void
    {
        $id=$this->create();$this->collaboration->comment($this->actor(),$id,['body'=>'<script>Fictional XSS</script>','author_id'=>'1','version'=>'1']);$comment=(int)$this->connection->table('task_comments')->where('task_id',$id)->get()->getRow('id');$this->assertSame(2,(int)$this->connection->table('task_comments')->where('id',$comment)->get()->getRow('author_id'));
        $this->collaboration->comment($this->actor(),$id,['body'=>'Edited plain text','version'=>'2'],$comment);$row=$this->collaboration->details($this->actor(),$id)['comments'][0];$this->assertSame('Edited plain text',$row['body']);$this->assertNotNull($row['edited_at']);$this->assertTrue($row['can_edit']);
        $this->collaboration->comment($this->actor(),$id,['version'=>'3'],$comment,true);$this->assertSame([],$this->collaboration->details($this->actor(),$id)['comments']);$this->denied(fn()=>$this->collaboration->comment($this->actor(),$id,['body'=>'Resurrect','version'=>'4'],$comment));$this->assertStringNotContainsString('Edited plain text',json_encode($this->tasks->details($this->actor(),$id)['history']));
    }
    public function testCommentAuthorAndModeratorPermissionsAreSeparate():void
    {
        $this->member(3);$id=$this->create(['assignee_id'=>'3']);$ownerComment=$this->addComment($id);$this->denied(fn()=>$this->collaboration->comment($this->actor(3),$id,['body'=>'Edit another','version'=>$this->version($id)],$ownerComment));$this->denied(fn()=>$this->collaboration->comment($this->actor(3),$id,['version'=>$this->version($id)],$ownerComment,true));
        $memberComment=$this->addComment($id,'Member comment',$this->actor(3));$this->denied(fn()=>$this->collaboration->comment($this->actor(),$id,['version'=>$this->version($id)],$memberComment,true));$this->denied(fn()=>$this->collaboration->comment($this->actor(1),$id,['body'=>'Admin cannot rewrite another author','version'=>$this->version($id)],$memberComment));
        $this->collaboration->comment($this->actor(1),$id,['version'=>$this->version($id)],$memberComment,true);$this->assertSame(1,(int)$this->connection->table('task_comments')->where('id',$memberComment)->get()->getRow('deleted_by'));
    }
    public function testReadOnlyGrantsAndRevokedMembershipRejectCollaboration():void
    {
        $this->member(3);$id=$this->create(['assignee_id'=>'3']);$reader=$this->actor(3,['projects.view','tasks.view']);$this->assertFalse($this->collaboration->details($reader,$id)['canComment']);$this->denied(fn()=>$this->collaboration->comment($reader,$id,['body'=>'No permission','version'=>'1']));$this->denied(fn()=>$this->collaboration->checklist($reader,$id,['label'=>'No permission','version'=>'1']));
        $this->connection->table('project_members')->where(['project_id'=>$this->project,'user_id'=>3])->delete();$this->denied(fn()=>$this->collaboration->details($this->actor(3),$id));$this->denied(fn()=>$this->collaboration->comment($this->actor(3),$id,['body'=>'Revoked','version'=>'1']));
    }
    public function testStaleTaskAndCollaborationEditsCannotOverwriteEachOther():void
    {
        $id=$this->create();$comment=$this->addComment($id);$this->tasks->status($this->actor(),$id,'2','2');$this->invalid(fn()=>$this->collaboration->comment($this->actor(),$id,['body'=>'Stale text','version'=>'2'],$comment),409);$this->invalid(fn()=>$this->tasks->save($this->actor(),['title'=>'Stale task','version'=>'1'],$id),409);$this->assertSame('Fictional comment',$this->collaboration->details($this->actor(),$id)['comments'][0]['body']);$this->assertCount(3,$this->tasks->details($this->actor(),$id)['history']);
    }
    public function testArchivedTasksAndProjectsRejectAllCollaborationWrites():void
    {
        $id=$this->create();$comment=$this->addComment($id);$this->tasks->archive($this->actor(),$id,true,$this->version($id));$this->invalid(fn()=>$this->collaboration->comment($this->actor(),$id,['body'=>'Archived','version'=>$this->version($id)],$comment));$this->invalid(fn()=>$this->collaboration->checklist($this->actor(),$id,['label'=>'Archived','version'=>$this->version($id)]));$this->assertFalse($this->collaboration->details($this->actor(),$id)['canComment']);
        $this->tasks->archive($this->actor(),$id,false,$this->version($id));$pv=(string)$this->projects->find($this->actor(),$this->project)['version'];$this->projects->archive($this->actor(),$this->project,true,$pv);$this->invalid(fn()=>$this->collaboration->comment($this->actor(),$id,['body'=>'Project archived','version'=>$this->version($id)]));
    }
    public function testCommentsAreBoundedAndChronologicallyPaginated():void
    {
        $id=$this->create();for($i=0;$i<22;$i++){$this->addComment($id,'Comment '.str_pad((string)$i,2,'0',STR_PAD_LEFT));}$first=$this->collaboration->details($this->actor(),$id);$last=$this->collaboration->details($this->actor(),$id,['comments_page'=>'last']);$this->assertCount(20,$first['comments']);$this->assertSame('Comment 00',$first['comments'][0]['body']);$this->assertCount(2,$last['comments']);$this->assertSame(2,$last['commentsPage']);
    }
    public function testValidationAndArchivedRelatedRowsCannotBeBypassed():void
    {
        $id=$this->create();foreach(['',str_repeat('x',241),['nested']] as $label){$this->invalid(fn()=>$this->collaboration->checklist($this->actor(),$id,['label'=>$label,'version'=>'1']));}foreach(['',str_repeat('x',5001),['nested']] as $body){$this->invalid(fn()=>$this->collaboration->comment($this->actor(),$id,['body'=>$body,'version'=>'1']));}
        $this->collaboration->checklist($this->actor(),$id,['label'=>'Item','version'=>'1']);$item=$this->item($id,'Item');$this->invalid(fn()=>$this->collaboration->checklist($this->actor(),$id,['completed'=>['1'],'version'=>'2'],$item,'toggle'));$this->collaboration->checklist($this->actor(),$id,['version'=>'2'],$item,'archive');$this->denied(fn()=>$this->collaboration->checklist($this->actor(),$id,['label'=>'Restore invalidly','version'=>'3'],$item));
    }
    public function testHistoryFailureRollsBackRelatedRowsAndTaskVersion():void
    {
        $id=$this->create();$this->connection->query("CREATE TRIGGER reject_collaboration BEFORE INSERT ON task_history WHEN NEW.event='comment_added' BEGIN SELECT RAISE(ABORT,'Fictional collaboration failure'); END");
        try{$this->addComment($id);$this->fail('Failed history should abort comment');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame(0,$this->connection->table('task_comments')->countAllResults());$this->assertSame('1',$this->version($id));$this->assertCount(1,$this->tasks->details($this->actor(),$id)['history']);
    }
    public function testChecklistReorderFailureRollsBackBothPositions():void
    {
        $id=$this->create();foreach(['A','B'] as $label){$this->collaboration->checklist($this->actor(),$id,['label'=>$label,'version'=>$this->version($id)]);}$this->connection->query("CREATE TRIGGER reject_order BEFORE INSERT ON task_history WHEN NEW.event='checklist_reordered' BEGIN SELECT RAISE(ABORT,'Fictional order failure'); END");
        try{$this->collaboration->checklist($this->actor(),$id,['direction'=>'up','version'=>'3'],$this->item($id,'B'),'move');$this->fail('Failed history should abort ordering');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame(['A','B'],array_column($this->collaboration->details($this->actor(),$id)['items'],'label'));$this->assertSame('3',$this->version($id));
    }
    public function testOuterRollbackOwnsCommentAndChecklistTransactions():void
    {
        $id=$this->create();$this->connection->transBegin();$this->addComment($id);$this->collaboration->checklist($this->actor(),$id,['label'=>'Rollback','version'=>$this->version($id)]);$this->assertSame(1,$this->connection->transDepth);$this->connection->transRollback();$this->assertSame('1',$this->version($id));$this->assertSame(0,$this->connection->table('task_comments')->countAllResults());$this->assertSame(0,$this->connection->table('task_checklist_items')->countAllResults());
    }
    public function testAdditiveMigrationRollbackRetainsExistingTasksAndHistory():void
    {
        $id=$this->create();$before=$this->connection->table('tasks')->where('id',$id)->get()->getRowArray();$forge=Database::forge($this->connection);(new CreateTaskCollaboration($forge))->down();$after=$this->connection->table('tasks')->where('id',$id)->get()->getRowArray();unset($before['parent_id']);$this->assertSame($before,$after);$this->assertSame(1,$this->connection->table('task_history')->countAllResults());(new CreateTaskCollaboration($forge))->up();$this->assertNull($this->tasks->find($this->actor(),$id)['parent_id']);
    }
    public function testParentForeignKeyRejectsOrphans():void
    {
        $id=$this->create();$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('tasks')->where('id',$id)->update(['parent_id'=>999999]);
    }
    public function testChecklistCapacityIsEnforcedWithoutAdvancingTaskOrHistory():void
    {
        $id=$this->create();for($i=0;$i<100;$i++){$this->collaboration->checklist($this->actor(),$id,['label'=>'Item '.$i,'version'=>$this->version($id)]);}
        $this->invalid(fn()=>$this->collaboration->checklist($this->actor(),$id,['label'=>'Too many','version'=>'101']));$this->assertSame('101',$this->version($id));$this->assertCount(100,$this->collaboration->details($this->actor(),$id)['items']);$this->assertFalse($this->collaboration->details($this->actor(),$id)['checked']>0);
        $this->collaboration->checklist($this->actor(),$id,['version'=>'101'],$this->item($id,'Item 0'),'archive');$this->collaboration->checklist($this->actor(),$id,['label'=>'Replacement','version'=>'102']);$this->assertCount(100,$this->collaboration->details($this->actor(),$id)['items']);
    }
    public function testSubtasksUseBoundedIndependentArchivePagination():void
    {
        $root=$this->create();$ids=[];for($i=0;$i<22;$i++){$ids[]=$this->create(['parent_id'=>(string)$root,'title'=>'Child '.str_pad((string)$i,2,'0',STR_PAD_LEFT)]);}
        $first=$this->tasks->children($this->actor(),$root);$second=$this->tasks->children($this->actor(),$root,['subtasks_page'=>'2']);$this->assertCount(20,$first['rows']);$this->assertCount(2,$second['rows']);$this->assertSame(22,$first['total']);
        $this->tasks->archive($this->actor(),$ids[0],true,'1');$archived=$this->tasks->children($this->actor(),$root,['subtasks_archive'=>'archived']);$this->assertCount(1,$archived['rows']);$this->assertSame($ids[0],(int)$archived['rows'][0]['id']);$this->assertSame(21,$this->tasks->children($this->actor(),$root)['total']);
    }
    public function testFailedCommentEditRollsBackBodyVersionAndHistory():void
    {
        $id=$this->create();$comment=$this->addComment($id);$this->connection->query("CREATE TRIGGER reject_comment_edit BEFORE INSERT ON task_history WHEN NEW.event='comment_edited' BEGIN SELECT RAISE(ABORT,'Fictional edit failure'); END");
        try{$this->collaboration->comment($this->actor(),$id,['body'=>'Must roll back','version'=>'2'],$comment);$this->fail('History failure must roll back edit');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
        $row=$this->collaboration->details($this->actor(),$id)['comments'][0];$this->assertSame('Fictional comment',$row['body']);$this->assertSame(1,(int)$row['version']);$this->assertNull($row['edited_at']);$this->assertSame('2',$this->version($id));$this->assertCount(2,$this->tasks->details($this->actor(),$id)['history']);
    }
}
