<?php

declare(strict_types=1);
use App\Services\{ClientService,ClientPolicy,ClientValidationException};
use App\Database\Migrations\CreateClientManagement;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class ClientServiceTest extends CIUnitTestCase
{
    private $connection;
    private ClientService $clients;
    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH.'Database/Migrations/2026-11-10-090000_CreateClientManagement.php';
        $this->connection=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','foreignKeys'=>true,'DBDebug'=>true,'busyTimeout'=>1000,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']],false);
        $this->connection->query('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        foreach([1,2,3] as $id){$this->connection->table('users')->insert(['id'=>$id]);}
        (new CreateClientManagement(Database::forge($this->connection)))->up();
        $this->clients=new ClientService($this->connection);
    }
    protected function tearDown(): void { $this->connection->close(); parent::tearDown(); }
    private function actor(int $id=1, bool $admin=false, array $permissions=['view','create','edit','archive']): User
    {
        $user=$this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock(); $user->id=$id;
        $user->method('can')->willReturnCallback(static fn(string $permission):bool=>$admin || in_array(substr($permission,8),$permissions,true)); return $user;
    }
    private function data(array $changes=[]): array { return array_replace(['type'=>'company','company_name'=>'Fictional Studio','email'=>'studio@example.invalid','status_id'=>'1','groups'=>[]],$changes); }
    public function testShieldPermissionKeysAreLowercase(): void
    {
        foreach (array_keys(config('AuthGroups')->permissions) as $permission) { $this->assertSame(strtolower($permission),$permission); }
        foreach (config('AuthGroups')->matrix as $permissions) { foreach($permissions as $permission) { $this->assertArrayHasKey($permission,config('AuthGroups')->permissions); } }
    }
    public function testCompanyAndIndividualCreationAndServerOwnedFields(): void
    {
        $id=$this->clients->save($this->actor(),$this->data(['owner_id'=>2,'archived_at'=>'2000-01-01','code'=>'forged']));
        $client=$this->clients->find($this->actor(),$id);$this->assertSame(1,(int)$client['owner_id']);$this->assertNull($client['archived_at']);$this->assertStringStartsWith('CL-',$client['code']);
        $person=$this->clients->save($this->actor(),$this->data(['type'=>'individual','first_name'=>'Sample','last_name'=>'Person','email'=>'person@example.invalid']));
        $this->assertSame('Sample Person',$this->clients->find($this->actor(),$person)['display_name']);
        $this->assertNull($this->clients->find($this->actor(),$person)['company_name']);
    }
    public function testClientEditAndGroupsAreTransactionalAndValidated(): void
    {
        $admin=$this->actor(3,true);$this->clients->saveLookup($admin,'groups',['name'=>'Partners']);
        $id=$this->clients->save($this->actor(),$this->data(['groups'=>['1']]));
        $this->clients->save($this->actor(),$this->data(['company_name'=>'Updated Example','groups'=>[]]),$id);
        $detail=$this->clients->details($this->actor(),$id);$this->assertSame('Updated Example',$detail['client']['display_name']);$this->assertSame([],$detail['memberships']);
        try{$this->clients->save($this->actor(),$this->data(['company_name'=>'Bad update','groups'=>['9999']]),$id);$this->fail('Invalid group accepted');}catch(ClientValidationException $e){$this->assertArrayHasKey('groups',$e->errors);}
        $this->assertSame('Updated Example',$this->clients->find($this->actor(),$id)['display_name']);
    }
    public function testDuplicateNormalizedEmailIncludesArchivedRecords(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());$this->clients->archive($this->actor(),$id,true);
        $this->expectException(ClientValidationException::class);$this->clients->save($this->actor(),$this->data(['email'=>'STUDIO@example.invalid']));
    }
    public function testOptionalEmailsAllowMultipleClients(): void
    {
        $this->clients->save($this->actor(),$this->data(['email'=>'']));$this->clients->save($this->actor(),$this->data(['email'=>'']));
        $this->assertSame(2,$this->clients->listing($this->actor(),[])['total']);
    }
    public function testValidationRejectsInvalidNamesStatusUrlAndNestedInputs(): void
    {
        foreach([['company_name'=>''],['type'=>'individual'],['status_id'=>'999'],['website'=>'javascript:alert(1)'],['email'=>'not-email'],['groups'=>[['1']]],['company_name'=>['bad']]] as $change){
            try{$this->clients->save($this->actor(),$this->data($change));$this->fail('Invalid data accepted');}catch(ClientValidationException $e){$this->assertNotEmpty($e->errors);}
        }
        $this->assertSame(0,$this->clients->listing($this->actor(),[])['total']);
    }
    public function testOwnerIsolationAndAdministratorAccess(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());
        $this->assertSame(0,$this->clients->listing($this->actor(2),[])['total']);$this->assertSame(1,$this->clients->listing($this->actor(3,true),[])['total']);
        foreach(['view','edit','archive'] as $action){try{match($action){'view'=>$this->clients->find($this->actor(2),$id),'edit'=>$this->clients->save($this->actor(2),$this->data(),$id),'archive'=>$this->clients->archive($this->actor(2),$id,true)};$this->fail('Foreign record authorized');}catch(PageNotFoundException){$this->addToAssertionCount(1);}}
        $this->clients->save($this->actor(3,true),$this->data(['company_name'=>'Admin updated']),$id);$this->assertSame('Admin updated',$this->clients->find($this->actor(),$id)['display_name']);
    }
    public function testReadOnlyUserCannotMutateOwnedRecord(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());$reader=$this->actor(1,false,['view']);$this->assertNotNull($this->clients->find($reader,$id));
        $this->expectException(PageNotFoundException::class);$this->clients->archive($reader,$id,true);
    }
    public function testArchiveRestoreRetainsChildrenAndBlocksEdits(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());$this->clients->saveChild($this->actor(),$id,'contacts',['first_name'=>'Demo','last_name'=>'Contact']);
        $this->clients->archive($this->actor(),$id,true);$this->assertSame(0,$this->clients->listing($this->actor(),[])['total']);$this->assertSame(1,$this->clients->listing($this->actor(),['archive'=>'archived'])['total']);
        try{$this->clients->save($this->actor(),$this->data(),$id);$this->fail('Archived client editable');}catch(ClientValidationException){$this->addToAssertionCount(1);}
        $this->clients->archive($this->actor(),$id,false);$this->assertCount(1,$this->clients->details($this->actor(),$id)['contacts']);$this->assertNull($this->clients->find($this->actor(),$id)['archived_at']);
    }
    public function testContactsCreateEditDuplicateAndRemove(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());$data=['first_name'=>'Demo','last_name'=>'Contact','email'=>'contact@example.invalid'];
        $contact=$this->clients->saveChild($this->actor(),$id,'contacts',$data);$this->clients->saveChild($this->actor(),$id,'contacts',array_replace($data,['job_title'=>'Updated role']),$contact);
        $this->assertSame('Updated role',$this->clients->child($this->actor(),$id,'contacts',$contact)['job_title']);
        try{$this->clients->saveChild($this->actor(),$id,'contacts',$data);$this->fail('Duplicate contact accepted');}catch(ClientValidationException){$this->addToAssertionCount(1);}
        $this->clients->removeChild($this->actor(),$id,'contacts',$contact);$this->assertSame([],$this->clients->details($this->actor(),$id)['contacts']);
    }
    public function testAddressesAndChildParentIsolation(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());$other=$this->clients->save($this->actor(),$this->data(['email'=>'other@example.invalid']));
        $data=['kind'=>'billing','line1'=>'123 Fictional Street','city'=>'Sample City','country'=>'Philippines'];$address=$this->clients->saveChild($this->actor(),$id,'addresses',$data);
        $this->clients->saveChild($this->actor(),$id,'addresses',array_replace($data,['city'=>'Updated City']),$address);$this->assertSame('Updated City',$this->clients->child($this->actor(),$id,'addresses',$address)['city']);
        try{$this->clients->removeChild($this->actor(),$other,'addresses',$address);$this->fail('Cross-client child deleted');}catch(PageNotFoundException){$this->addToAssertionCount(1);}
        $this->clients->removeChild($this->actor(),$id,'addresses',$address);$this->assertSame([],$this->clients->details($this->actor(),$id)['addresses']);
    }
    public function testSearchStatusGroupAndPagination(): void
    {
        $this->clients->saveLookup($this->actor(3,true),'groups',['name'=>'Sample Group']);
        for($i=1;$i<=23;$i++){$this->clients->save($this->actor(),$this->data(['company_name'=>sprintf('Sample %02d',$i),'email'=>"sample$i@example.invalid",'status_id'=>$i===1?'2':'1','groups'=>$i===1?['1']:[]]));}
        $this->assertCount(20,$this->clients->listing($this->actor(),[])['rows']);$this->assertCount(3,$this->clients->listing($this->actor(),['page'=>'2'])['rows']);
        $this->assertSame(1,$this->clients->listing($this->actor(),['q'=>'Sample 01'])['total']);$this->assertSame(1,$this->clients->listing($this->actor(),['status'=>'2','group'=>'1'])['total']);
        $this->assertSame(0,$this->clients->listing($this->actor(),['q'=>"' OR 1=1 --"])['total'],'SQL syntax must remain literal');$this->assertSame(0,$this->clients->listing($this->actor(),['q'=>'%'])['total'],'Wildcard must remain literal');
    }
    public function testLookupPermissionsAndDuplicateNames(): void
    {
        $this->clients->saveLookup($this->actor(3,true),'groups',['name'=>'Partners']);$this->clients->saveLookup($this->actor(3,true),'groups',['name'=>'Updated'],1);
        $this->assertSame('Updated',$this->clients->lookups()['groups'][0]['name']);
        try{$this->clients->saveLookup($this->actor(3,true),'groups',['name'=>'UPDATED']);$this->fail('Duplicate lookup accepted');}catch(ClientValidationException){$this->addToAssertionCount(1);}
        $this->expectException(PageNotFoundException::class);$this->clients->saveLookup($this->actor(),'statuses',['name'=>'Forbidden']);
    }
    public function testContactAndAddressValidationAndReadOnlyProtection(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());
        foreach([['contacts',['first_name'=>'','last_name'=>'Contact']],['contacts',['first_name'=>'Demo','last_name'=>'Contact','email'=>'invalid']],['addresses',['kind'=>'billing','line1'=>'Line','city'=>'City','country'=>'']],['addresses',['kind'=>'unsafe','line1'=>'Line','city'=>'City','country'=>'Country']]] as [$kind,$data]) {
            try{$this->clients->saveChild($this->actor(),$id,$kind,$data);$this->fail('Invalid child accepted');}catch(ClientValidationException $e){$this->assertNotEmpty($e->errors);}
        }
        $this->assertSame([],$this->clients->details($this->actor(),$id)['contacts']);
        $this->assertSame([],$this->clients->details($this->actor(),$id)['addresses']);
        $this->expectException(PageNotFoundException::class);
        $this->clients->saveChild($this->actor(1,false,['view']),$id,'contacts',['first_name'=>'Denied','last_name'=>'Contact']);
    }
    public function testDatabaseUniqueEmailConstraintProtectsAgainstRaces(): void
    {
        $id=$this->clients->save($this->actor(),$this->data());
        $row=$this->clients->find($this->actor(),$id);unset($row['id'],$row['status_name']);$row['code']='CL-UNIQUE-TEST';
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->connection->table('clients')->insert($row);
    }
    public function testDatabaseForeignKeysAndUniqueEmailAreEnforced(): void
    {
        $this->clients->save($this->actor(),$this->data());
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->connection->table('client_contacts')->insert(['client_id'=>999,'first_name'=>'Invalid','last_name'=>'Parent','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }
}
