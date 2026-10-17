<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** Data-only render contracts; optional server processors require deployment trust. */
final class Extensions
{
    public const PARAMETER_TYPES = ['boolean','checkbox-selection','dropdown','integer','interval','percentage','percentage-interval','percentage-dep','ratio','software-versions-list','string-list','string'];
    public const PLOT_TYPES = ['bar-plot'=>'bar','line-plot'=>'line','line-plot-multi'=>'line','pie-plot'=>'pie','box-plot'=>'boxplot'];
    private const PLOT_DIRECTORIES = ['bar-plot'=>'bar-plot','line-plot'=>'line-plot','line-plot-multi'=>'line-plot-multi','pie-plot'=>'pie-plot','box-plot'=>'boxplot'];
    private array $parameters = [];
    private array $plots = [];
    private array $pages = [];
    private array $diagnostics = [];
    private readonly string $directory;
    private readonly bool $trusted;

    public function __construct(private readonly string $root, private readonly int $systemId, ?array $trustedSystems = null)
    {
        $base = realpath($root . '/webroot/systems');
        $path = $root . '/webroot/systems/' . $systemId;
        $resolved = realpath($path);
        if ($systemId < 1 || $base === false || is_link($root . '/webroot') || is_link($root . '/webroot/systems') || is_link($path) || $resolved === false || dirname($resolved) !== $base) {
            throw new HttpError(409, 'system_files_unavailable', 'The isolated system directory is unavailable.');
        }
        $this->directory = $resolved;
        $allowlist = $trustedSystems ?? (defined('CHRONOS_TRUSTED_EXTENSION_SYSTEMS') ? CHRONOS_TRUSTED_EXTENSION_SYSTEMS : []);
        $this->trusted = is_array($allowlist) && in_array($systemId, $allowlist, true);
        try {
            $document = $this->readJson('extensions.v3.json');
            if ($document !== null) $this->load($document);
        } catch (\Throwable) {
            $this->diagnostics[] = ['code'=>'invalid_extension_manifest','kind'=>'manifest','message'=>'Repair extensions.v3.json using the v3 data contract. No extension code was loaded.'];
        }
        $this->discoverLegacy();
    }

    public static function capabilities(): array
    {
        return ['format'=>1,'parameterBaseTypes'=>self::PARAMETER_TYPES,'plotBaseTypes'=>array_keys(self::PLOT_TYPES),'pageBlocks'=>['text','metrics','table','chart'],'trustedProcessors'=>true];
    }

    public function catalog(bool $owner = false): array
    {
        return ['format'=>1,'aliases'=>['parameters'=>array_map(static fn(array $entry): string=>$entry['baseType'],array_filter($this->parameters,static fn(array $entry): bool=>$entry['status']==='ready'))?:new \stdClass(),'plots'=>array_map(static fn(array $entry): string=>$entry['baseType'],array_filter($this->plots,static fn(array $entry): bool=>$entry['status']==='ready'))?:new \stdClass()],'parameters'=>array_values($this->parameters),'plots'=>array_values($this->plots),
            'pages'=>array_values(array_map(static fn(array $page): array => array_intersect_key($page,array_flip(['id','title','description','access','status','message'])),array_filter($this->pages,static fn(array $page): bool => $owner || $page['access']==='members'))),
            'diagnostics'=>$this->diagnostics];
    }

    public function parameterBase(string $type): string
    {
        if (in_array($type,self::PARAMETER_TYPES,true)) return $type;
        return $this->ready($this->parameters,$type,'parameter')['baseType'];
    }

    public function plotBase(string $type): string
    {
        if (isset(self::PLOT_TYPES[$type])) return $type;
        return $this->ready($this->plots,$type,'plot')['baseType'];
    }

    private function ready(array $entries,string $type,string $kind): array
    {
        $descriptor = $entries[$type] ?? null;
        if ($descriptor === null || $descriptor['status'] !== 'ready') {
            throw new HttpError(422,'extension_unavailable','The custom '.$kind.' '.$type.' needs a ready v3 adapter. Its stored definition has been preserved.');
        }
        return $descriptor;
    }

