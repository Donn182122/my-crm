<?php

declare(strict_types=1);
use App\Services\{LeadService,LeadConversionService,ClientService,LeadInputException,DatabaseUnitOfWork};
use App\Database\Migrations\{CreateClientManagement,CreateLeadManagement,CreateLeadConversions};
use App\Database\Seeds\LeadReferenceSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;

final class PipelineTest extends CIUnitTestCase
{
    private $connection;private LeadService $leads;private ClientService $clients;private LeadConversionService $conversion;
    private function actor(int $id=2,?array $permissions=null):User
    {
        $user=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();$user->id=$id;
        $permissions??=['leads.view','leads.create','leads.edit','leads.archive','leads.convert','clients.view','clients.create','clients.edit','clients.archive'];
        $user->method('can')->willReturnCallback(static fn(string $p):bool=>$id===1||in_array($p,$permissions,true));return $user;
    }
    protected function setUp():void
    {
        parent::setUp();foreach(['2026-11-10-090000_CreateClientManagement','2026-11-10-100000_CreateLeadManagement','2026-11-10-110000_CreateLeadConversions'] as $file){require_once APPPATH.'Database/Migrations/'.$file.'.php';}
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users(id INTEGER PRIMARY KEY, username VARCHAR(30), active INTEGER)');foreach([1,2,3,4] as $id){$this->connection->table('users')->insert(['id'=>$id,'username'=>'Fictional'.$id,'active'=>1]);}
        $forge=Database::forge($this->connection);(new CreateClientManagement($forge))->up();(new CreateLeadManagement($forge))->up();(new LeadReferenceSeeder(config('Database'),$this->connection))->run();(new CreateLeadConversions($forge))->up();
        $this->leads=new LeadService($this->connection,fn($id)=>$this->actor($id));$this->clients=new ClientService($this->connection);$this->conversion=new LeadConversionService($this->connection);
    }
    protected function tearDown():void{$this->connection->close();parent::tearDown();}
    private function lead(int $owner=2,array $changes=[]):int{return $this->leads->save($this->actor($owner),array_replace(['type'=>'company','company_name'=>'Fictional Prospect','email'=>'lead'.$owner.'@example.invalid','source_id'=>'1','status_id'=>'3'],$changes));}
    private function client(int $owner=2,array $changes=[]):int{return $this->clients->save($this->actor($owner),array_replace(['type'=>'company','company_name'=>'Existing Fictional Client','email'=>'client'.$owner.'@example.invalid','status_id'=>'1'],$changes));}
    private function version(int $id):string{return (string)$this->leads->find($this->actor(1),$id)['version'];}
    private function command(int $id,string $mode='create',?int $client=null,bool $copy=true,?User $actor=null):array{return $this->conversion->preview($actor??$this->actor(),$id,['version'=>$this->version($id),'mode'=>$mode,'client_id'=>$client?(string)$client:'','client_status_id'=>'1','copy_related'=>$copy?'1':'0'])['command'];}
    private function invalid(callable $fn,int $status=422):void{try{$fn();$this->fail('Invalid pipeline action accepted');}catch(LeadInputException $e){$this->assertSame($status,$e->status);}}
    private function denied(callable $fn):void{try{$fn();$this->fail('Unauthorized action accepted');}catch(PageNotFoundException){$this->addToAssertionCount(1);}}
    public function testBoardIsBoundedFilteredAndScoped():void
    {
        for($i=0;$i<22;$i++){$this->lead(2,['company_name'=>'Sample '.$i,'email'=>'sample'.$i.'@example.invalid']);}$this->lead(3);
        $board=$this->leads->board($this->actor(),['q'=>'Sample']);$qualified=array_values(array_filter($board,fn($col)=>$col['status']['slug']==='qualified'))[0];
        $this->assertSame(22,$qualified['listing']['total']);$this->assertCount(20,$qualified['listing']['rows']);
        $page=$this->leads->board($this->actor(),['status'=>'3','source'=>'1','columns'=>['3'=>'2']]);$this->assertCount(1,$page);$this->assertCount(2,$page[0]['listing']['rows']);
        $other=$this->leads->board($this->actor(4),[]);$this->assertSame(0,array_sum(array_column(array_column($other,'listing'),'total')));
    }
    public function testStageTransitionIsVersionedAndRejectsReservedOrForeignChanges():void
    {
        $id=$this->lead();$this->leads->transition($this->actor(),$id,'1','4');$this->assertSame('negotiation',$this->leads->find($this->actor(),$id)['status_slug']);
        $this->invalid(fn()=>$this->leads->transition($this->actor(),$id,'1','3'),409);$this->invalid(fn()=>$this->leads->transition($this->actor(),$id,'2','6'));
        $this->denied(fn()=>$this->leads->transition($this->actor(3),$id,'2','3'));
        $this->leads->transition($this->actor(),$id,'2','5');$this->invalid(fn()=>$this->leads->transition($this->actor(1),$id,'3','3'));$this->assertCount(3,$this->leads->details($this->actor(),$id)['history']);
    }
    public function testCreateClientConversionCopiesChildrenAndRetainsHistory():void
    {
        $id=$this->lead();$this->leads->saveChild($this->actor(),$id,'contacts',['version'=>$this->version($id),'first_name'=>'Demo','last_name'=>'Contact','email'=>'contact@example.invalid']);
        $this->leads->saveChild($this->actor(),$id,'addresses',['version'=>$this->version($id),'kind'=>'billing','line1'=>'123 Fictional Lane','city'=>'Sample City','country'=>'Philippines']);
        $command=$this->command($id);$result=$this->conversion->convert($this->actor(),$id,$command,str_repeat('a',64),true);$client=$this->clients->details($this->actor(),(int)$result['client']['id']);
        $this->assertFalse($result['replayed']);$this->assertSame('Fictional Prospect',$client['client']['display_name']);$this->assertSame(2,(int)$client['client']['owner_id']);$this->assertCount(1,$client['contacts']);$this->assertCount(1,$client['addresses']);
        $lead=$this->leads->details($this->actor(),$id);$this->assertSame('converted',$lead['lead']['outcome']);$this->assertCount(4,$lead['history']);$this->assertCount(1,$lead['contacts']);$this->assertSame('converted',$lead['history'][0]['event']);
    }
    public function testExistingClientLinkKeepsPrimaryDataAndDeduplicatesRelatedRecords():void
    {
        $id=$this->lead();$clientId=$this->client();$before=$this->clients->find($this->actor(),$clientId);
        $contact=['first_name'=>'Demo','last_name'=>'Contact','email'=>'same@example.invalid','phone'=>'555-0100'];$address=['kind'=>'billing','line1'=>'123 Fictional Lane','city'=>'Sample City','country'=>'Philippines'];
        $this->clients->saveChild($this->actor(),$clientId,'contacts',array_replace($contact,['phone'=>'555-9999']));$this->clients->saveChild($this->actor(),$clientId,'addresses',$address);
        $this->leads->saveChild($this->actor(),$id,'contacts',$contact+['version'=>$this->version($id)]);$this->leads->saveChild($this->actor(),$id,'addresses',$address+['version'=>$this->version($id)]);
        $preview=$this->conversion->preview($this->actor(),$id,['version'=>$this->version($id),'mode'=>'link','client_id'=>(string)$clientId,'copy_related'=>'1']);
        $this->assertSame('keep',$preview['plans']['contacts'][0]['action']);$this->assertTrue($preview['plans']['contacts'][0]['conflict']);
        $this->conversion->convert($this->actor(),$id,$preview['command'],str_repeat('b',64),true);$after=$this->clients->details($this->actor(),$clientId);
        $this->assertSame($before['display_name'],$after['client']['display_name']);$this->assertSame($before['email'],$after['client']['email']);$this->assertCount(1,$after['contacts']);$this->assertSame('555-9999',$after['contacts'][0]['phone']);$this->assertCount(1,$after['addresses']);
    }
    public function testLinkCanAddConfirmedMissingRecordsWithoutOverwritingClient():void
    {
        $id=$this->lead();$clientId=$this->client();$this->leads->saveChild($this->actor(),$id,'contacts',['version'=>$this->version($id),'first_name'=>'Missing','last_name'=>'Person']);
        $result=$this->conversion->convert($this->actor(),$id,$this->command($id,'link',$clientId),str_repeat('c',64),true);
        $this->assertSame($clientId,(int)$result['client']['id']);$this->assertSame('Existing Fictional Client',$result['client']['display_name']);$this->assertCount(1,$this->clients->details($this->actor(),$clientId)['contacts']);
    }
    public function testExplicitConfirmationAndValidPreviewAreRequired():void
    {
        $id=$this->lead();$command=$this->command($id);$this->invalid(fn()=>$this->conversion->convert($this->actor(),$id,$command,str_repeat('d',64),false));
        $this->invalid(fn()=>$this->conversion->convert($this->actor(),$id,array_replace($command,['digest'=>str_repeat('0',64)]),str_repeat('d',64),true),409);
        $this->invalid(fn()=>$this->conversion->convert($this->actor(),$id,$command,'invalid',true));$this->assertSame(0,$this->connection->table('clients')->countAllResults());
    }
    public function testStaleLeadOrClientPreviewCannotWrite():void
    {
        $id=$this->lead();$clientId=$this->client();$command=$this->command($id,'link',$clientId);
        $this->clients->save($this->actor(),['type'=>'company','company_name'=>'Changed Client','email'=>'client2@example.invalid','status_id'=>'1'],$clientId);
        $this->invalid(fn()=>$this->conversion->convert($this->actor(),$id,$command,str_repeat('e',64),true),409);
        $command=$this->command($id);$this->leads->transition($this->actor(),$id,$this->version($id),'4');$this->invalid(fn()=>$this->conversion->convert($this->actor(),$id,$command,str_repeat('e',64),true));
        $this->assertSame(0,$this->connection->table('lead_conversions')->countAllResults());
    }
    public function testRetriesReturnOriginalClientAndDoNotDuplicateHistory():void
    {
        $id=$this->lead();$command=$this->command($id);$first=$this->conversion->convert($this->actor(),$id,$command,str_repeat('f',64),true);
        foreach(['f','1'] as $key){$retry=$this->conversion->convert($this->actor(),$id,$command,str_repeat($key,64),true);$this->assertTrue($retry['replayed']);$this->assertSame($first['client']['id'],$retry['client']['id']);}
        $this->assertSame(1,$this->connection->table('clients')->countAllResults());$this->assertSame(1,$this->connection->table('lead_conversions')->countAllResults());$this->assertCount(2,$this->leads->details($this->actor(),$id)['history']);
    }
    public function testForeignLeadsClientsAndResultsAreIndependentlyProtected():void
    {
        $id=$this->lead();$other=$this->client(3,['email'=>'lead2@example.invalid']);$this->assertSame([],$this->conversion->candidates($this->actor(),$id)['rows']);
        $this->denied(fn()=>$this->command($id,'link',$other));$this->denied(fn()=>$this->conversion->preview($this->actor(3),$id,['version'=>'1','mode'=>'create']));
        $this->denied(fn()=>$this->conversion->preview($this->actor(2,['leads.view','clients.view']),$id,['version'=>'1','mode'=>'create']));
        $this->invalid(fn()=>$this->conversion->convert($this->actor(),$id,$this->command($id),str_repeat('2',64),true));
        $this->assertSame(1,$this->connection->table('clients')->countAllResults());
    }
    public function testLinkWithoutCopyNeedsViewButCopyRequiresEdit():void
    {
        $id=$this->lead();$clientId=$this->client();$viewer=$this->actor(2,['leads.view','leads.convert','clients.view']);
        $this->denied(fn()=>$this->command($id,'link',$clientId,true,$viewer));
        $command=$this->command($id,'link',$clientId,false,$viewer);$result=$this->conversion->convert($viewer,$id,$command,str_repeat('3',64),true);$this->assertSame($clientId,(int)$result['client']['id']);
    }
    public function testConvertedLeadDoesNotGrantClientAccess():void
    {
        $id=$this->lead();$result=$this->conversion->convert($this->actor(),$id,$this->command($id),str_repeat('4',64),true);$this->connection->table('clients')->where('id',$result['client']['id'])->update(['owner_id'=>3]);
        $this->denied(fn()=>$this->conversion->result($this->actor(),$id));$this->denied(fn()=>$this->conversion->convert($this->actor(),$id,[],str_repeat('4',64),true));
        $this->assertSame('converted',$this->leads->find($this->actor(),$id)['outcome']);
    }
    public function testArchivedAndUnqualifiedLeadsAndArchivedClientsAreRejected():void
    {
        $id=$this->lead();$clientId=$this->client();$this->clients->archive($this->actor(),$clientId,true);$this->invalid(fn()=>$this->command($id,'link',$clientId));
        $this->leads->archive($this->actor(),$id,true,$this->version($id));$this->invalid(fn()=>$this->command($id));$this->leads->archive($this->actor(),$id,false,$this->version($id));
        foreach(['1','5'] as $status){if($status==='5'){$this->leads->transition($this->actor(),$id,$this->version($id),'5');}else{$this->leads->transition($this->actor(),$id,$this->version($id),'1');}$this->invalid(fn()=>$this->command($id));}
    }
    public function testFailureAfterClientInsertRollsBackEverythingAndRetryWorks():void
    {
        $id=$this->lead();$command=$this->command($id);$this->connection->query("CREATE TRIGGER reject_conversion BEFORE INSERT ON lead_conversions BEGIN SELECT RAISE(ABORT,'Fictional conversion failure'); END");
        try{$this->conversion->convert($this->actor(),$id,$command,str_repeat('5',64),true);$this->fail('Conversion should fail');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
        $this->assertSame(0,$this->connection->table('clients')->countAllResults());$this->assertSame('qualified',$this->leads->find($this->actor(),$id)['status_slug']);$this->assertSame('1',$this->version($id));$this->assertCount(1,$this->leads->details($this->actor(),$id)['history']);
        $this->connection->query('DROP TRIGGER reject_conversion');$this->conversion->convert($this->actor(),$id,$command,str_repeat('5',64),true);$this->assertSame(1,$this->connection->table('clients')->countAllResults());
    }
    public function testHistoryFailureRollsBackLinkedContactsAndConversion():void
    {
        $id=$this->lead();$clientId=$this->client();$this->leads->saveChild($this->actor(),$id,'contacts',['version'=>$this->version($id),'first_name'=>'Rollback','last_name'=>'Contact']);$version=$this->version($id);$command=$this->command($id,'link',$clientId);
        $this->connection->query("CREATE TRIGGER reject_converted_history BEFORE INSERT ON lead_history WHEN NEW.event='converted' BEGIN SELECT RAISE(ABORT,'Fictional history failure'); END");
        try{$this->conversion->convert($this->actor(),$id,$command,str_repeat('6',64),true);$this->fail('History failure should abort conversion');}catch(\CodeIgniter\Database\Exceptions\DatabaseException){$this->addToAssertionCount(1);}
        $this->assertSame([],$this->clients->details($this->actor(),$clientId)['contacts']);$this->assertSame(0,$this->connection->table('lead_conversions')->countAllResults());$this->assertSame($version,$this->version($id));$this->assertCount(2,$this->leads->details($this->actor(),$id)['history']);
    }
    public function testClientOperationsDoNotCommitCallersTransaction():void
    {
        $this->connection->transBegin();$this->client();$this->assertSame(1,$this->connection->transDepth);$this->connection->transRollback();$this->assertSame(0,$this->connection->table('clients')->countAllResults());
    }
    public function testDatabaseEnforcesOneConversionPerLead():void
    {
        $id=$this->lead();$this->conversion->convert($this->actor(),$id,$this->command($id),str_repeat('7',64),true);$row=$this->connection->table('lead_conversions')->get()->getRowArray();unset($row['id']);$row['request_key']=str_repeat('8',64);
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);$this->connection->table('lead_conversions')->insert($row);
    }
}
