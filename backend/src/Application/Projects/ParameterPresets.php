<?php
declare(strict_types=1);

namespace Chronos\Application\Projects;

use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;

/** Project-shared parameter drafts, stored outside the public document root. */
final class ParameterPresets
{
    private const MAX_BYTES = 4194304;
    private readonly string $root;

    public function __construct(string $root,private readonly int $projectId)
    {
        $this->root=realpath($root)?:throw new HttpError(503,'preset_storage_unavailable','Parameter preset storage is unavailable.');
        if($projectId<1)throw new \InvalidArgumentException('Use a positive project ID.');
    }

    public static function schemaHash(array $groups): string
    {
        $canonical=function(mixed $value)use(&$canonical):mixed{if(!is_array($value))return$value;if(!array_is_list($value))ksort($value,SORT_STRING);return array_map($canonical,$value);};
        return hash('sha256',json_encode($canonical($groups),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public function listing(string $schemaHash): array
    {
        $items=$this->read();
        usort($items,static fn(array$a,array$b):int=>strnatcasecmp($a['name'],$b['name'])?:strcmp($a['id'],$b['id']));
        return ['items'=>array_map(static fn(array$item):array=>$item+['compatible'=>hash_equals($schemaHash,$item['schemaHash'])],$items),'schemaHash'=>$schemaHash];
    }

    public function save(?string $id,array $body,string $schemaHash,int $actorId): array
    {
        Input::fields($body,$id===null?['name','values','schemaHash']:['name','values','schemaHash','revision']);
        $name=Input::text($body['name']??null,'name',100);
        $values=self::values($body['values']??null);
        if(!is_string($body['schemaHash']??null)||!hash_equals($schemaHash,$body['schemaHash']))throw new HttpError(409,'schema_changed','The system parameter schema changed. Reload it before saving this preset.');
        if($actorId<1)throw new \InvalidArgumentException('Use a verified actor ID.');
        return $this->mutate(function(array &$items)use($id,$body,$name,$values,$schemaHash,$actorId):array{
            $index=$id===null?null:$this->find($items,$id);
            if($index!==null)$this->revision($items[$index],$body['revision']??null);
            foreach($items as$i=>$item)if($i!==$index&&mb_strtolower($item['name'],'UTF-8')===mb_strtolower($name,'UTF-8'))throw new HttpError(409,'preset_name_conflict','A preset with this name already exists in the project.');
            if($index===null&&count($items)>=100)throw new HttpError(422,'preset_limit','A project can contain at most 100 parameter presets.');
            $at=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Zurich')))->format(DATE_ATOM);
            $item=['id'=>$id??bin2hex(random_bytes(16)),'name'=>$name,'values'=>$values,'schemaHash'=>$schemaHash,'revision'=>bin2hex(random_bytes(16)),
                'createdAt'=>$index===null?$at:$items[$index]['createdAt'],'updatedAt'=>$at,'createdBy'=>$index===null?$actorId:$items[$index]['createdBy'],'updatedBy'=>$actorId];
            if($index===null)$items[]=$item;else$items[$index]=$item;
            return$item+['compatible'=>true];
        });
    }

    public function delete(string $id,mixed $revision): array
    {
        return $this->mutate(function(array &$items)use($id,$revision):array{$index=$this->find($items,$id);$this->revision($items[$index],$revision);array_splice($items,$index,1);return['deleted'=>true];});
    }

    private function find(array $items,string $id): int
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new HttpError(404,'not_found','The parameter preset does not exist.');
        foreach($items as$i=>$item)if($item['id']===$id)return$i;
        throw new HttpError(404,'not_found','The parameter preset does not exist.');
    }

    private function revision(array $item,mixed $revision): void
    {
        if(!is_string($revision)||!hash_equals($item['revision'],$revision))throw new HttpError(409,'preset_changed','This preset changed in another session. Reload it before saving or deleting.');
    }

    private static function values(mixed $values): array
    {
        if(!is_array($values)||($values!==[]&&array_is_list($values))||count($values)>1000)Input::invalid('values','Use a parameter value object of at most 1000 fields.');
        foreach($values as$key=>$value){
            if(!is_string($key)||$key===''||strlen($key)>256||!mb_check_encoding($key,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$key))Input::invalid('values','A parameter field name is invalid.');
            $list=is_array($value)?$value:[$value];
            if(is_array($value)&&(!array_is_list($value)||count($value)>1000))Input::invalid('values.'.$key,'Use scalar values or a list of choices.');
            foreach($list as$entry)if($entry!==null&&!is_bool($entry)&&!is_int($entry)&&!(is_float($entry)&&is_finite($entry))&&!(is_string($entry)&&strlen($entry)<=16384&&mb_check_encoding($entry,'UTF-8')&&!str_contains($entry,"\0")))Input::invalid('values.'.$key,'Use finite scalar values or a list of choices.');
        }
        if(strlen(json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>65535)Input::invalid('values','The parameter draft exceeds the experiment storage limit.');
        return$values;
    }

    private function directory(bool $create): ?string { return self::storageDirectory($this->root,$create); }

    public static function storageDirectory(string $root,bool $create): ?string
    {
        $path=realpath($root)?:throw new HttpError(503,'preset_storage_unavailable','Parameter preset storage is unavailable.');
        foreach(['data','presets']as$part){$path.='/'.$part;if(is_link($path)||(file_exists($path)&&!is_dir($path)))throw new HttpError(503,'preset_storage_unavailable','Parameter preset storage has an unsafe path.');if(!is_dir($path)){if(!$create)return null;if(!mkdir($path,0700))throw new HttpError(503,'preset_storage_unavailable','Parameter preset storage could not be created.');}}
        return$path;
    }

    private function read(): array
    {
        $directory=$this->directory(false);if($directory===null)return[];
        $file=$directory.'/'.$this->projectId.'.json';
        if(is_link($file)||(file_exists($file)&&(!is_file($file)||filesize($file)>self::MAX_BYTES)))throw new HttpError(503,'preset_storage_unavailable','Parameter preset data is invalid.');
        if(!file_exists($file))return[];
        return self::decode((string)file_get_contents($file));
    }

    public static function validateDocument(string $json): void { self::decode($json); }

    private static function decode(string $json): array
    {
        try{if(strlen($json)>self::MAX_BYTES)throw new \RuntimeException();$document=json_decode($json,true,32,JSON_THROW_ON_ERROR);if(!is_array($document)||($document['format']??null)!==1||!is_array($document['items']??null)||!array_is_list($document['items'])||count($document['items'])>100)throw new \RuntimeException();$ids=[];
            foreach($document['items']as$item){if(is_array($item))Input::fields($item,['id','name','values','schemaHash','revision','createdAt','updatedAt','createdBy','updatedBy']);if(!is_array($item)||!preg_match('/^[a-f0-9]{32}$/D',$item['id']??'')||isset($ids[$item['id']])||!is_string($item['name']??null)||!preg_match('/^[a-f0-9]{64}$/D',$item['schemaHash']??'')||!preg_match('/^[a-f0-9]{32}$/D',$item['revision']??'')||!is_int($item['createdBy']??null)||!is_int($item['updatedBy']??null)||!is_string($item['createdAt']??null)||!is_string($item['updatedAt']??null))throw new \RuntimeException();$ids[$item['id']]=true;Input::text($item['name'],'name',100);self::values($item['values']??null);}
            return$document['items'];
        }catch(\Throwable){throw new HttpError(503,'preset_storage_unavailable','Parameter preset data is invalid. Restore the private preset backup before editing.');}
    }

    private function mutate(callable $change): array
    {
        $directory=$this->directory(true);$path=$directory.'/'.$this->projectId.'.json';$lockPath=$directory.'/'.$this->projectId.'.lock';
        if(is_link($lockPath)||(file_exists($lockPath)&&!is_file($lockPath)))throw new HttpError(503,'preset_storage_unavailable','Parameter preset locking is unavailable.');
        $lock=fopen($lockPath,'c');if($lock===false||!flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);throw new HttpError(503,'preset_storage_unavailable','Parameter preset storage is busy.');}
        $temporary=null;
        try{$items=$this->read();$result=$change($items);$json=json_encode(['format'=>1,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>self::MAX_BYTES)throw new HttpError(422,'preset_limit','The project preset collection exceeds its storage limit.');$temporary=tempnam($directory,'.preset-');if($temporary===false||file_put_contents($temporary,$json)!==strlen($json)||!chmod($temporary,0600)||!rename($temporary,$path))throw new HttpError(503,'preset_storage_unavailable','Parameter presets could not be saved.');$temporary=null;return$result;}
        finally{if($temporary!==null&&is_file($temporary))unlink($temporary);flock($lock,LOCK_UN);fclose($lock);}
    }
}
