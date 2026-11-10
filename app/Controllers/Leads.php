<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{LeadService,LeadPolicy,LeadInputException,NavigationService};
class Leads extends BaseController
{
    private function leads():LeadService{return new LeadService();}
    private function render(string $view,string $title,array $data=[],int $status=200):string
    {
        helper(['crm','form']);$user=auth()->user();$nav=new NavigationService();$this->response->setStatusCode($status);
        return view('leads/'.$view,$data+['title'=>$title,'active'=>'leads','current'=>'leads','user'=>$user,'policy'=>new LeadPolicy(),'errors'=>[],
            'navigation'=>$nav->sections($user),'destinations'=>$nav->destinations($user)]);
    }
    public function index():string{return $this->render('index','Leads',$this->leads()->listing(auth()->user(),$this->request->getGet())+$this->leads()->lookups()+['owners'=>$this->leads()->owners(auth()->user())]);}
    public function show(int $id):string{return $this->render('show','Lead profile',$this->leads()->details(auth()->user(),$id));}
    private function form(array $values,?int $id=null,array $errors=[],int $status=200):string{return $this->render('form',$id?'Edit lead':'Add lead',compact('values','id','errors')+$this->leads()->lookups()+['owners'=>$this->leads()->owners(auth()->user())],$status);}
    public function create():string
    {
        (new LeadPolicy())->require(auth()->user(),'create');$lookups=$this->leads()->lookups();$values=['type'=>'company','owner_id'=>(string)auth()->id()];
        foreach($lookups['sources'] as $row){if($row['slug']==='manual'){$values['source_id']=$row['id'];}}
        foreach($lookups['statuses'] as $row){if($row['slug']==='new'){$values['status_id']=$row['id'];}}return $this->form($values);
    }
    public function edit(int $id):string
    {
        $details=$this->leads()->details(auth()->user(),$id);$lead=$details['lead'];(new LeadPolicy())->require(auth()->user(),'edit',$lead);
        if($lead['archived_at']||$lead['outcome']!=='open'){return $this->render('show','Lead profile',$details+['errors'=>['Archived and terminal leads are read-only. Restore or request authorized reopening first.']],422);}
        return $this->form($lead,$id);
    }
    public function store(){return $this->persist();}
    public function update(int $id){return $this->persist($id);}
    private function persist(?int $id=null)
    {
        $input=$this->request->getPost();
        try{$saved=$this->leads()->save(auth()->user(),$input,$id);$lead=$this->leads()->find(auth()->user(),$saved);return redirect()->to(site_url('leads/'.$saved))->with('success',$id?'Lead updated.':'Lead created.');}
        catch(\CodeIgniter\Exceptions\PageNotFoundException $e){if(isset($saved)){return redirect()->to(site_url('leads'))->with('success','Lead saved and assigned. It is no longer in your assigned records.');}throw $e;}
        catch(LeadInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors]);}return $this->form($input,$id,$e->errors,$e->status);}
    }
    public function archive(int $id){return $this->state($id,'archive');}
    public function restore(int $id){return $this->state($id,'restore');}
    public function reopen(int $id){return $this->state($id,'reopen');}
    private function state(int $id,string $action)
    {
        try{if($action==='reopen'){$this->leads()->reopen(auth()->user(),$id,$this->request->getPost('version'));}else{$this->leads()->archive(auth()->user(),$id,$action==='archive',$this->request->getPost('version'));}return redirect()->to(site_url('leads/'.$id))->with('success','Lead '.$action.' action completed.');}
        catch(LeadInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors]);}return $this->render('show','Lead profile',$this->leads()->details(auth()->user(),$id)+['errors'=>$e->errors],$e->status);}
    }
    public function childCreate(int $leadId,string $kind):string{return $this->childForm($leadId,$kind);}
    public function childEdit(int $leadId,string $kind,int $id):string{return $this->childForm($leadId,$kind,$id);}
    private function childForm(int $leadId,string $kind,?int $id=null,?array $values=null,array $errors=[],int $status=200):string
    {
        if(!in_array($kind,['contacts','addresses'],true)){throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();}
        $lead=$this->leads()->find(auth()->user(),$leadId);(new LeadPolicy())->require(auth()->user(),'edit',$lead);
        if($lead['archived_at']||$lead['outcome']!=='open'){return $this->render('show','Lead profile',$this->leads()->details(auth()->user(),$leadId)+['errors'=>['Archived and terminal leads are read-only.']],422);}
        $values??=$id?$this->leads()->child(auth()->user(),$leadId,$kind,$id):[];
        return $this->render('child_form',($id?'Edit ':'Add ').($kind==='contacts'?'lead contact':'lead address'),compact('lead','kind','id','values','errors'),$status);
    }
    public function childStore(int $leadId,string $kind){return $this->childPersist($leadId,$kind);}
    public function childUpdate(int $leadId,string $kind,int $id){return $this->childPersist($leadId,$kind,$id);}
    private function childPersist(int $leadId,string $kind,?int $id=null)
    {
        $input=$this->request->getPost();try{$this->leads()->saveChild(auth()->user(),$leadId,$kind,$input,$id);return redirect()->to(site_url('leads/'.$leadId))->with('success','Lead entry saved.');}
        catch(LeadInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors]);}return $this->childForm($leadId,$kind,$id,$input,$e->errors,$e->status);}
    }
    public function childRemove(int $leadId,string $kind,int $id)
    {
        try{$this->leads()->removeChild(auth()->user(),$leadId,$kind,$id,$this->request->getPost('version'));return redirect()->to(site_url('leads/'.$leadId))->with('success','Lead entry removed.');}
        catch(LeadInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors]);}return $this->render('show','Lead profile',$this->leads()->details(auth()->user(),$leadId)+['errors'=>$e->errors],$e->status);}
    }
}
