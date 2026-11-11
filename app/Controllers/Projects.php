<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{ProjectService,ProjectPolicy,ProjectInputException,NavigationService};
use CodeIgniter\Exceptions\PageNotFoundException;
class Projects extends BaseController
{
    private function service():ProjectService{return new ProjectService();}
    private function query(string $key):string{$value=$this->request->getGet($key);return is_string($value)?mb_substr($value,0,100):'';}
    private function render(string $view,string $title,array $data=[],int $status=200):string
    {
        helper(['crm','form']);$nav=new NavigationService();$user=auth()->user();$this->response->setStatusCode($status);
        return view('projects/'.$view,$data+['title'=>$title,'active'=>'projects','current'=>'projects','user'=>$user,'policy'=>new ProjectPolicy(),'errors'=>[],'navigation'=>$nav->sections($user),'destinations'=>$nav->destinations($user)]);
    }
    public function index():string
    {
        $s=$this->service();$user=auth()->user();$listing=$s->listing($user,$this->request->getGet());return $this->render('index','Projects',$listing+['statuses'=>$s->statuses(),'owners'=>$user->can('projects.manageall')?$s->people($user,$this->query('person_q')):[],'clients'=>$s->clients($user,$this->query('client_q'),$listing['client']?['client_id'=>$listing['client']]:null),'clientQuery'=>$this->query('client_q'),'personQuery'=>$this->query('person_q')]);
    }
    public function show(int $id):string
    {
        $s=$this->service();$data=$s->details(auth()->user(),$id);$data['people']=(new ProjectPolicy())->allows(auth()->user(),'members',$data['project'])?$s->people(auth()->user(),$this->query('person_q')):[];$data['personQuery']=$this->query('person_q');$data['taskListing']=auth()->user()->can('tasks.view')?(new \App\Services\TaskService())->listing(auth()->user(),['project'=>(string)$id]):null;return $this->render('show','Project profile',$data);
    }
    private function form(array $values,?int $id=null,array $errors=[],int $status=200):string
    {
        $s=$this->service();$project=$id?$s->find(auth()->user(),$id):null;if($project){(new ProjectPolicy())->require(auth()->user(),'edit',$project);}else{(new ProjectPolicy())->require(auth()->user(),'create');}
        $owners=auth()->user()->can('projects.assign')?$s->people(auth()->user(),$this->query('person_q')):[];$currentOwner=$project?['id'=>$project['owner_id'],'username'=>$project['owner_name']]:['id'=>auth()->id(),'username'=>auth()->user()->username];if(auth()->user()->can('projects.assign')&&!in_array((int)$currentOwner['id'],array_map('intval',array_column($owners,'id')),true)){$owners[]=$currentOwner;}
        $selection=$project;if(is_string($values['client_id']??null)&&ctype_digit($values['client_id'])){$selection=['client_id'=>(int)$values['client_id']];}
        return $this->render('form',$id?'Edit project':'Create project',compact('values','id','errors','project')+['statuses'=>$s->statuses(),'clients'=>$s->clients(auth()->user(),$this->query('client_q'),$selection),'owners'=>$owners,'clientQuery'=>$this->query('client_q'),'personQuery'=>$this->query('person_q')],$status);
    }
    public function create():string
    {
        $client=$this->query('client');$statuses=$this->service()->statuses();return $this->form(['client_id'=>$client,'status_id'=>(string)($statuses[0]['id']??''),'owner_id'=>(string)auth()->id()]);
    }
    public function edit(int $id):string{return $this->form($this->service()->find(auth()->user(),$id),$id);}
    public function store(){return $this->persist();}
    public function update(int $id){return $this->persist($id);}
    private function persist(?int $id=null)
    {
        $input=$this->request->getPost();try{$saved=$this->service()->save(auth()->user(),$input,$id);try{$this->service()->find(auth()->user(),$saved);}catch(PageNotFoundException){return redirect()->to(site_url('projects'))->with('success','Project saved. Your access changed with the new assignment.');}return redirect()->to(site_url('projects/'.$saved))->with('success',$id?'Project updated.':'Project created.');}
        catch(ProjectInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}return $this->form($input,$id,$e->errors,$e->status);}
    }
    private function mutate(int $id,callable $callback)
    {
        try{$callback();try{$this->service()->find(auth()->user(),$id);}catch(PageNotFoundException){return redirect()->to(site_url('projects'))->with('success','Project access updated.');}return redirect()->to(site_url('projects/'.$id))->with('success','Project updated.');}
        catch(ProjectInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}return $this->render('error','Project change needs review',['errors'=>$e->errors,'projectId'=>$id],$e->status);}
    }
    public function archive(int $id){return $this->mutate($id,fn()=>$this->service()->archive(auth()->user(),$id,true,$this->request->getPost('version')));}
    public function restore(int $id){return $this->mutate($id,fn()=>$this->service()->archive(auth()->user(),$id,false,$this->request->getPost('version')));}
    public function addMember(int $id){return $this->mutate($id,fn()=>$this->service()->addMember(auth()->user(),$id,$this->request->getPost()));}
    public function removeMember(int $id,int $member){return $this->mutate($id,fn()=>$this->service()->removeMember(auth()->user(),$id,$member,$this->request->getPost('version')));}
}
