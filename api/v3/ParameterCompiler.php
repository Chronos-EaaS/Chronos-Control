<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** Compile trusted schema + editable values into the unchanged agent job format. */
final class ParameterCompiler
{
    private const TYPES = ['boolean','checkbox-selection','dropdown','integer','interval','percentage','percentage-interval','percentage-dep','ratio','software-versions-list','string-list','string'];

    public static function values(array $groups, mixed $input): array
    {
        if(!is_array($input)){Input::invalid('parameters','Provide parameter values as an object.');}
        if(isset($input['groups'],$input['values'])){$input=$input['values'];if(!is_array($input)){Input::invalid('parameters','Provide parameter values as an object.');}}
        $values=$input;
        foreach($values as$key=>$value){if(!is_string($key)||strlen($key)>256||(!is_scalar($value)&&$value!==null&&(!is_array($value)||!array_is_list($value)))){Input::invalid('parameters','Use scalar values or a list of choices.');}if(is_string($value)&&(!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")||strlen($value)>16384)){Input::invalid('parameters','A parameter value is invalid or too long.');}}
        foreach($groups as$group){foreach($group['elements']as$e){$p=$e['parameter'];$id=$e['id'];$old=is_string($input[$id.'-parameter']??null)?$input[$id.'-parameter']:$p;
            $set=static function(string$suffix,mixed$fallback)use(&$values,$input,$p,$old):void{if(!array_key_exists($p.$suffix,$values)){$values[$p.$suffix]=$input[$old.$suffix]??$fallback;}};
            switch($e['type']){
                case'checkbox-selection':$defaults=self::csv($e['default']??'');$copied=array_key_exists($id.'-parameter',$input);foreach(self::csv($e['options']??'')as$option){$set('-'.$option,$copied?false:(in_array($option,$defaults,true)?'on':false));}break;
                case'software-versions-list':$oldVersions=self::csv($input[$old]??'');$set('-numberOfVersions',count($oldVersions)?:1);$count=Input::integer($values[$p.'-numberOfVersions'],'parameters.'.$p,1,1000);for($i=0;$i<$count;$i++){$set('-version-'.$i,$oldVersions[$i]??'');}break;
                case'interval':foreach(['min','max','step']as$suffix){$set('-'.$suffix,$e['default-'.$suffix]??'');}break;
                case'ratio':foreach(['start','end','step']as$suffix){$set('-'.$suffix,$e['default-'.$suffix]??'');}break;
                case'percentage-interval':foreach(['start','end','step']as$suffix){$set('-percentage-'.$suffix,$e['default-'.$suffix]??'');}break;
                case'percentage':case'percentage-dep':$set('-percentage',$e['default']??'');break;
                case'dropdown':$options=self::csv($e['options']??'');$set('', $e['default']??($options[0]??''));break;
                default:$set('',$e['default']??'');
            }
        }}
        return $values;
    }

    public static function csv(mixed $input): array
    {
        if(is_array($input)){return array_values(array_map('strval',$input));}
        return is_scalar($input)&&trim((string)$input)!==''?array_map('trim',explode(',',(string)$input)):[];
    }

    /** Disabled groups stay in the draft while their fields are absent here. */
    public static function legacy(array $groups,array $values):array
    {
        $owner=[];foreach($groups as$i=>$g){foreach($g['elements']as$e){$owner[$e['parameter']]=$i;foreach($values as$key=>$value){if(str_starts_with($key,$e['parameter'].'-')){$owner[$key]=$i;}}}}
        $shown=[];$visiting=[];
        $visible=function(int$i)use(&$visible,&$shown,&$visiting,$owner,$groups,$values):bool{
            if(array_key_exists($i,$shown)){return$shown[$i];}if(isset($visiting[$i])){Input::invalid('parameters','Conditional parameter groups contain a cycle.');}$visiting[$i]=true;$g=$groups[$i];$dep=$g['depends']??'';
            $answer=$dep===''||((!isset($owner[$dep])||$visible($owner[$dep]))&&array_key_exists($dep,$values)&&self::scalar($values[$dep])===self::scalar($g['dependsValue']??''));unset($visiting[$i]);return$shown[$i]=$answer;
        };
        $data=['elements'=>[],'_v3Elements'=>[]];
        foreach($groups as$i=>$group){if(!$visible($i)){continue;}foreach($group['elements']as$e){$type=$e['type'];if(!in_array($type,self::TYPES,true)){Input::invalid('parameters','The custom parameter type '.$type.' requires an installed v3 adapter.');}$id=$e['id'];$p=$e['parameter'];$data['elements'][]=$id;$data['_v3Elements'][]=['id'=>$id,'type'=>$type,'parameter'=>$p];$data[$id.'-type']=$type;$data[$id.'-parameter']=$p;
            foreach($values as$key=>$value){if($key===$p||str_starts_with($key,$p.'-')){$data[$key]=$value;}}
            if($type==='dropdown'&&!in_array(self::scalar($values[$p]??''),self::csv($e['options']??''),true)){Input::invalid('parameters.'.$p,'Choose an available option.');}
            if($type==='checkbox-selection'){foreach(array_keys($data)as$key){if(str_starts_with($key,$p.'-')){unset($data[$key]);}}foreach(self::csv($e['options']??'')as$option){$key=$p.'-'.$option;if(($values[$key]??false)===true||($values[$key]??'')==='on'){$data[$key]='on';}else{unset($data[$key]);}}}
            if(in_array($type,['percentage','percentage-dep','percentage-interval'],true)){$dep=$e['dependency']??'';if(!is_string($dep)||$dep===''){Input::invalid('parameters','A percentage dependency is missing.');}$data[$p.'-dependency']=$dep;$data['dependency-'.$dep]=$p;$data[$p.'-type']=($e['parameter-type']??'int')==='float'?'float':'int';}
        }}
        return$data;
    }

    private static function scalar(mixed$value):string
    {
        return is_bool($value)?($value?'true':'false'):(is_scalar($value)?(string)$value:'');
    }

    private static function number(mixed$value,string$field,bool$integer=false):float|int
    {
        if((!is_string($value)&&!is_int($value)&&!is_float($value))||!is_numeric($value)||!is_finite((float)$value)||abs((float)$value)>1e12||($integer&&(float)$value!==(float)(int)$value)){Input::invalid('parameters.'.$field,'Enter a finite'.($integer?' whole':'').' number.');}
        return$integer?(int)$value:(float)$value;
    }

    private static function rangeFactor(array&$data,string$p,string$first,string$last,int$limit):int
    {
        $a=self::number($data[$p.'-'.$first]??null,$p.'-'.$first,true);$b=self::number($data[$p.'-'.$last]??null,$p.'-'.$last,true);$step=self::number($data[$p.'-step']??null,$p.'-step',true);
        if($a!==$b&&($step===0||($b-$a)*$step<0)){Input::invalid('parameters.'.$p.'-step','Choose a nonzero step toward the end value.');}
        if($a===$b){$step=1;}if($a>$b){[$a,$b]=[$b,$a];$step=abs($step);}
        $factor=(int)floor(($b-$a)/$step)+1;if($factor<1||$factor>$limit){Input::invalid('parameters','This interval exceeds the maximum number of jobs.');}$data[$p.'-'.$first]=$a;$data[$p.'-'.$last]=$b;$data[$p.'-step']=$step;return$factor;
    }

    /** Bounded before every expansion; never invoke untrusted system PHP. */
    public static function configurations(string$root,array$data,int$limit):array
    {
        $limit=max(1,min($limit,100000));$runs=Input::integer($data['runs']??1,'runs',1,$limit);$distribution=$data['run-distribution']??'alter';if(!in_array($distribution,['order','alter','rand'],true)){Input::invalid('distribution','Choose a supported run distribution.');}
        $entries=[];$singles=[];
        foreach($data['_v3Elements']??array_map(static fn($id)=>['id'=>$id,'type'=>$data[$id.'-type']??null,'parameter'=>$data[$id.'-parameter']??null],$data['elements']??[])as$spec){$type=$spec['type']??null;$p=$spec['parameter']??null;if(!is_string($type)||!in_array($type,self::TYPES,true)||!is_string($p)||$p===''){Input::invalid('parameters','The experiment contains an unsupported parameter.');}$entry=['type'=>$type,'parameter'=>$p,'directory'=>$type==='software-versions-list'?'software-version-list':$type];if(in_array($type,['boolean','dropdown','integer','percentage','percentage-dep','string'],true)){$singles[]=$entry;}else{$entries[]=$entry;}}
        // Percentage inputs are derived on the server, independent of previews.
        foreach(array_merge($entries,$singles)as$e){$p=$e['parameter'];$type=$e['type'];if($type==='percentage'||$type==='percentage-interval'){$dep=$data[$p.'-dependency']??null;$base=self::number(is_string($dep)?($data[$dep]??null):null,$p.' dependency');$convert=static function(mixed$v)use($base,$data,$p):float|int{$value=$base*self::number($v,$p.' percentage')/100;return($data[$p.'-type']??'int')==='float'?$value:(int)floor($value);};if($type==='percentage'){$data[$p]=$convert($data[$p.'-percentage']??null);}else{foreach(['start','end','step']as$s){$data[$p.'-'.$s]=$convert($data[$p.'-percentage-'.$s]??null);}}}}
        $configurations=[['parameters'=>[]]];$repeat=static function(array$configs,int$count):array{$out=[];for($i=0;$i<$count;$i++){foreach($configs as$config){$config['parameters']['run']=$i;$out[]=$config;}}return$out;};
        if($distribution==='order'){$configurations=$repeat($configurations,$runs);}
        foreach($entries as$e){$p=$e['parameter'];$type=$e['type'];$factor=1;
            switch($type){
                case'interval':$factor=self::rangeFactor($data,$p,'min','max',$limit);break;
                case'percentage-interval':$factor=isset($configurations[0][($data[$p.'-dependency']??'').'-step'])?1:self::rangeFactor($data,$p,'start','end',$limit);break;
                case'string-list':$data[$p]=Input::text($data[$p]??null,'parameters.'.$p,16384,false);$factor=count(explode(',',$data[$p]));break;
                case'checkbox-selection':$factor=0;foreach($data as$key=>$value){if(str_starts_with($key,$p.'-')&&$value==='on'){$factor++;}}break;
                case'software-versions-list':$factor=Input::integer($data[$p.'-numberOfVersions']??null,'parameters.'.$p,1,1000);for($i=0;$i<$factor;$i++){$data[$p.'-version-'.$i]=Input::text($data[$p.'-version-'.$i]??null,'parameters.'.$p,256);}break;
                case'ratio':$start=explode(':',str_replace('%','',self::scalar($data[$p.'-start']??'')));$end=explode(':',str_replace('%','',self::scalar($data[$p.'-end']??'')));if(count($start)!==2||count($end)!==2||count(explode(',',$p))!==2){Input::invalid('parameters.'.$p,'Enter two percentages totalling 100.');}$a=self::number($start[0],$p,true);$b=self::number($start[1],$p,true);$c=self::number($end[0],$p,true);$d=self::number($end[1],$p,true);$step=self::number(str_replace('%','',self::scalar($data[$p.'-step']??'')),$p,true);if($a+$b!==100||$c+$d!==100||min($a,$b,$c,$d)<0||$step<=0||$step>100||$c<$a){Input::invalid('parameters.'.$p,'Enter an increasing ratio range with a positive step.');}$factor=(int)floor(($c-$a)/$step)+1;break;
            }
            if($factor<1){Input::invalid('parameters.'.$p,'Select at least one value.');}
            $reserve=$distribution==='order'?1:$runs;if($factor>intdiv($limit,max(1,count($configurations)*$reserve))){Input::invalid('parameters','This evaluation exceeds the configured maximum number of jobs.');}
            try{(new \Element($root.'/libraries/elements/'.$e['directory']))->process($data,$p,$configurations);}catch(\Exception){Input::invalid('parameters.'.$p,'This parameter configuration cannot generate jobs.');}
            if(count($configurations)>$limit){Input::invalid('parameters','This evaluation exceeds the configured maximum number of jobs.');}
        }
        foreach($singles as$e){$p=$e['parameter'];if($e['type']==='integer'){$data[$p]=self::number($data[$p]??null,$p,true);}elseif($e['type']==='boolean'){$value=self::scalar($data[$p]??'');if(!in_array($value,['true','false','1','0'],true)){Input::invalid('parameters.'.$p,'Choose true or false.');}$data[$p]=in_array($value,['true','1'],true)?'true':'false';}elseif($e['type']==='percentage-dep'){self::number($data[$p.'-percentage']??null,$p);}else{$data[$p]=Input::text(self::scalar($data[$p]??''),'parameters.'.$p,16384,false);}
            try{(new \Element($root.'/libraries/elements/'.$e['directory']))->process($data,$p,$configurations);}catch(\Exception){Input::invalid('parameters.'.$p,'This parameter configuration cannot generate jobs.');}
        }
        if($distribution!=='order'){$configurations=$repeat($configurations,$runs);if($distribution==='rand'){shuffle($configurations);}}
        if($configurations===[]||count($configurations)>$limit){Input::invalid('parameters','Choose at least one job within the configured limit.');}
        return$configurations;
    }
}
