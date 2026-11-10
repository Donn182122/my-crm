<?php

declare(strict_types=1);
use App\Services\{LeadService,LeadInputException};
use App\Database\Migrations\CreateLeadManagement;
use App\Database\Seeds\LeadReferenceSeeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
final class LeadServiceTest extends CIUnitTestCase
{
    private $connection;
    private LeadService $leads;
    private function actor(int $id=2,bool $admin=false,array $permissions=['view','create','edit','archive']):User
    {
        $user=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();$user->id=$id;$user->method('can')->willReturnCallback(static fn(string $p):bool=>$admin||in_array(substr($p,6),$permissions,true));return $user;
    }
    protected function setUp():void
    {
        parent::setUp();require_once APPPATH.'Database/Migrations/2026-11-10-100000_CreateLeadManagement.php';
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users(id INTEGER PRIMARY KEY, username VARCHAR(30), active INTEGER)');
        foreach([1,2,3,4] as $id){$this->connection->table('users')->insert(['id'=>$id,'username'=>'Fictional'.$id,'active'=>$id===3?0:1]);}
        (new CreateLeadManagement(Database::forge($this->connection)))->up();(new LeadReferenceSeeder(config('Database'),$this->connection))->run();
        $this->leads=new LeadService($this->connection,fn($id)=>$this->actor($id,$id===1));
    }
    protected function tearDown():void{$this->connection->close();parent::tearDown();}
    private function data(array $changes=[]):array{return array_replace(['type'=>'company','company_name'=>'Fictional Lead','email'=>'lead@example.invalid','source_id'=>'1','status_id'=>'1'],$changes);}
    private function version(int $id):string{return (string)$this->leads->find($this->actor(1,true),$id)['version'];}
    private function invalid(callable $fn,int $expected=422):void{try{$fn();$this->fail('Invalid mutation accepted');}catch(LeadInputException $e){$this->assertSame($expected,$e->status);}}
    private function denied(callable $fn):void{try{$fn();$this->fail('Unauthorized mutation accepted');}catch(PageNotFoundException){$this->addToAssertionCount(1);}}
    public function testIdempotentSeederPreservesCustomizedReferenceData():void
    {
        $this->connection->table('lead_sources')->where('slug','manual')->update(['name'=>'Customized manual']);
        (new LeadReferenceSeeder(config('Database'),$this->connection))->run();
        $this->assertSame(6,$this->connection->table('lead_sources')->countAllResults());$this->assertSame(6,$this->connection->table('lead_statuses')->countAllResults());
        $this->assertSame('Customized manual',$this->connection->table('lead_sources')->where('slug','manual')->get()->getRow('name'));
    }
    public function testCreationProtectsOwnerCreatorCodeAndVersion():void
    {
        $id=$this->leads->save($this->actor(),$this->data(['owner_id'=>'1','created_by'=>'1','version'=>'99','code'=>'FORGED','archived_at'=>'2000-01-01']));
        $lead=$this->leads->find($this->actor(),$id);$this->assertSame(2,(int)$lead['owner_id']);$this->assertSame(2,(int)$lead['created_by']);$this->assertSame(1,(int)$lead['version']);$this->assertNull($lead['archived_at']);$this->assertStringStartsWith('LD-',$lead['code']);
        $this->assertCount(1,$this->leads->details($this->actor(),$id)['history']);
        $person=$this->leads->save($this->actor(),$this->data(['type'=>'individual','first_name'=>'Demo','last_name'=>'Person','email'=>'person@example.invalid']));$this->assertSame('Demo Person',$this->leads->find($this->actor(),$person)['display_name']);
    }
    public function testEditAndOptimisticConcurrencyLeaveHistoryAtomic():void
    {
        $id=$this->leads->save($this->actor(),$this->data());$input=$this->data(['company_name'=>'Updated','version'=>'1']);$this->leads->save($this->actor(),$input,$id);
        $this->invalid(fn()=>$this->leads->save($this->actor(),$this->data(['company_name'=>'Stale','version'=>'1']),$id),409);
        $this->invalid(fn()=>$this->leads->save($this->actor(),$this->data(['version'=>'99']),$id),409);
        $detail=$this->leads->details($this->actor(),$id);$this->assertSame('Updated',$detail['lead']['display_name']);$this->assertSame(2,(int)$detail['lead']['version']);$this->assertCount(2,$detail['history']);
    }
    public function testSourcesStatusesTypesAndDuplicateValidation():void
    {
        foreach([['source_id'=>'999'],['status_id'=>'999'],['status_id'=>'6'],['status_id'=>'5'],['company_name'=>''],['type'=>'invalid'],['email'=>'invalid'],['website'=>'javascript:alert(1)'],['company_name'=>['nested']]] as $change){$this->invalid(fn()=>$this->leads->save($this->actor(),$this->data($change)));}
        $id=$this->leads->save($this->actor(),$this->data());$this->leads->archive($this->actor(),$id,true,'1');$this->invalid(fn()=>$this->leads->save($this->actor(),$this->data(['email'=>'LEAD@example.invalid'])));
        $this->leads->save($this->actor(),$this->data(['email'=>'']));$this->leads->save($this->actor(),$this->data(['email'=>'']));$this->assertSame(2,$this->leads->listing($this->actor(),[])['total']);
    }
    public function testOwnershipAssignmentAndReadonlyRestrictions():void
    {
        $admin=$this->actor(1,true);$id=$this->leads->save($admin,$this->data(['owner_id'=>'2']));$this->assertSame(1,(int)$this->leads->find($admin,$id)['created_by']);
        $this->denied(fn()=>$this->leads->find($this->actor(4),$id));$this->denied(fn()=>$this->leads->save($this->actor(4),$this->data(['version'=>'1']),$id));$this->denied(fn()=>$this->leads->archive($this->actor(4),$id,true,'1'));
        $this->invalid(fn()=>$this->leads->save($admin,$this->data(['owner_id'=>'3','version'=>'1']),$id));
        $this->leads->save($admin,$this->data(['owner_id'=>'4','version'=>'1']),$id);$this->denied(fn()=>$this->leads->find($this->actor(2),$id));
        $reader=$this->actor(4,false,['view']);$this->assertNotNull($this->leads->find($reader,$id));$this->denied(fn()=>$this->leads->save($reader,$this->data(['version'=>'2']),$id));$this->denied(fn()=>$this->leads->archive($reader,$id,true,'2'));
    }
    public function testContactsAddressesAndMismatchedParents():void
    {
        $actor=$this->actor();$id=$this->leads->save($actor,$this->data());$other=$this->leads->save($actor,$this->data(['email'=>'other@example.invalid']));
        foreach(['contacts'=>['first_name'=>'Demo','last_name'=>'Contact','email'=>'contact@example.invalid'],'addresses'=>['kind'=>'billing','line1'=>'123 Fictional Lane','city'=>'Sample City','country'=>'Philippines']] as $kind=>$data){
            $child=$this->leads->saveChild($actor,$id,$kind,$data+['version'=>$this->version($id),'lead_id'=>$other]);$this->assertSame($id,(int)$this->leads->child($actor,$id,$kind,$child)['lead_id']);
            $this->leads->saveChild($actor,$id,$kind,$data+['version'=>$this->version($id)],$child);
            $this->denied(fn()=>$this->leads->saveChild($actor,$other,$kind,$data+['version'=>$this->version($other)],$child));
            $this->denied(fn()=>$this->leads->removeChild($actor,$other,$kind,$child,$this->version($other)));
            $this->denied(fn()=>$this->leads->child($this->actor(4),$id,$kind,$child));
            $this->leads->removeChild($actor,$id,$kind,$child,$this->version($id));$this->assertSame([],$this->leads->details($actor,$id)[$kind]);
        }
    }
    public function testChildConflictDuplicateAndRollback():void
    {
        $actor=$this->actor();$id=$this->leads->save($actor,$this->data());$data=['first_name'=>'Demo','last_name'=>'Contact','email'=>'contact@example.invalid','version'=>'1'];
        $child=$this->leads->saveChild($actor,$id,'contacts',$data);
        $this->invalid(fn()=>$this->leads->saveChild($actor,$id,'contacts',array_replace($data,['email'=>'second@example.invalid'])),409);
        $this->invalid(fn()=>$this->leads->saveChild($actor,$id,'contacts',array_replace($data,['version'=>'2'])));
        $this->invalid(fn()=>$this->leads->removeChild($actor,$id,'contacts',$child,'1'),409);
        $this->assertSame('2',$this->version($id));$this->assertCount(1,$this->leads->details($actor,$id)['contacts']);$this->assertCount(2,$this->leads->details($actor,$id)['history']);
    }
    public function testArchiveRestoreAndTerminalReopening():void
    {
        $actor=$this->actor();$admin=$this->actor(1,true);$id=$this->leads->save($actor,$this->data());
        $this->leads->archive($actor,$id,true,'1');$this->invalid(fn()=>$this->leads->archive($actor,$id,false,'1'),409);
        $this->invalid(fn()=>$this->leads->saveChild($actor,$id,'contacts',['first_name'=>'No','last_name'=>'Edit','version'=>'2']));
        $this->assertSame(1,$this->leads->listing($actor,['archive'=>'archived'])['total']);$this->leads->archive($actor,$id,false,'2');
        $this->leads->save($actor,$this->data(['status_id'=>'5','version'=>'3']),$id);
        $this->invalid(fn()=>$this->leads->save($admin,$this->data(['version'=>'4']),$id));$this->denied(fn()=>$this->leads->reopen($actor,$id,'4'));
        $this->leads->reopen($admin,$id,'4');$this->assertSame('open',$this->leads->find($actor,$id)['outcome']);$this->assertSame('5',$this->version($id));
    }
    public function testSearchFiltersAndPagination():void
    {
        for($i=1;$i<=22;$i++){$this->leads->save($this->actor(),$this->data(['company_name'=>sprintf('Sample %02d',$i),'email'=>"lead$i@example.invalid",'source_id'=>$i===1?'2':'1']));}
        $this->assertCount(20,$this->leads->listing($this->actor(),[])['rows']);$this->assertCount(2,$this->leads->listing($this->actor(),['page'=>'2'])['rows']);
        $this->assertSame(1,$this->leads->listing($this->actor(),['source'=>'2','status'=>'1','q'=>'Sample 01'])['total']);
        foreach(['%',"' OR 1=1 --"] as $q){$this->assertSame(0,$this->leads->listing($this->actor(),['q'=>$q])['total']);}$this->assertSame(0,$this->leads->listing($this->actor(4),[])['total']);
    }
    public function testInactiveSourceAndMissingVersionAreRejected():void
    {
        $this->connection->table('lead_sources')->where('id',1)->update(['active'=>0]);
        $this->invalid(fn()=>$this->leads->save($this->actor(),$this->data()));
        $this->connection->table('lead_sources')->where('id',1)->update(['active'=>1]);
        $id=$this->leads->save($this->actor(),$this->data());
        $this->invalid(fn()=>$this->leads->save($this->actor(),$this->data(),$id));
        $this->invalid(fn()=>$this->leads->archive($this->actor(),$id,true,['nested']));
        $this->assertSame('1',$this->version($id));
    }
    public function testArchivedAndTerminalChildrenStayReadOnly():void
    {
        $actor=$this->actor();$admin=$this->actor(1,true);$id=$this->leads->save($actor,$this->data());
        $children=[];$payloads=['contacts'=>['first_name'=>'Demo','last_name'=>'Contact'],'addresses'=>['kind'=>'other','line1'=>'123 Sample Lane','city'=>'Sample City','country'=>'Philippines']];
        foreach($payloads as $kind=>$data){$children[$kind]=$this->leads->saveChild($actor,$id,$kind,$data+['version'=>$this->version($id)]);}
        $this->leads->archive($actor,$id,true,$this->version($id));
        foreach($payloads as $kind=>$data){$this->invalid(fn()=>$this->leads->saveChild($admin,$id,$kind,$data+['version'=>$this->version($id)],$children[$kind]));$this->invalid(fn()=>$this->leads->removeChild($admin,$id,$kind,$children[$kind],$this->version($id)));}
        $this->leads->archive($actor,$id,false,$this->version($id));$this->leads->save($actor,$this->data(['status_id'=>'5','version'=>$this->version($id)]),$id);
        foreach($payloads as $kind=>$data){$this->invalid(fn()=>$this->leads->saveChild($admin,$id,$kind,$data+['version'=>$this->version($id)],$children[$kind]));$this->invalid(fn()=>$this->leads->removeChild($admin,$id,$kind,$children[$kind],$this->version($id)));}
        $details=$this->leads->details($actor,$id);$this->assertCount(1,$details['contacts']);$this->assertCount(1,$details['addresses']);$this->assertSame('6',$this->version($id));
    }
    public function testHistoryFailureRollsBackLeadVersionAndData():void
    {
        $id=$this->leads->save($this->actor(),$this->data());
        $this->connection->query("CREATE TRIGGER reject_history BEFORE INSERT ON lead_history WHEN NEW.event='updated' BEGIN SELECT RAISE(ABORT,'Fictional history failure'); END");
        try{$this->leads->save($this->actor(),$this->data(['company_name'=>'Must roll back','version'=>'1']),$id);$this->fail('Failed history should abort lead mutation');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
        $detail=$this->leads->details($this->actor(),$id);$this->assertSame('Fictional Lead',$detail['lead']['display_name']);$this->assertSame(1,(int)$detail['lead']['version']);$this->assertCount(1,$detail['history']);
    }
    public function testDatabaseForeignKeysAndUniqueCodes():void
    {
        $id=$this->leads->save($this->actor(),$this->data());$this->connection->transException(true)->transBegin();
        try{$this->connection->table('lead_contacts')->insert(['lead_id'=>999,'first_name'=>'Invalid','last_name'=>'Parent','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);$this->fail('Foreign key accepted');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}finally{$this->connection->transRollback();}
        $row=$this->connection->table('leads')->where('id',$id)->get()->getRowArray();unset($row['id']);$row['email']='different@example.invalid';$this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('leads')->insert($row);
    }
}