    private function load(mixed $document): void
    {
        if (!is_array($document) || ($document['format']??null)!==1) throw new \InvalidArgumentException();
        self::keys($document,['format','parameters','plots','pages']);
        $parameters=$plots=$pages=[];
        foreach (['parameters','plots','pages'] as $kind) {
            $list=$document[$kind]??[];
            if (!is_array($list)||!array_is_list($list)||count($list)>100) throw new \InvalidArgumentException();
            foreach ($list as $entry) {
                if (!is_array($entry)) throw new \InvalidArgumentException();
                if ($kind==='pages') {
                    self::keys($entry,['id','title','description','access','blocks','provider']);
                    $id=self::id($entry['id']??null); if(isset($pages[$id]))throw new \InvalidArgumentException();
                    $page=['id'=>$id,'title'=>self::text($entry['title']??null,256),'description'=>self::text($entry['description']??'',4096),'access'=>$entry['access']??'owner','blocks'=>self::blocks($entry['blocks']??[])];
                    if(!in_array($page['access'],['owner','members'],true))throw new \InvalidArgumentException();
                    $provider=$entry['provider']??null;
                    if($provider!==null&&$provider!=='pages/'.$id.'/data.php')throw new \InvalidArgumentException();
                    $page['provider']=$provider;
                    $page+=['status'=>'ready','message'=>null];
                    if($provider!==null) $this->processorState($page,$provider);
                    $pages[$id]=$page;
                    continue;
                }
                self::keys($entry,['type','name','baseType','processor']);
                $type=self::id($entry['type']??null);$parameter=$kind==='parameters';
                $builtins=$parameter?self::PARAMETER_TYPES:array_keys(self::PLOT_TYPES);
                if(in_array($type,$builtins,true)||!in_array($entry['baseType']??null,$builtins,true)||isset(($parameter?$parameters:$plots)[$type]))throw new \InvalidArgumentException();
                $baseType=$entry['baseType'];
                $processor=$entry['processor']??null;
                if($processor!==null&&$processor!==($parameter?'parameters/':'results/').$type.'/process.php')throw new \InvalidArgumentException();
                $descriptor=['type'=>$type,'name'=>self::text($entry['name']??null,256),'baseType'=>$baseType,'trustedProcessor'=>$processor!==null,'status'=>'ready','message'=>null];
                if(!$parameter)$descriptor['renderer']=self::PLOT_TYPES[$baseType];
                if($processor!==null)$this->processorState($descriptor,$processor);
                if($parameter)$parameters[$type]=$descriptor;else$plots[$type]=$descriptor;
            }
        }
        $this->parameters=$parameters;$this->plots=$plots;$this->pages=$pages;
    }

    private function processorState(array &$descriptor,string $path): void
    {
        if(!$this->trusted){$descriptor['status']='blocked';$descriptor['message']='A deployment administrator must allow this system in CHRONOS_TRUSTED_EXTENSION_SYSTEMS before its server processor can run.';return;}
        try{$this->file($path,true,262144);}catch(\Throwable){$descriptor['status']='blocked';$descriptor['message']='The installed server processor is missing or has an unsafe path.';}
    }

    private static function keys(array $value,array $allowed): void
    {
        if(array_diff(array_keys($value),$allowed)!==[])throw new \InvalidArgumentException('Unsupported extension fields.');
    }

