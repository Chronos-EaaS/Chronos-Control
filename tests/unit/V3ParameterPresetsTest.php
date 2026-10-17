<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Api\V3\HttpError;
use Chronos\Api\V3\ParameterPresets;
use PHPUnit\Framework\TestCase;

foreach(['HttpError','Input','ParameterPresets']as$file)require_once CHRONOS_TEST_ROOT.'/api/v3/'.$file.'.php';

final class V3ParameterPresetsTest extends TestCase
{
    private string $root;
    private string $hash;
    protected function setUp():void{$this->root=sys_get_temp_dir().'/chronos-presets-'.bin2hex(random_bytes(8));mkdir($this->root,0700);$this->hash=ParameterPresets::schemaHash([['id'=>'fixture','elements'=>[]]]);}
    protected function tearDown():void{self::remove($this->root);}
    private static function remove(string$path):void{if(is_link($path)||is_file($path)){unlink($path);return;}foreach(new \FilesystemIterator($path)as$entry)self::remove($entry->getPathname());rmdir($path);}
    private function store(int$id=1):ParameterPresets{return new ParameterPresets($this->root,$id);}
    private function create(string$name='Zero and false'):array{return$this->store()->save(null,['name'=>$name,'values'=>['quantity'=>0,'enabled'=>false,'hidden-value'=>'Zürich & <value>','choices'=>['a','b']],'schemaHash'=>$this->hash],$this->hash,3);}
    private function fails(callable$action,string$code):void{try{$action();self::fail('Invalid preset operation succeeded.');}catch(HttpError$error){self::assertSame($code,$error->errorCode);}}

    public function testReadingAnEmptyProjectCreatesNoDirectoriesOrLockFiles():void
    {
        self::assertSame(['items'=>[],'schemaHash'=>$this->hash],$this->store()->listing($this->hash));
        self::assertSame([],array_values(array_diff(scandir($this->root),['.','..'])));
    }

    public function testCreatePreservesHiddenZeroFalseAndUnicodeWithPrivateAtomicStorage():void
    {
        $created=$this->create();
        self::assertSame(0,$created['values']['quantity']);self::assertFalse($created['values']['enabled']);self::assertSame('Zürich & <value>',$created['values']['hidden-value']);
        self::assertSame([$created],$this->store()->listing($this->hash)['items']);
        self::assertSame(0600,fileperms($this->root.'/data/presets/1.json')&0777);
        self::assertSame([],glob($this->root.'/data/presets/.preset-*'));
        self::assertSame([],$this->store(2)->listing($this->hash)['items']);
    }

    public function testTwoReadersCannotSilentlyOverwriteOrDeleteANewerRevision():void
    {
        $initial=$this->create();$body=['name'=>'Updated','values'=>['quantity'=>1],'schemaHash'=>$this->hash,'revision'=>$initial['revision']];
        $next=$this->store()->save($initial['id'],$body,$this->hash,4);
        self::assertNotSame($initial['revision'],$next['revision']);self::assertSame(3,$next['createdBy']);self::assertSame(4,$next['updatedBy']);
        $this->fails(fn()=>$this->store()->save($initial['id'],$body,$this->hash,3),'preset_changed');
        $this->fails(fn()=>$this->store()->delete($initial['id'],$initial['revision']),'preset_changed');
        self::assertSame([$next],$this->store()->listing($this->hash)['items']);
        self::assertSame(['deleted'=>true],$this->store()->delete($next['id'],$next['revision']));
        self::assertSame([],$this->store()->listing($this->hash)['items']);
    }

    public function testChangedSchemaRetainsReadableDraftButRejectsStaleSave():void
    {
        $initial=$this->create();$newHash=str_repeat('b',64);
        $listing=$this->store()->listing($newHash);self::assertFalse($listing['items'][0]['compatible']);self::assertSame($initial['values'],$listing['items'][0]['values']);
        $this->fails(fn()=>$this->store()->save(null,['name'=>'Stale','values'=>[],'schemaHash'=>$this->hash],$newHash,3),'schema_changed');
        self::assertCount(1,$this->store()->listing($newHash)['items']);
    }

    public function testCanonicalHashIgnoresObjectKeyOrderButTracksArrayOrderAndDefaults():void
    {
        self::assertSame(ParameterPresets::schemaHash([['id'=>'one','title'=>'A']]),ParameterPresets::schemaHash([['title'=>'A','id'=>'one']]));
        self::assertNotSame(ParameterPresets::schemaHash([['id'=>'one'],['id'=>'two']]),ParameterPresets::schemaHash([['id'=>'two'],['id'=>'one']]));
        self::assertNotSame(ParameterPresets::schemaHash([['default'=>0]]),ParameterPresets::schemaHash([['default'=>false]]));
    }

    public function testDuplicateNamesAndInvalidValuesDoNotModifySavedBytes():void
    {
        $this->create('Fixture');$path=$this->root.'/data/presets/1.json';$before=file_get_contents($path);
        $this->fails(fn()=>$this->create('fixture'),'preset_name_conflict');
        foreach([['nested'=>[['invalid'=>'object']]],['value'=>INF],['null-byte'=>"bad\0value"]]as$values)$this->fails(fn()=>$this->store()->save(null,['name'=>'Invalid','values'=>$values,'schemaHash'=>$this->hash],$this->hash,3),'validation_failed');
        self::assertSame($before,file_get_contents($path));
    }

    public function testCorruptCollectionCannotBeReplacedByAnEmptyImplicitDraft():void
    {
        $this->create();$path=$this->root.'/data/presets/1.json';file_put_contents($path,'broken');
        $this->fails(fn()=>$this->store()->listing($this->hash),'preset_storage_unavailable');
        $this->fails(fn()=>$this->create('Another'),'preset_storage_unavailable');self::assertSame('broken',file_get_contents($path));
    }

    public function testLinkedDirectoryFileAndLockNeverModifyOutsideTargets():void
    {
        $outside=$this->root.'/outside';mkdir($outside);file_put_contents($outside.'/untouched','safe');
        symlink($outside,$this->root.'/data');$this->fails(fn()=>$this->create(),'preset_storage_unavailable');unlink($this->root.'/data');
        $this->create();$file=$this->root.'/data/presets/1.json';unlink($file);symlink($outside.'/untouched',$file);$this->fails(fn()=>$this->create(),'preset_storage_unavailable');unlink($file);
        $lock=$this->root.'/data/presets/1.lock';unlink($lock);symlink($outside.'/untouched',$lock);$this->fails(fn()=>$this->create(),'preset_storage_unavailable');self::assertSame('safe',file_get_contents($outside.'/untouched'));
    }
}
