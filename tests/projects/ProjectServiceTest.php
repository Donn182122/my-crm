<?php

declare(strict_types=1);
use App\Services\{ProjectService,ProjectPolicy,ProjectInputException,ClientService};
use App\Database\Migrations\{CreateClientManagement,CreateProjectManagement};
use App\Database\Seeds\ProjectReferenceSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
final class ProjectServiceTest extends CIUnitTestCase
{
    private $connection;private ProjectService $projects;private ClientService $clients;private int $client;private array $revoked=[];
    private function actor(int $id=2,?array $permissions=null):User
    {
        $user=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();$user->id=$id;
        $permissions??=['projects.view','projects.create','projects.edit','projects.archive','projects.members','clients.view','clients.create','clients.edit','clients.archive'];
        $user->method('can')->willReturnCallback(fn($p)=>!in_array($id,$this->revoked,true)&&($id===1||in_array($p,$permissions,true)));return $user;
    }
    protected function setUp():void
    {
        parent::setUp();foreach(['2026-11-10-090000_CreateClientManagement','2026-11-11-090000_CreateProjectManagement'] as $name){require_once APPPATH.'Database/Migrations/'.$name.'.php';}
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users(id INTEGER PRIMARY KEY,username VARCHAR(30),active INTEGER)');foreach([1,2,3,4,5] as $id){$this->connection->table('users')->insert(['id'=>$id,'username'=>'Fictional'.$id,'active'=>1]);}
        $forge=Database::forge($this->connection);(new CreateClientManagement($forge))->up();(new CreateProjectManagement($forge))->up();(new ProjectReferenceSeeder(config('Database'),$this->connection))->run();
        $this->clients=new ClientService($this->connection);$this->projects=new ProjectService($this->connection,fn($id)=>$this->actor($id));$this->client=$this->clients->save($this->actor(),['type'=>'company','company_name'=>'Private Fictional Client','email'=>'private@example.invalid','status_id'=>'1']);
    }
    protected function tearDown():void{$this->connection->close();parent::tearDown();}
    private function data(array $extra=[]):array{return array_replace(['name'=>'Fictional Project','client_id'=>(string)$this->client,'status_id'=>'1','start_date'=>'2026-11-01','end_date'=>'2026-12-01'],$extra);}
    private function create(array $extra=[]):int{return $this->projects->save($this->actor(),$this->data($extra));}
    private function version(int $id):string{return (string)$this->projects->find($this->actor(1),$id)['version'];}
    private function add(int $id,int $user,string $role='member'):void{$this->projects->addMember($this->actor(),$id,['version'=>$this->version($id),'user_id'=>(string)$user,'role'=>$role]);}
    private function invalid(callable $fn,int $status=422):void{try{$fn();$this->fail('Invalid operation accepted');}catch(ProjectInputException $e){$this->assertSame($status,$e->status);}}
    private function denied(callable $fn):void{try{$fn();$this->fail('Unauthorized operation accepted');}catch(PageNotFoundException){$this->addToAssertionCount(1);}}
    public function testCreationIgnoresForgedCreatorCodeAndVersion():void
    {
        $id=$this->create(['created_by'=>'1','code'=>'FORGED','version'=>'99']);$row=$this->projects->find($this->actor(),$id);
        $this->assertSame(2,(int)$row['owner_id']);$this->assertSame(2,(int)$row['created_by']);$this->assertSame(1,(int)$row['version']);$this->assertStringStartsWith('PR-',$row['code']);$this->assertCount(1,$this->projects->details($this->actor(),$id)['history']);
    }
    public function testClientAuthorizationAndArchiveAreIndependent():void
    {
        $this->denied(fn()=>$this->projects->save($this->actor(3),$this->data()));$this->clients->archive($this->actor(),$this->client,true);$this->invalid(fn()=>$this->create());
        $this->denied(fn()=>$this->create(['client_id'=>'9999']));$this->invalid(fn()=>$this->create(['client_id'=>['1']]));
    }
    public function testDatesNamesStatusAndNestedInputsAreValidated():void
    {
        foreach([['name'=>''],['name'=>['X']],['status_id'=>'999'],['start_date'=>'2026-02-30'],['start_date'=>'0000-01-01'],['start_date'=>'2026-1-01'],['end_date'=>'2026-01-01'],['description'=>str_repeat('x',10001)]] as $data){$this->invalid(fn()=>$this->create($data));}
        $this->connection->table('project_statuses')->where('id',1)->update(['active'=>0]);$this->invalid(fn()=>$this->create());
    }
    public function testMemberViewsWithoutClientAccessButCannotEdit():void
    {
        $id=$this->create();$this->add($id,3);$member=$this->actor(3);$details=$this->projects->details($member,$id);$this->assertNull($details['linkedClient']);$this->assertSame('Fictional Project',$details['project']['name']);
        $this->denied(fn()=>$this->clients->find($member,$this->client));$this->denied(fn()=>$this->projects->save($member,$this->data(['version'=>$this->version($id)]),$id));$this->denied(fn()=>$this->projects->archive($member,$id,true,$this->version($id)));
        $this->denied(fn()=>$this->projects->addMember($member,$id,['version'=>$this->version($id),'user_id'=>'4','role'=>'manager']));$this->denied(fn()=>$this->projects->listing($member,['client'=>(string)$this->client]));
    }
    public function testUnassignedStaffAndCreatorHaveNoImplicitAccess():void
    {
        $id=$this->create();$this->denied(fn()=>$this->projects->find($this->actor(3),$id));$this->assertSame(0,$this->projects->listing($this->actor(3),[])['total']);
        $this->projects->save($this->actor(1),$this->data(['owner_id'=>'4','version'=>$this->version($id)]),$id);$this->denied(fn()=>$this->projects->find($this->actor(),$id));$this->assertSame(4,(int)$this->projects->find($this->actor(4),$id)['owner_id']);
    }
    public function testManagerCanEditButCannotChangeOwnerClientOrPromote():void
    {
        $id=$this->create();$this->add($id,3,'manager');$manager=$this->actor(3);
        $this->projects->save($manager,['name'=>'Manager update','status_id'=>'2','version'=>$this->version($id)],$id);$this->assertSame('Manager update',$this->projects->find($manager,$id)['name']);
        $this->denied(fn()=>$this->projects->save($manager,$this->data(['owner_id'=>'3','version'=>$this->version($id)]),$id));
        $other=$this->clients->save($manager,['type'=>'company','company_name'=>'Other','status_id'=>'1']);$this->denied(fn()=>$this->projects->save($manager,$this->data(['client_id'=>(string)$other,'version'=>$this->version($id)]),$id));
        $this->denied(fn()=>$this->projects->addMember($manager,$id,['user_id'=>'4','role'=>'manager','version'=>$this->version($id)]));
        $this->projects->addMember($manager,$id,['user_id'=>'4','role'=>'member','version'=>$this->version($id)]);$this->assertSame(1,$this->projects->listing($this->actor(4),[])['total']);
        $this->denied(fn()=>$this->projects->removeMember($manager,$id,3,$this->version($id)));$this->projects->removeMember($manager,$id,4,$this->version($id));$this->denied(fn()=>$this->projects->find($this->actor(4),$id));
    }
    public function testMembershipRevocationTakesEffectOnAlreadyLoadedActor():void
    {
        $id=$this->create();$this->add($id,3,'manager');$cachedActor=$this->actor(3);$old=$this->version($id);$this->projects->removeMember($this->actor(),$id,3,$old);
        $this->denied(fn()=>$this->projects->find($cachedActor,$id));$this->denied(fn()=>$this->projects->save($cachedActor,['name'=>'Stale manager','status_id'=>'1','version'=>$old],$id));
    }
    public function testStaleEditsArchiveAndMembershipReturn409():void
    {
        $id=$this->create();$this->projects->save($this->actor(),$this->data(['version'=>'1','status_id'=>'2']),$id);
        $this->invalid(fn()=>$this->projects->save($this->actor(),$this->data(['version'=>'1']),$id),409);$this->invalid(fn()=>$this->projects->archive($this->actor(),$id,true,'1'),409);$this->invalid(fn()=>$this->projects->addMember($this->actor(),$id,['version'=>'1','user_id'=>'3','role'=>'member']),409);
        $this->assertCount(2,$this->projects->details($this->actor(),$id)['history']);
    }
    public function testArchiveSuspendsMembersAndRestoreRetainsRelationships():void
    {
        $id=$this->create();$this->add($id,3,'manager');$this->projects->archive($this->actor(),$id,true,$this->version($id));$this->denied(fn()=>$this->projects->find($this->actor(3),$id));
        $this->denied(fn()=>$this->projects->save($this->actor(),$this->data(['version'=>$this->version($id)]),$id));
    }
    public function testRestoreAndArchivedClientDoNotCascadeProjects():void
    {
        $id=$this->create();$this->add($id,3);$this->clients->archive($this->actor(),$this->client,true);$this->assertNull($this->projects->find($this->actor(3),$id)['archived_at']);
        $this->projects->save($this->actor(),$this->data(['version'=>$this->version($id)]),$id);$this->projects->archive($this->actor(),$id,true,$this->version($id));$this->projects->archive($this->actor(),$id,false,$this->version($id));$this->assertNotNull($this->projects->find($this->actor(3),$id));
    }
    public function testReadOnlyGlobalPermissionsOverrideProjectManagerRole():void
    {
        $id=$this->create();$this->add($id,3,'manager');$reader=$this->actor(3,['projects.view']);$this->assertNotNull($this->projects->find($reader,$id));$this->denied(fn()=>$this->projects->save($reader,['name'=>'No','status_id'=>'1','version'=>$this->version($id)],$id));
        $this->denied(fn()=>$this->projects->find($this->actor(3,[]),$id));$this->revoked[] = 3;$this->invalid(fn()=>$this->projects->addMember($this->actor(),$id,['version'=>$this->version($id),'user_id'=>'3','role'=>'member']));
    }
    public function testOwnerAssignmentRestrictedAndEligible():void
    {
        $this->denied(fn()=>$this->create(['owner_id'=>'3']));$this->connection->table('users')->where('id',4)->update(['active'=>0]);$this->invalid(fn()=>$this->projects->save($this->actor(1),$this->data(['owner_id'=>'4'])));
        $id=$this->projects->save($this->actor(1),$this->data(['owner_id'=>'3']));$this->assertSame(3,(int)$this->projects->find($this->actor(3),$id)['owner_id']);
    }
    public function testDuplicateMembershipRollsBackVersionAndHistory():void
    {
        $id=$this->create();$this->add($id,3);$version=$this->version($id);$this->invalid(fn()=>$this->add($id,3),409);$this->assertSame($version,$this->version($id));$this->assertCount(2,$this->projects->details($this->actor(),$id)['history']);
        $this->invalid(fn()=>$this->add($id,2));$this->denied(fn()=>$this->projects->removeMember($this->actor(),$id,4,$this->version($id)));
    }
    public function testSearchFiltersPaginationAndClientScope():void
    {
        for($i=0;$i<22;$i++){$this->create(['name'=>'Sample '.$i,'status_id'=>$i%2?'1':'2']);}$all=$this->projects->listing($this->actor(),['q'=>'Sample','client'=>(string)$this->client]);$this->assertSame(22,$all['total']);$this->assertCount(20,$all['rows']);$this->assertCount(2,$this->projects->listing($this->actor(),['page'=>'2'])['rows']);$this->assertSame(11,$this->projects->listing($this->actor(),['status'=>'2'])['total']);$this->assertSame(0,$this->projects->listing($this->actor(1),['owner'=>'3'])['total']);
    }
    public function testFailureInjectionRollsBackCreateMembershipAndHistory():void
    {
        $this->connection->query("CREATE TRIGGER reject_project_history BEFORE INSERT ON project_history BEGIN SELECT RAISE(ABORT,'Fictional project failure'); END");
        try{$this->create();$this->fail('History failure should abort creation');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame(0,$this->connection->table('projects')->countAllResults());$this->connection->query('DROP TRIGGER reject_project_history');
        $id=$this->create();$this->connection->query("CREATE TRIGGER reject_member_history BEFORE INSERT ON project_history WHEN NEW.event='member_added' BEGIN SELECT RAISE(ABORT,'Fictional member failure'); END");
        try{$this->add($id,3);$this->fail('Membership history failure should abort');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}$this->assertSame('1',$this->version($id));$this->assertCount(0,$this->projects->details($this->actor(),$id)['members']);$this->assertCount(1,$this->projects->details($this->actor(),$id)['history']);
    }
    public function testSeederPreservesCustomizationsAndForeignKeysAreEnforced():void
    {
        $this->connection->table('project_statuses')->where('id',1)->update(['name'=>'Custom planning']);(new ProjectReferenceSeeder(config('Database'),$this->connection))->run();$this->assertSame(5,$this->connection->table('project_statuses')->countAllResults());$this->assertSame('Custom planning',$this->connection->table('project_statuses')->where('id',1)->get()->getRow('name'));
        $id=$this->create();$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('projects')->where('id',$id)->update(['client_id'=>99999]);
    }
    public function testOuterRollbackOwnsProjectTransaction():void
    {
        $this->connection->transBegin();$this->create();$this->assertSame(1,$this->connection->transDepth);$this->connection->transRollback();$this->assertSame(0,$this->connection->table('projects')->countAllResults());
    }
    public function testOwnerRelinkingRequiresIndependentActiveClientAccess():void
    {
        $id=$this->create();$target=$this->clients->save($this->actor(),['type'=>'company','company_name'=>'Authorized target','status_id'=>'1']);
        $this->projects->save($this->actor(),$this->data(['client_id'=>(string)$target,'version'=>$this->version($id)]),$id);$this->assertSame($target,(int)$this->projects->find($this->actor(),$id)['client_id']);
        $other=$this->clients->save($this->actor(3),['type'=>'company','company_name'=>'Foreign target','status_id'=>'1']);$this->denied(fn()=>$this->projects->save($this->actor(),$this->data(['client_id'=>(string)$other,'version'=>$this->version($id)]),$id));
        $this->clients->archive($this->actor(),$this->client,true);$this->invalid(fn()=>$this->projects->save($this->actor(),$this->data(['version'=>$this->version($id)]),$id));
        $this->assertSame($target,(int)$this->projects->find($this->actor(),$id)['client_id']);
        $this->projects->save($this->actor(1),$this->data(['client_id'=>(string)$target,'owner_id'=>'3','version'=>$this->version($id)]),$id);$meta=json_decode($this->projects->details($this->actor(1),$id)['history'][0]['metadata'],true);$this->assertSame(2,$meta['owner_from']);$this->assertSame(3,$meta['owner_to']);
    }
    public function testDisposableMigrationRollbackPreservesExistingClients():void
    {
        $forge=Database::forge($this->connection);(new CreateProjectManagement($forge))->down();$this->assertFalse($this->connection->tableExists('projects'));$this->assertNotNull($this->clients->find($this->actor(),$this->client));
        (new CreateProjectManagement($forge))->up();(new ProjectReferenceSeeder(config('Database'),$this->connection))->run();$this->assertSame(5,count($this->projects->statuses()));$this->assertNotNull($this->create());
    }
}