    private static function id(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$value))throw new \InvalidArgumentException('Use a stable lowercase extension identifier.');
        return $value;
    }

    private static function text(mixed $value,int $limit): string
    {
        if(!is_string($value)||strlen($value)>$limit||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Invalid extension text.');
        return $value;
    }

    /** Every component is checked, including intermediate directories. */
    private function file(string $relative,bool $required=false,int $limit=1048576): ?string
    {
        $path=$this->directory;
        foreach(explode('/',$relative)as$part){if($part===''||$part==='.'||$part==='..'||str_contains($part,'\\'))throw new \InvalidArgumentException();$path.='/'.$part;if(is_link($path))throw new \RuntimeException('Linked extension files are forbidden.');}
        if(!file_exists($path)){if($required)throw new \RuntimeException('Extension file is missing.');return null;}
        if(!is_file($path)||!is_readable($path)||filesize($path)>$limit||!str_starts_with(realpath($path)?:'',$this->directory.'/'))throw new \RuntimeException('Unsafe extension file.');
        return $path;
    }

    private function readJson(string $relative): mixed
    {
        $file=$this->file($relative);
        return $file===null?null:json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    }

    private function discoverLegacy(): void
    {
        $unknown=[];
        try{foreach($this->readJson('parameters.json')??[] as$group){foreach($group['elements']??[]as$e){$type=$e['type']??null;if(is_string($type)&&!in_array($type,self::PARAMETER_TYPES,true)&&!isset($this->parameters[$type]))$unknown['parameter:'.$type]=['code'=>'unregistered_parameter','kind'=>'parameter','type'=>$type,'message'=>'Register this saved type in extensions.v3.json before editing or generating jobs.'];}}}catch(\Throwable){}
        try{$doc=$this->readJson('resultConfigurations.json');$configs=$doc['elements']??[];if(!$doc)$configs=[['all'=>$this->readJson('results.json')??[],'job'=>$this->readJson('resultsJob.json')??[]]];foreach($configs as$config){foreach(['all','job']as$scope)foreach($config[$scope]??[]as$plot){$type=$plot['type']??null;if(is_string($type)&&!isset(self::PLOT_TYPES[$type])&&!isset($this->plots[$type]))$unknown['plot:'.$type]=['code'=>'unregistered_plot','kind'=>'plot','type'=>$type,'message'=>'Register this saved plot in extensions.v3.json to render its retained definition.'];}}}catch(\Throwable){}
        $count=0;
        foreach(new \DirectoryIterator($this->directory)as$entry){if(++$count>1000)break;if($entry->isDot()||$entry->isLink())continue;if($entry->isFile()&&str_ends_with(strtolower($entry->getFilename()),'.php'))$unknown['page:'.$entry->getFilename()]=['code'=>'legacy_page','kind'=>'page','path'=>$entry->getFilename(),'message'=>'Port this PHP page to a data-only v3 page descriptor. The legacy UI file is not executed.'];if($entry->getFilename()==='system_assets')$unknown['assets']=['code'=>'legacy_assets','kind'=>'page','path'=>'system_assets','message'=>'Move legacy UI assets to the v3 data-only page contract.'];}
        foreach(array_slice(array_values($unknown),0,200)as$diagnostic)$this->diagnostics[]=$diagnostic;
    }

    /** Same data variable names as installed legacy processors, without UI templates. */
    public function processParameter(string $type,array $data,string $parameter,array &$allConfigurations,int $limit): void
    {
        $descriptor=$this->ready($this->parameters,$type,'parameter');
        if($descriptor['trustedProcessor']){
            $path=$this->file('parameters/'.$type.'/process.php',true,262144);
            self::quiet(static function()use($path,$data,$parameter,&$allConfigurations):void{include $path;});
        }else{
            $directory=$descriptor['baseType']==='software-versions-list'?'software-version-list':$descriptor['baseType'];
            (new \Element($this->root.'/libraries/elements/'.$directory))->process($data,$parameter,$allConfigurations);
        }
        if(!array_is_list($allConfigurations)||!$allConfigurations||count($allConfigurations)>$limit)throw new HttpError(422,'invalid_extension_output','The extension exceeded the configured job limit or returned invalid configurations.');
        foreach($allConfigurations as$configuration){if(!is_array($configuration)||!is_array($configuration['parameters']??null))throw new HttpError(422,'invalid_extension_output','The extension returned an invalid job configuration.');self::jsonValue($configuration);}
    }

    public function plotData(string $type,array $jobs,array $p): array
    {
        $descriptor=$this->ready($this->plots,$type,'plot');
        if($descriptor['trustedProcessor']){
            $path=$this->file('results/'.$type.'/process.php',true,262144);
            $result=self::quiet(static function()use($path,$jobs,$p):mixed{$parameter=$p['parameter']??'';$allData=$p;if(is_string($parameter)&&str_contains($parameter,','))$parameter=explode(',',$parameter);$plotData='{}';include $path;return$plotData;});
            $result=is_string($result)?json_decode($result,true,32,JSON_THROW_ON_ERROR):$result;
        }else{$plot=new \Plot($this->root.'/libraries/graphs/'.self::PLOT_DIRECTORIES[$descriptor['baseType']]);$result=json_decode($plot->process($jobs,$p),true,32,JSON_THROW_ON_ERROR);}
        return self::chartData($result);
    }

    public function page(string $id,bool $owner,array $context=[]): array
    {
        $page=$this->pages[$id]??throw new HttpError(404,'not_found','The extension page does not exist.');
        Policy::require($owner||$page['access']==='members');
        if($page['status']!=='ready')throw new HttpError(409,'extension_unavailable',$page['message']);
        $blocks=$page['blocks'];
        if($page['provider']!==null){$path=$this->file($page['provider'],true,262144);$result=self::quiet(static function()use($path,$context):mixed{return include $path;});try{$blocks=self::blocks($result);}catch(\InvalidArgumentException){throw new HttpError(422,'invalid_extension_output','The page provider returned an invalid data-only block.');}}
        return ['id'=>$page['id'],'title'=>$page['title'],'description'=>$page['description'],'blocks'=>$blocks];
    }

    private static function quiet(callable $processor): mixed
    {
        $level=ob_get_level();ob_start();
        try{$result=$processor();$output=ob_get_contents();if($output!==false&&$output!=='')throw new HttpError(422,'invalid_extension_output','Server extension processors must return data without printing UI content.');return$result;}
        finally{while(ob_get_level()>$level)ob_end_clean();}
    }

    public static function chartData(mixed $data): array
    {
        if(!is_array($data)||!is_array($data['labels']??null)||!array_is_list($data['labels'])||count($data['labels'])>10000||!is_array($data['datasets']??null)||!array_is_list($data['datasets'])||count($data['datasets'])>1000)throw new HttpError(422,'invalid_extension_output','A chart requires bounded labels and datasets.');
        $result=['labels'=>[],'datasets'=>[]];
        foreach($data['labels']as$label){if(!is_scalar($label))throw new \InvalidArgumentException();$result['labels'][]=self::text((string)$label,4096);}
        foreach($data['datasets']as$dataset){if(!is_array($dataset)||!is_array($dataset['data']??null)||!array_is_list($dataset['data'])||count($dataset['data'])>10000)throw new \InvalidArgumentException();$item=['label'=>self::text((string)($dataset['label']??''),4096),'data'=>$dataset['data']];self::jsonValue($item['data']);foreach(['backgroundColor','borderColor','borderWidth','fill']as$key)if(isset($dataset[$key])){self::jsonValue($dataset[$key]);$item[$key]=$dataset[$key];}$result['datasets'][]=$item;}
        if(isset($data['notEnoughRuns']))$result['notEnoughRuns']=(bool)$data['notEnoughRuns'];
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>1048576)throw new HttpError(422,'invalid_extension_output','The chart output is too large.');
        return$result;
    }

    public static function blocks(mixed $blocks): array
    {
        if(!is_array($blocks)||!array_is_list($blocks)||count($blocks)>100)throw new \InvalidArgumentException('Use at most 100 data-only page blocks.');
        foreach($blocks as&$block){if(!is_array($block))throw new \InvalidArgumentException();$type=$block['type']??null;$allowed=['type','title'];
            if(isset($block['title']))self::text($block['title'],256);
            switch($type){
                case'text':$allowed[]='text';self::text($block['text']??null,16384);break;
                case'metrics':$allowed[]='items';if(!is_array($block['items']??null)||!array_is_list($block['items'])||count($block['items'])>100)throw new \InvalidArgumentException();foreach($block['items']as$item){if(!is_array($item))throw new \InvalidArgumentException();self::keys($item,['label','value','unit']);self::text($item['label']??null,256);if(!is_scalar($item['value']??null))throw new \InvalidArgumentException();self::text((string)$item['value'],4096);if(isset($item['unit']))self::text($item['unit'],64);}break;
                case'table':$allowed=[...$allowed,'columns','rows'];if(!is_array($block['columns']??null)||!array_is_list($block['columns'])||count($block['columns'])>50||!is_array($block['rows']??null)||!array_is_list($block['rows'])||count($block['rows'])>1000)throw new \InvalidArgumentException();$keys=[];foreach($block['columns']as$column){if(!is_array($column))throw new \InvalidArgumentException();self::keys($column,['key','label']);$key=self::id($column['key']??null);if(isset($keys[$key]))throw new \InvalidArgumentException();$keys[$key]=true;self::text($column['label']??null,256);}foreach($block['rows']as$row){if(!is_array($row)||array_diff(array_keys($row),array_keys($keys))!==[])throw new \InvalidArgumentException();foreach($row as$value){if($value!==null&&!is_scalar($value))throw new \InvalidArgumentException();self::text((string)$value,4096);}}break;
                case'chart':$allowed=[...$allowed,'renderer','data'];if(!in_array($block['renderer']??null,array_values(self::PLOT_TYPES),true))throw new \InvalidArgumentException();$block['data']=self::chartData($block['data']??null);break;
                default:throw new \InvalidArgumentException('Use a supported data-only page block.');
            }self::keys($block,$allowed);
        }unset($block);
        if(strlen(json_encode($blocks,JSON_THROW_ON_ERROR))>1048576)throw new \InvalidArgumentException('Page data is too large.');
        return$blocks;
    }

    private static function jsonValue(mixed $value,int $depth=0,int &$count=0): void
    {
        if($depth>8||++$count>100000)throw new HttpError(422,'invalid_extension_output','Extension data is too complex.');
        if(is_array($value)){foreach($value as$key=>$child){if(is_string($key))self::text($key,256);self::jsonValue($child,$depth+1,$count);}return;}
        if(is_string($value)){self::text($value,16384);return;}
        if($value===null||is_bool($value)||is_int($value)||(is_float($value)&&is_finite($value)))return;
        throw new HttpError(422,'invalid_extension_output','Extension data must contain finite JSON values.');
    }
}
