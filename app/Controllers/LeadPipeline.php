<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{LeadService,LeadPolicy,LeadConversionService,ClientService,LeadInputException,NavigationService};
use CodeIgniter\Exceptions\PageNotFoundException;

class LeadPipeline extends BaseController
{
    private function render(string $view,string $title,array $data=[],int $status=200):string
    {
        helper(['crm','form']);$user=auth()->user();$nav=new NavigationService();$this->response->setStatusCode($status);
        return view('leads/'.$view,$data+['title'=>$title,'active'=>'leads','current'=>'leads','user'=>$user,'policy'=>new LeadPolicy(),'errors'=>[],
            'navigation'=>$nav->sections($user),'destinations'=>$nav->destinations($user)]);
    }
    public function board():string
    {
        $service=new LeadService();$input=$this->request->getGet();$input['archive']='active';
        return $this->render('kanban','Leads pipeline',['columns'=>$service->board(auth()->user(),$input),'input'=>$input,'owners'=>$service->owners(auth()->user())]+$service->lookups());
    }
    public function stage(int $id)
    {
        try{(new LeadService())->transition(auth()->user(),$id,$this->request->getPost('version'),$this->request->getPost('status_id'));
            if($this->request->isAJAX()){return $this->response->setJSON(['message'=>'Pipeline stage updated.','csrf'=>csrf_hash()]);}
            return redirect()->to(site_url('leads/kanban'))->with('success','Pipeline stage updated.');
        }catch(LeadInputException $e){
            if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}
            return $this->render('pipeline_error','Stage change needs review',['errors'=>$e->errors],$e->status);
        }
    }
    public function start(int $id):string
    {
        $lead=(new LeadService())->find(auth()->user(),$id);(new LeadPolicy())->require(auth()->user(),'convert',$lead);
        if($lead['outcome']==='converted'){return $this->result($id);}
        $statuses=(new ClientService())->lookups()['statuses'];$default='';foreach($statuses as $status){if($status['name_key']==='active'){$default=(string)$status['id'];}}
        return $this->choice($id,['version'=>(string)$lead['version'],'mode'=>auth()->user()->can('clients.create')?'create':'link','client_status_id'=>$default,'copy_related'=>'1']);
    }
    private function choice(int $id,array $values,array $errors=[],int $status=200):string
    {
        $lead=(new LeadService())->find(auth()->user(),$id);$query=is_string($this->request->getGet('q'))?mb_substr($this->request->getGet('q'),0,100):'';
        try{$candidates=(new LeadConversionService())->candidates(auth()->user(),$id,$query);}
        catch(LeadInputException $e){return $this->render('pipeline_error','Conversion needs review',['errors'=>$e->errors],$e->status);}
        return $this->render('conversion_choice','Prepare lead conversion',compact('lead','values','errors','candidates')+['clientStatuses'=>(new ClientService())->lookups()['statuses']],$status);
    }
    public function preview(int $id)
    {
        $input=$this->request->getPost();try{
            $preview=(new LeadConversionService())->preview(auth()->user(),$id,$input);$key=bin2hex(random_bytes(32));
            $saved=session()->get('conversion_previews')??[];if(!is_array($saved)){$saved=[];}
            $saved=array_filter($saved,static fn($item)=>is_array($item)&&($item['created']??0)>time()-1800);
            $saved=array_slice($saved,-19,null,true);$saved[$key]=['lead_id'=>$id,'user_id'=>(int)auth()->id(),'created'=>time(),'command'=>$preview['command']];session()->set('conversion_previews',$saved);
            return $this->render('conversion_preview','Review conversion mapping',$preview+['requestKey'=>$key]);
        }catch(LeadInputException $e){return $this->choice($id,$input,$e->errors,$e->status);}
    }
    public function confirm(int $id)
    {
        // Authorize the source before revealing anything about a confirmation key.
        $lead=(new LeadService())->find(auth()->user(),$id);(new LeadPolicy())->require(auth()->user(),'convert',$lead);
        $key=$this->request->getPost('request_key');$all=session()->get('conversion_previews')??[];$preview=is_string($key)&&is_array($all)?($all[$key]??null):null;
        if(!is_array($preview)||$preview['lead_id']!==$id||$preview['user_id']!==(int)auth()->id()||$preview['created']<time()-1800){return $this->render('pipeline_error','Conversion preview expired',['errors'=>['Prepare and review a new conversion preview.']],422);}
        try{$result=(new LeadConversionService())->convert(auth()->user(),$id,$preview['command'],$key,$this->request->getPost('confirm')==='yes');
            return redirect()->to(site_url('leads/'.$id.'/conversion'))->with('success',$result['replayed']?'This lead was already converted. The original result is shown.':'Lead converted successfully.');
        }catch(LeadInputException $e){return $this->render('pipeline_error','Conversion needs review',['errors'=>$e->errors,'leadId'=>$id],$e->status);}
    }
    public function result(int $id):string
    {
        $result=(new LeadConversionService())->result(auth()->user(),$id);if(!$result){throw PageNotFoundException::forPageNotFound();}
        return $this->render('conversion_result','Lead conversion', $result+['lead'=>(new LeadService())->find(auth()->user(),$id)]);
    }
}
