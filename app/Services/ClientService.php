<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;

class ClientService
{
    private BaseConnection $db;
    private ClientPolicy $policy;
    public function __construct(?BaseConnection $db = null) { $this->db=$db ?? db_connect(); $this->policy=new ClientPolicy(); }
    private function input(array $input, string $key): string { return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : ''; }
    private function validate(array $data, array $rules): void
    {
        $validator=service('validation'); $validator->reset(); $validator->setRules($rules);
        if (!$validator->run($data)) { throw new ClientValidationException($validator->getErrors()); }
    }
    private function write(callable $callback): mixed
    {
        $this->db->transException(true)->transBegin();
        try {
            $result=$callback();
            if (!$this->db->transStatus()) { throw new \RuntimeException('Client transaction failed.'); }
            $this->db->transCommit(); return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            if (str_contains($e->getMessage(),'Duplicate entry') || str_contains($e->getMessage(),'UNIQUE constraint')) {
                throw new ClientValidationException(['duplicate'=>'That email or lookup name already exists. Please use a different value.']);
            }
            throw $e;
        }
    }
    private function scoped(User $user)
    {
        $this->policy->require($user,'view');
        $builder=$this->db->table('clients c');
        if (!$user->can('clients.manageall')) { $builder->where('c.owner_id',(int)$user->id); }
        return $builder;
    }
    public function listing(User $user, array $input): array
    {
        $q=mb_substr($this->input($input,'q'),0,100);
        $status=ctype_digit($this->input($input,'status')) ? (int)$input['status'] : 0;
        $group=ctype_digit($this->input($input,'group')) ? (int)$input['group'] : 0;
        $archive=$this->input($input,'archive') === 'archived' ? 'archived' : 'active';
        $page=max(1,min(100000,(int)$this->input($input,'page')));
        $builder=$this->scoped($user)->join('client_statuses s','s.id=c.status_id');
        $builder->where('c.archived_at IS '.($archive === 'archived' ? 'NOT NULL' : 'NULL'), null, false);
        if ($status) { $builder->where('c.status_id',$status); }
        if ($group) { $builder->join('client_group_memberships gm','gm.client_id=c.id')->where('gm.group_id',$group); }
        if ($q !== '') { $match=$this->db->escapeLikeString($q); $builder->groupStart()->like('c.display_name',$match)->orLike('c.email',$match)->orLike('c.code',$match)->orLike('c.phone',$match)->groupEnd(); }
        $total=(clone $builder)->countAllResults(); $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages);
        $rows=$builder->select('c.*, s.name AS status_name')->orderBy('c.display_name')->orderBy('c.id')->limit(20,($page-1)*20)->get()->getResultArray();
        return compact('rows','total','page','pages','q','status','group','archive');
    }
    public function find(User $user, int $id): array
    {
        $client=$this->scoped($user)->select('c.*, s.name AS status_name')->join('client_statuses s','s.id=c.status_id')->where('c.id',$id)->get()->getRowArray();
        if (!$client) { throw PageNotFoundException::forPageNotFound(); }
        return $client;
    }
    public function lookups(): array
    {
        return ['statuses'=>$this->db->table('client_statuses')->orderBy('name')->get()->getResultArray(),
            'groups'=>$this->db->table('client_groups')->orderBy('name')->get()->getResultArray()];
    }
    public function details(User $user, int $id): array
    {
        $client=$this->find($user,$id);
        return ['client'=>$client,
            'contacts'=>$this->db->table('client_contacts')->where('client_id',$id)->orderBy('first_name')->get()->getResultArray(),
            'addresses'=>$this->db->table('client_addresses')->where('client_id',$id)->orderBy('id')->get()->getResultArray(),
            'memberships'=>$this->db->table('client_group_memberships m')->select('g.id,g.name')->join('client_groups g','g.id=m.group_id')->where('m.client_id',$id)->orderBy('g.name')->get()->getResultArray()];
    }
    private function writable(User $user, int $id, string $action = 'edit'): array
    {
        $client=$this->find($user,$id); $this->policy->require($user,$action,$client);
        if ($action === 'edit' && $client['archived_at'] !== null) { throw new ClientValidationException(['archive'=>'Restore this client before editing its information, contacts or addresses.']); }
        return $client;
    }
    public function save(User $user, array $input, ?int $id = null): int
    {
        if ($id === null) { $this->policy->require($user,'create'); } else { $this->writable($user,$id); }
        $data=[]; foreach (['type','company_name','first_name','last_name','email','phone','website','notes','status_id'] as $field) { $data[$field]=$this->input($input,$field); }
        $data['email']=mb_strtolower($data['email']);
        $this->validate($data,[
            'type'=>'required|in_list[company,individual]', 'company_name'=>'permit_empty|max_length[180]',
            'first_name'=>'permit_empty|max_length[80]','last_name'=>'permit_empty|max_length[80]',
            'email'=>'permit_empty|valid_email|max_length[190]','phone'=>'permit_empty|max_length[40]',
            'website'=>'permit_empty|valid_url_strict[https,http]|max_length[255]','notes'=>'permit_empty|max_length[5000]',
            'status_id'=>'required|is_natural_no_zero',
        ]);
        $errors=[];
        if ($data['type']==='company' && $data['company_name']==='') { $errors['company_name']='Company name is required for a company.'; }
        if ($data['type']==='individual' && ($data['first_name']==='' || $data['last_name']==='')) { $errors['first_name']='First and last names are required for an individual.'; }
        if (!$this->db->table('client_statuses')->where('id',(int)$data['status_id'])->countAllResults()) { $errors['status_id']='Choose an existing client status.'; }
        $groupInput=$input['groups'] ?? [];
        if (!is_array($groupInput) || count($groupInput)>100) { $errors['groups']='Choose valid client groups.'; $groupInput=[]; }
        $groups=[];
        foreach ($groupInput as $value) { if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1) { $errors['groups']='Choose valid client groups.'; } else { $groups[]=(int)$value; } }
        $groups=array_values(array_unique($groups));
        if ($groups && $this->db->table('client_groups')->whereIn('id',$groups)->countAllResults() !== count($groups)) { $errors['groups']='A selected client group no longer exists.'; }
        if ($data['email']!=='') {
            $duplicate=$this->db->table('clients')->where('email',$data['email']); if ($id) { $duplicate->where('id !=',$id); }
            if ($duplicate->countAllResults()) { $errors['email']='This email is already assigned to a client, including archived clients.'; }
        }
        if ($errors) { throw new ClientValidationException($errors); }
        $data['display_name']=$data['type']==='company' ? $data['company_name'] : $data['first_name'].' '.$data['last_name'];
        if ($data['type']==='company') { $data['first_name']=$data['last_name']=null; } else { $data['company_name']=null; }
        foreach (['email','phone','website','notes'] as $key) { if ($data[$key]==='') { $data[$key]=null; } }
        $data['status_id']=(int)$data['status_id']; $data['updated_at']=date('Y-m-d H:i:s');
        return $this->write(function () use ($user,$data,$groups,$id) {
            if ($id === null) {
                $data['owner_id']=(int)$user->id; $data['created_at']=$data['updated_at']; $data['code']='CL-'.strtoupper(bin2hex(random_bytes(6)));
                $this->db->table('clients')->insert($data); $id=(int)$this->db->insertID();
            } else { $this->db->table('clients')->where('id',$id)->update($data); }
            $this->db->table('client_group_memberships')->where('client_id',$id)->delete();
            foreach ($groups as $group) { $this->db->table('client_group_memberships')->insert(['client_id'=>$id,'group_id'=>$group]); }
            return $id;
        });
    }
    public function archive(User $user, int $id, bool $archive): void
    {
        $this->writable($user,$id,'archive');
        $this->db->table('clients')->where('id',$id)->update(['archived_at'=>$archive ? date('Y-m-d H:i:s') : null,'updated_at'=>date('Y-m-d H:i:s')]);
    }
    public function child(User $user, int $clientId, string $kind, int $id): array
    {
        $this->find($user,$clientId); $table=$this->childTable($kind);
        $row=$this->db->table($table)->where(['id'=>$id,'client_id'=>$clientId])->get()->getRowArray();
        if (!$row) { throw PageNotFoundException::forPageNotFound(); } return $row;
    }
    private function childTable(string $kind): string
    {
        return match ($kind) { 'contacts'=>'client_contacts','addresses'=>'client_addresses',default=>throw PageNotFoundException::forPageNotFound() };
    }
    public function saveChild(User $user, int $clientId, string $kind, array $input, ?int $id = null): int
    {
        $this->writable($user,$clientId); $table=$this->childTable($kind); if ($id !== null) { $this->child($user,$clientId,$kind,$id); }
        $rules=$kind==='contacts' ? [
            'first_name'=>'required|max_length[80]','last_name'=>'required|max_length[80]','job_title'=>'permit_empty|max_length[100]',
            'email'=>'permit_empty|valid_email|max_length[190]','phone'=>'permit_empty|max_length[40]',
        ] : [
            'kind'=>'required|in_list[billing,shipping,other]','line1'=>'required|max_length[180]','line2'=>'permit_empty|max_length[180]',
            'city'=>'required|max_length[100]','region'=>'permit_empty|max_length[100]','postal_code'=>'permit_empty|max_length[24]','country'=>'required|max_length[100]',
        ];
        $data=[]; foreach ($rules as $key=>$rule) { $data[$key]=$this->input($input,$key); }
        if (isset($data['email'])) { $data['email']=mb_strtolower($data['email']); }
        $this->validate($data,$rules);
        if ($kind==='contacts' && $data['email']!=='') {
            $duplicate=$this->db->table($table)->where(['client_id'=>$clientId,'email'=>$data['email']]); if ($id) { $duplicate->where('id !=',$id); }
            if ($duplicate->countAllResults()) { throw new ClientValidationException(['email'=>'That email already belongs to a contact of this client.']); }
        }
        foreach ($data as $key=>$value) { if ($value==='') { $data[$key]=null; } }
        $data['updated_at']=date('Y-m-d H:i:s');
        return $this->write(function () use($data,$id,$clientId,$table) {
            if ($id===null) { $data['client_id']=$clientId; $data['created_at']=$data['updated_at']; $this->db->table($table)->insert($data); $id=(int)$this->db->insertID(); }
            else { $this->db->table($table)->where(['id'=>$id,'client_id'=>$clientId])->update($data); }
            $this->touch($clientId); return $id;
        });
    }
    public function removeChild(User $user, int $clientId, string $kind, int $id): void
    {
        $this->writable($user,$clientId); $this->child($user,$clientId,$kind,$id);
        $this->write(function () use($kind,$id,$clientId) { $this->db->table($this->childTable($kind))->where(['id'=>$id,'client_id'=>$clientId])->delete(); $this->touch($clientId); });
    }
    private function touch(int $id): void { $this->db->table('clients')->where('id',$id)->update(['updated_at'=>date('Y-m-d H:i:s')]); }
    public function saveLookup(User $user, string $kind, array $input, ?int $id = null): void
    {
        $this->policy->require($user,'lookups');
        $table=match($kind){'groups'=>'client_groups','statuses'=>'client_statuses',default=>throw PageNotFoundException::forPageNotFound()};
        $name=$this->input($input,'name'); $this->validate(['name'=>$name],['name'=>'required|max_length[80]']);
        $key=mb_strtolower($name); $duplicate=$this->db->table($table)->where('name_key',$key); if ($id) {$duplicate->where('id !=',$id);}
        if ($duplicate->countAllResults()) { throw new ClientValidationException(['name'=>'This name already exists.']); }
        if ($id && !$this->db->table($table)->where('id',$id)->countAllResults()) { throw PageNotFoundException::forPageNotFound(); }
        $this->write(function() use($table,$name,$key,$id){
            $data=['name'=>$name,'name_key'=>$key,'updated_at'=>date('Y-m-d H:i:s')];
            if ($id) {$this->db->table($table)->where('id',$id)->update($data);} else {$data['created_at']=$data['updated_at'];$this->db->table($table)->insert($data);}
        });
    }
}
