<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Tests\Support\V2ExperimentFixture;
use PHPUnit\Framework\TestCase;


final class V3ExtensionsTest extends TestCase
{
    private string $root;
    private string $system;

    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/chronos-extensions-'.bin2hex(random_bytes(8));
        $this->system=$this->root.'/webroot/systems/1';
        mkdir($this->system,0700,true);
        $source=CHRONOS_REPOSITORY_ROOT.'/tests/fixtures/extensions';
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS))as$file){
            $target=$this->system.'/'.substr($file->getPathname(),strlen($source)+1);
            if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
            copy($file->getPathname(),$target);
        }
    }

    protected function tearDown(): void { self::remove($this->root); }
    private static function remove(string $path): void
    {
        if(is_link($path)||is_file($path)){unlink($path);return;}
        foreach(new \FilesystemIterator($path)as$file)self::remove($file->getPathname());
        rmdir($path);
    }
    private function manifest(): array { return json_decode(file_get_contents($this->system.'/extensions.v3.json'),true,32,JSON_THROW_ON_ERROR); }
    private function save(array $document): void { file_put_contents($this->system.'/extensions.v3.json',json_encode($document,JSON_THROW_ON_ERROR)); }
    private function registry(bool $trusted=false): Extensions { return new Extensions($this->root,1,$trusted?[1]:[]); }

    public function testAliasCatalogNeedsNoPhpTrustAndExposesOnlyDataDescriptors(): void
    {
        $catalog=$this->registry()->catalog();
        self::assertSame('ready',$catalog['parameters'][0]['status']);
        self::assertSame('string',$this->registry()->parameterBase('custom-label'));
        self::assertSame('bar-plot',$this->registry()->plotBase('custom-bar'));
        self::assertSame('bar',$catalog['plots'][0]['renderer']);
        self::assertSame(['custom-label'=>'string'],$catalog['aliases']['parameters']);
        self::assertSame(['overview','live'],array_column($catalog['pages'],'id'));
        self::assertArrayNotHasKey('provider',$catalog['pages'][1]);
        self::assertArrayNotHasKey('blocks',$catalog['pages'][0]);
        self::assertSame(12,count(Extensions::capabilities()['parameterBaseTypes']));
    }

    public function testUntrustedPhpIsNeverEvaluatedEvenWhenItsFileExists(): void
    {
        file_put_contents($this->system.'/parameters/custom-double/process.php','<?php throw new LogicException("Executed untrusted PHP");');
        $registry=$this->registry();
        self::assertSame('blocked',$registry->catalog()['parameters'][1]['status']);
        $this->expectException(HttpError::class);
        $registry->parameterBase('custom-double');
    }

    public function testAliasPreservesStoredCustomTypeWhileGeneratingLegacyCompatibleJobs(): void
    {
        $groups=[['id'=>'fixture','elements'=>[['id'=>'name','type'=>'custom-label','parameter'=>'label','default'=>'']]]];
        $registry=$this->registry();
        $values=ParameterCompiler::values($groups,['label'=>'Zürich & XML <value>'],$registry);
        $data=V2ExperimentFixture::postData($groups[0]['elements'],$values);
        self::assertSame('custom-label',$data['name-type']);
        self::assertSame('custom-label',ParameterCompiler::elements($groups,$values,[],$registry)[0]['type']);
        $jobs=ParameterCompiler::configurations($this->root,$data,2,$registry);
        self::assertSame('Zürich & XML <value>',$jobs[0]['parameters']['label']);
        self::assertSame($jobs,ParameterCompiler::compile($this->root,$groups,$values,[],2,$registry));
    }

    public function testTrustedParameterProcessorUsesExistingVariablesAndZeroSurvives(): void
    {
        $groups=[['id'=>'fixture','elements'=>[['id'=>'number','type'=>'custom-double','parameter'=>'quantity','default'=>0]]]];
        $registry=$this->registry(true);
        foreach([0,3]as$value){
            $draft=ParameterCompiler::values($groups,['quantity'=>$value],$registry);
            $data=V2ExperimentFixture::postData($groups[0]['elements'],$draft);
            $jobs=ParameterCompiler::configurations($this->root,$data,2,$registry);
            self::assertSame(2*$value,$jobs[0]['parameters']['quantity']);
            self::assertSame($jobs,ParameterCompiler::compile($this->root,$groups,$draft,[],2,$registry));
        }
    }

    public function testPercentageAliasesUseTrustedValuesForConditionalGroups(): void
    {
        $manifest = $this->manifest();
        $manifest['parameters'][] = ['type' => 'custom-share', 'name' => 'Share', 'baseType' => 'percentage'];
        $this->save($manifest);
        $groups = [
            ['id' => 'base', 'elements' => [
                ['id' => 'total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100],
                ['id' => 'share', 'type' => 'custom-share', 'parameter' => 'share', 'dependency' => 'total', 'default' => 50],
            ]],
            ['id' => 'detail', 'depends' => 'share', 'dependsValue' => '50',
                'elements' => [['id' => 'label', 'type' => 'string', 'parameter' => 'label', 'default' => 'visible']]],
        ];
        $registry = $this->registry();
        $values = ParameterCompiler::values($groups, ['share' => 999], $registry);
        $jobs = ParameterCompiler::compile($this->root, $groups, $values, [], 10, $registry);
        self::assertSame(['total' => 100, 'share' => '50', 'label' => 'visible', 'run' => 0], $jobs[0]['parameters']);
    }

    public function testInactiveUnavailableAdapterRemainsInertInSavedDefinitions(): void
    {
        $groups = [
            ['id' => 'base', 'elements' => [['id' => 'total', 'type' => 'integer', 'parameter' => 'total']]],
            ['id' => 'inactive', 'depends' => 'enabled', 'dependsValue' => 'true',
                'elements' => [['id' => 'blocked', 'type' => 'custom-double', 'parameter' => 'quantity']]],
        ];
        $jobs = ParameterCompiler::compile($this->root, $groups, ['total' => 1, 'enabled' => false], [], 10, $this->registry());
        self::assertSame(['total' => 1, 'run' => 0], $jobs[0]['parameters']);
    }

    public function testProcessorCannotReturnExcessJobsOrPrintPhpUi(): void
    {
        $path=$this->system.'/parameters/custom-double/process.php';
        foreach(['<?php $allConfigurations = array_fill(0, 3, ["parameters"=>[]]);','<?php echo "<script>old UI</script>";']as$source){
            file_put_contents($path,$source);$jobs=[['parameters'=>[]]];
            try{$this->registry(true)->processParameter('custom-double',[],'quantity',$jobs,2);self::fail('Invalid processor output accepted.');}
            catch(HttpError$error){self::assertSame('invalid_extension_output',$error->errorCode);}
        }
    }

    public function testTrustedPlotProcessorProducesValidatedDatasets(): void
    {
        $job=new class {public function getResult():string{return '{"metric":7}';}};
        $data=$this->registry(true)->plotData('custom-score',[[$job,$job]],['parameter'=>'throughput']);
        self::assertSame(['Synthetic score'],$data['labels']);
        self::assertSame([14],$data['datasets'][0]['data']);
        $this->expectException(HttpError::class);
        Extensions::chartData(['labels'=>[],'datasets'=>[['data'=>[INF]]]]);
    }

    public function testDataOnlyPagePreservesLiteralMarkupZeroFalseAndAllFourBlocks(): void
    {
        $page=$this->registry()->page('overview',false);
        self::assertSame(['text','metrics','table','chart'],array_column($page['blocks'],'type'));
        self::assertSame('Zürich & XML <value> stays plain text.',$page['blocks'][0]['text']);
        self::assertSame(0,$page['blocks'][1]['items'][0]['value']);
        self::assertFalse($page['blocks'][1]['items'][1]['value']);
        $this->expectException(HttpError::class);
        $this->registry()->page('private',false);
    }

    public function testTrustedProviderGetsOnlyExplicitContextAndReturnsSafeBlocks(): void
    {
        $page=$this->registry(true)->page('live',false,['systemId'=>1,'userId'=>4]);
        self::assertSame(1,$page['blocks'][0]['items'][0]['value']);
        self::assertSame(4,$page['blocks'][0]['items'][1]['value']);
        $this->expectException(HttpError::class);
        $this->registry()->page('live',false);
    }

    public function testManifestCannotLoadTraversalCodeOrOverrideBuiltinTypes(): void
    {
        foreach(['../outside.php','parameters/custom-double/../../outside.php','/etc/passwd']as$path){
            $document=$this->manifest();$document['parameters'][1]['processor']=$path;$this->save($document);
            self::assertSame([], $this->registry(true)->catalog()['parameters']);
            self::assertSame('invalid_extension_manifest',$this->registry()->catalog()['diagnostics'][0]['code']);
        }
        $document=['format'=>1,'parameters'=>[['type'=>'integer','name'=>'override','baseType'=>'string']]];$this->save($document);
        self::assertSame([],$this->registry()->catalog()['parameters']);
    }

    public function testIntermediateOrFinalSymlinksBlockTrustedProcessorWithoutOutsideEffects(): void
    {
        $outside=$this->root.'/outside.php';file_put_contents($outside,'<?php throw new LogicException("Outside code loaded");');
        $file=$this->system.'/parameters/custom-double/process.php';unlink($file);symlink($outside,$file);
        self::assertSame('blocked',$this->registry(true)->catalog()['parameters'][1]['status']);
        unlink($file);rmdir(dirname($file));symlink($this->root,$this->system.'/parameters/custom-double');
        self::assertSame('blocked',$this->registry(true)->catalog()['parameters'][1]['status']);
        self::assertSame('<?php throw new LogicException("Outside code loaded");',file_get_contents($outside));
    }

    public function testUnknownSavedTypesAndLegacyPagesAreDiagnosedWithoutExecutionOrMigration(): void
    {
        file_put_contents($this->system.'/parameters.json','[{"elements":[{"type":"retained-unknown"}]}]');
        file_put_contents($this->system.'/results.json','[{"type":"retained-plot"}]');
        file_put_contents($this->system.'/1.php','<?php throw new LogicException("Old page executed");');
        $before=hash_file('sha256',$this->system.'/results.json');
        $codes=array_column($this->registry()->catalog()['diagnostics'],'code');
        self::assertContains('unregistered_parameter',$codes);self::assertContains('unregistered_plot',$codes);self::assertContains('legacy_page',$codes);
        self::assertSame($before,hash_file('sha256',$this->system.'/results.json'));
        self::assertFileDoesNotExist($this->system.'/resultConfigurations.json');
    }

    public function testPagesRejectExecutableFieldsAndNonfiniteOrUnboundedData(): void
    {
        foreach([
            [['type'=>'html','html'=>'<script>bad()</script>']],
            [['type'=>'text','text'=>'safe','onclick'=>'bad()']],
            [['type'=>'table','columns'=>[['key'=>'known','label'=>'Known']],'rows'=>[['unknown'=>'no']]]],
            [['type'=>'chart','renderer'=>'arbitrary-plugin','data'=>['labels'=>[],'datasets'=>[]]]],
        ]as$blocks){try{Extensions::blocks($blocks);self::fail('Unsafe page accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}}
        $this->expectException(\InvalidArgumentException::class);Extensions::blocks(array_fill(0,101,['type'=>'text','text'=>'x']));
    }
}
