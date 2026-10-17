<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Api\V3\HttpError;
use Chronos\Api\V3\ParameterCompiler;
use PHPUnit\Framework\TestCase;

foreach (['HttpError','Input','ParameterCompiler'] as $file) { require_once CHRONOS_TEST_ROOT . '/api/v3/' . $file . '.php'; }

final class V3ParametersTest extends TestCase
{
    private function schema(): array
    {
        return [
            ['id'=>'base','title'=>'Base','depends'=>'','dependsValue'=>'','elements'=>[
                ['id'=>'i','type'=>'integer','parameter'=>'quantity','default'=>0],
                ['id'=>'b','type'=>'boolean','parameter'=>'enabled','default'=>false],
                ['id'=>'s','type'=>'string','parameter'=>'label','default'=>''],
                ['id'=>'a','type'=>'interval','parameter'=>'size','default-min'=>10,'default-max'=>20,'default-step'=>10],
                ['id'=>'v','type'=>'software-versions-list','parameter'=>'version'],
            ]],
            ['id'=>'conditional','title'=>'Conditional','depends'=>'enabled','dependsValue'=>'true','elements'=>[
                ['id'=>'hidden','type'=>'integer','parameter'=>'hidden','default'=>99],
            ]],
        ];
    }

    public function testPresencePreservesZeroFalseEmptyAndHiddenDraftWithoutMutatingInput(): void
    {
        $input=['quantity'=>0,'enabled'=>false,'label'=>'','version-version-0'=>'main','hidden'=>123];
        $values=ParameterCompiler::values($this->schema(),$input);
        self::assertSame(0,$values['quantity']);self::assertFalse($values['enabled']);self::assertSame('',$values['label']);self::assertSame(123,$values['hidden']);self::assertArrayNotHasKey('elements',$input);
        $legacy=ParameterCompiler::legacy($this->schema(),$values);
        self::assertNotContains('hidden',$legacy['elements']);self::assertArrayNotHasKey('hidden',$legacy);self::assertSame('integer',$legacy['i-type']);
    }

    public function testConditionalFieldsActivateWithBooleanTrue(): void
    {
        $values=ParameterCompiler::values($this->schema(),['enabled'=>true,'version-version-0'=>'main']);
        $legacy=ParameterCompiler::legacy($this->schema(),$values);self::assertContains('hidden',$legacy['elements']);self::assertSame(99,$legacy['hidden']);
    }

    public function testTrustedSchemaOverwritesForgedTypeAndDoesNotTrustClientElements(): void
    {
        $values=ParameterCompiler::values($this->schema(),['i-type'=>'custom-execution','i-parameter'=>'evil','elements'=>['evil'],'version-version-0'=>'main']);
        $legacy=ParameterCompiler::legacy($this->schema(),$values);self::assertSame('integer',$legacy['i-type']);self::assertSame('quantity',$legacy['i-parameter']);self::assertNotContains('evil',$legacy['elements']);
    }

    public function testRunsAndCartesianParametersRetainLegacyConfigurationShape(): void
    {
        $values=ParameterCompiler::values($this->schema(),['quantity'=>0,'enabled'=>false,'label'=>'Zürich & XML <value>','version-version-0'=>'main']);
        $legacy=ParameterCompiler::legacy($this->schema(),$values)+['runs'=>2,'run-distribution'=>'alter'];
        $jobs=ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,10);
        self::assertCount(4,$jobs);self::assertSame([10,20,10,20],array_column(array_column($jobs,'parameters'),'size'));
        self::assertSame([0,0,1,1],array_column(array_column($jobs,'parameters'),'run'));
        foreach($jobs as$job){self::assertSame(0,$job['parameters']['quantity']);self::assertSame('false',$job['parameters']['enabled']);self::assertSame('Zürich & XML <value>',$job['parameters']['label']);self::assertArrayNotHasKey('hidden',$job['parameters']);}
    }

    public function testConfiguredMaximumIsCheckedBeforeCartesianExpansion(): void
    {
        $values=ParameterCompiler::values($this->schema(),['version-version-0'=>'main']);$legacy=ParameterCompiler::legacy($this->schema(),$values)+['runs'=>100,'run-distribution'=>'alter'];
        $this->expectException(HttpError::class);ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,100);
    }

    public function testWrongDirectionIntervalIsRejectedBeforeLegacyLoop(): void
    {
        $values=ParameterCompiler::values($this->schema(),['size-min'=>10,'size-max'=>20,'size-step'=>-1,'version-version-0'=>'main']);$legacy=ParameterCompiler::legacy($this->schema(),$values);
        $this->expectException(HttpError::class);ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,100);
    }

    public function testDescendingIntervalIsBoundedAndRetainsEveryConfiguration(): void
    {
        $values=ParameterCompiler::values($this->schema(),['size-min'=>20,'size-max'=>10,'size-step'=>-10,'version-version-0'=>'main']);$legacy=ParameterCompiler::legacy($this->schema(),$values);
        $jobs=ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,100);self::assertCount(2,$jobs);self::assertSame([10,20],array_column(array_column($jobs,'parameters'),'size'));
    }

    public function testConditionalCycleCannotExecuteAnything(): void
    {
        $groups=[['id'=>'g1','depends'=>'two','dependsValue'=>'x','elements'=>[['id'=>'a','type'=>'string','parameter'=>'one']]],['id'=>'g2','depends'=>'one','dependsValue'=>'x','elements'=>[['id'=>'b','type'=>'string','parameter'=>'two']]]];
        $this->expectException(HttpError::class);ParameterCompiler::legacy($groups,['one'=>'x','two'=>'x']);
    }

    public function testUnknownCustomTypeIsAnExplicitValidationError(): void
    {
        $groups=[['id'=>'g','depends'=>'','elements'=>[['id'=>'e','type'=>'custom','parameter'=>'x']]]];$this->expectException(HttpError::class);ParameterCompiler::legacy($groups,['x'=>'safe']);
    }

    public function testPercentagePreviewCannotOverrideServerDerivedValue(): void
    {
        $groups=[['id'=>'g','depends'=>'','elements'=>[['id'=>'base','type'=>'integer','parameter'=>'count','default'=>80],['id'=>'share','type'=>'percentage','parameter'=>'share','default'=>25,'dependency'=>'count','parameter-type'=>'int']]]];
        $values=ParameterCompiler::values($groups,['share'=>99999]);$legacy=ParameterCompiler::legacy($groups,$values);$jobs=ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,10);self::assertSame('20',$jobs[0]['parameters']['share']);
    }
}
