<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

final class PresetRoutes
{
    private readonly Context $context;
    public function __construct(private readonly Request $request,private readonly Store $db,private readonly array $actor,private readonly string $root,private readonly Sessions $sessions){$this->context=new Context($db,$actor,$root);}

    public function handle(string $path): ?array
    {
        if(!preg_match('#^/projects/([1-9][0-9]*)/parameter-presets(?:/([a-f0-9]{32}))?$#D',$path,$match))return null;
        $id=Input::id($match[1],'projectId');$project=$this->context->project($id);
        $this->request->assertMethod(isset($match[2])?['PUT','DELETE']:['GET','POST']);
        $store=new ParameterPresets($this->root,$id);
        $schemaHash=ParameterPresets::schemaHash(SystemRoutes::parameterGroups($this->root,(int)$project['systemId']));
        if($this->request->method==='GET'){$listing=$store->listing($schemaHash);$listing['items']=array_map(self::dto(...),$listing['items']);return Context::success($listing);}
        $body=$this->request->json();
        $result=$this->db->transaction(function()use($id,$body,$match,$store,$schemaHash):array{
            $this->context->project($id,true);$this->sessions->requireUser();
            if($this->request->method==='DELETE'){Input::fields($body,['revision']);return$store->delete($match[2],$body['revision']??null);}
            return self::dto($store->save($match[2]??null,$body,$schemaHash,(int)$this->actor['id']));
        });
        return Context::success($result,$this->request->method==='POST'?201:200);
    }

    private static function dto(array $item): array { $item['values']=(object)$item['values'];return$item; }
}
