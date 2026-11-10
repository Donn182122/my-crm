<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{ClientService,ClientPolicy,ClientValidationException,NavigationService};

class Clients extends BaseController
{
    private function clients(): ClientService { return new ClientService(); }
    private function render(string $view, string $title, array $data = [], int $status = 200): string
    {
        helper(['crm','form']); $user=auth()->user(); $nav=new NavigationService();
        $this->response->setStatusCode($status);
        return view('clients/'.$view,$data + ['title'=>$title,'active'=>'clients','current'=>'clients','user'=>$user,
            'navigation'=>$nav->sections($user),'destinations'=>$nav->destinations($user),'policy'=>new ClientPolicy(),'errors'=>[]]);
    }
    public function index(): string { return $this->render('index','Clients',$this->clients()->listing(auth()->user(),$this->request->getGet())+$this->clients()->lookups()); }
    public function show(int $id): string { return $this->render('show','Client profile',$this->clients()->details(auth()->user(),$id)); }
    public function create(): string
    {
        (new ClientPolicy())->require(auth()->user(),'create');
        return $this->form(['type'=>'company','status_id'=>'','groups'=>[]]);
    }
    private function form(array $values, ?int $id = null, array $errors = []): string
    {
        return $this->render('form',$id ? 'Edit client' : 'Add client',['values'=>$values,'id'=>$id,'errors'=>$errors]+$this->clients()->lookups(),$errors ? 422 : 200);
    }
    public function edit(int $id): string
    {
        $details=$this->clients()->details(auth()->user(),$id); $client=$details['client'];
        (new ClientPolicy())->require(auth()->user(),'edit',$client);
        if ($client['archived_at']) { return $this->render('show','Client profile',$details+['errors'=>['Restore this client before editing.']],422); }
        return $this->form($client+['groups'=>array_column($details['memberships'],'id')],$id);
    }
    public function store() { return $this->persist(); }
    public function update(int $id) { return $this->persist($id); }
    private function persist(?int $id = null)
    {
        $input=$this->request->getPost();
        try { $saved=$this->clients()->save(auth()->user(),$input,$id); return redirect()->to(site_url('clients/'.$saved))->with('success',$id ? 'Client updated.' : 'Client created.'); }
        catch(ClientValidationException $e) { return $this->form($input,$id,$e->errors); }
    }
    public function archive(int $id) { return $this->setArchive($id,true); }
    public function restore(int $id) { return $this->setArchive($id,false); }
    private function setArchive(int $id, bool $archive)
    {
        $this->clients()->archive(auth()->user(),$id,$archive);
        return redirect()->to(site_url('clients/'.$id))->with('success',$archive ? 'Client archived. Its contacts and addresses have been retained.' : 'Client restored.');
    }
    public function childCreate(int $clientId, string $kind): string { return $this->childForm($clientId,$kind); }
    public function childEdit(int $clientId, string $kind, int $id): string { return $this->childForm($clientId,$kind,$id); }
    private function childForm(int $clientId, string $kind, ?int $id = null, ?array $values = null, array $errors = []): string
    {
        if (!in_array($kind,['contacts','addresses'],true)) { throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(); }
        $client=$this->clients()->find(auth()->user(),$clientId); (new ClientPolicy())->require(auth()->user(),'edit',$client);
        if ($client['archived_at']) { return $this->render('show','Client profile',$this->clients()->details(auth()->user(),$clientId)+['errors'=>['Restore this client before editing.']],422); }
        $values ??= $id ? $this->clients()->child(auth()->user(),$clientId,$kind,$id) : [];
        return $this->render('child_form',($id ? 'Edit ' : 'Add ').($kind==='contacts' ? 'contact' : 'address'),compact('client','kind','id','values','errors'),$errors ? 422 : 200);
    }
    public function childStore(int $clientId, string $kind) { return $this->childPersist($clientId,$kind); }
    public function childUpdate(int $clientId, string $kind, int $id) { return $this->childPersist($clientId,$kind,$id); }
    private function childPersist(int $clientId, string $kind, ?int $id = null)
    {
        $input=$this->request->getPost();
        try { $this->clients()->saveChild(auth()->user(),$clientId,$kind,$input,$id); return redirect()->to(site_url('clients/'.$clientId))->with('success',ucfirst($kind==='contacts' ? 'Contact' : 'Address').' saved.'); }
        catch(ClientValidationException $e) { return $this->childForm($clientId,$kind,$id,$input,$e->errors); }
    }
    public function childRemove(int $clientId, string $kind, int $id)
    {
        try { $this->clients()->removeChild(auth()->user(),$clientId,$kind,$id); return redirect()->to(site_url('clients/'.$clientId))->with('success','Entry removed.'); }
        catch(ClientValidationException $e) { return redirect()->to(site_url('clients/'.$clientId))->with('error',implode(' ',$e->errors)); }
    }
    public function lookups(): string
    {
        (new ClientPolicy())->require(auth()->user(),'lookups');
        return $this->render('lookups','Client groups & statuses',$this->clients()->lookups());
    }
    public function lookupStore(string $kind) { return $this->lookupPersist($kind); }
    public function lookupUpdate(string $kind, int $id) { return $this->lookupPersist($kind,$id); }
    private function lookupPersist(string $kind, ?int $id = null)
    {
        try { $this->clients()->saveLookup(auth()->user(),$kind,$this->request->getPost(),$id); return redirect()->to(site_url('clients/lookups'))->with('success','Lookup saved. Existing client assignments are retained.'); }
        catch(ClientValidationException $e) { return $this->render('lookups','Client groups & statuses',['errors'=>$e->errors]+$this->clients()->lookups(),422); }
    }
}
