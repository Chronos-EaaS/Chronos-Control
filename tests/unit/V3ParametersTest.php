<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Http\HttpError;
use Chronos\Application\Workflows\ParameterCompiler;
use PHPUnit\Framework\TestCase;


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

    public function testCompilerMetadataCannotOverwriteLegalParameterValuesOrExecutionOptions(): void
    {
        $fields = ['runs' => 99, '_v3Elements' => 'element value', '_v3Groups' => 'group value',
            '_v3Values' => 'draft value', 'run-distribution' => 'user value', 'groups' => 'group label', 'values' => 'value label'];
        $elements = [];
        foreach ($fields as $parameter => $value) $elements[] = ['id' => 'field-' . count($elements), 'type' => is_int($value) ? 'integer' : 'string', 'parameter' => $parameter, 'default' => $value];
        $groups = [['id' => 'base', 'depends' => '', 'elements' => $elements]];
        $values = ParameterCompiler::values($groups, $fields);
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, $values, ['runs' => 2, 'distribution' => 'alter'], 10);
        self::assertCount(2, $jobs);
        foreach ($jobs as $index => $job) self::assertSame($fields + ['run' => $index], $job['parameters']);
        self::assertSame($fields, $values);
    }

    public function testPerElementProcessorMetadataDoesNotChangeAnotherActualInput(): void
    {
        $groups = [['id' => 'base', 'elements' => [
            ['id' => 'count', 'type' => 'integer', 'parameter' => 'count', 'default' => 80],
            ['id' => 'share', 'type' => 'percentage', 'parameter' => 'share', 'default' => 25, 'dependency' => 'count'],
            ['id' => 'named-like-metadata', 'type' => 'integer', 'parameter' => 'share-type', 'default' => 7],
        ]]];
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, []), [], 10);
        self::assertSame(['count' => 80, 'share' => '20', 'share-type' => 7, 'run' => 0], $jobs[0]['parameters']);
    }

    public function testPercentageChainDerivesTrustedIntermediateValuesInEitherSchemaOrder(): void
    {
        $elements = [
            ['id' => 'quarter', 'type' => 'percentage', 'parameter' => 'quarter', 'dependency' => 'half', 'default' => 50],
            ['id' => 'half', 'type' => 'percentage', 'parameter' => 'half', 'dependency' => 'total', 'default' => 50],
            ['id' => 'total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100],
        ];
        foreach ([$elements, array_reverse($elements)] as $ordered) {
            $groups = [['id' => 'base', 'elements' => $ordered]];
            $values = ParameterCompiler::values($groups, ['quarter' => 99999, 'half' => -12345]);
            $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, $values, [], 10);
            self::assertSame(100, $jobs[0]['parameters']['total']);
            self::assertSame('50', $jobs[0]['parameters']['half']);
            self::assertSame('25', $jobs[0]['parameters']['quarter']);
            $legacy = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, ParameterCompiler::legacy($groups, $values), 10);
            self::assertSame('25', $legacy[0]['parameters']['quarter']);
        }
    }

    public function testDependingPercentageChainExecutesAfterItsLaterDeclaredBase(): void
    {
        $groups = [['id' => 'base', 'elements' => [
            ['id' => 'quarter', 'type' => 'percentage-dep', 'parameter' => 'quarter', 'dependency' => 'half', 'default' => 50, 'parameter-type' => 'float'],
            ['id' => 'half', 'type' => 'percentage-dep', 'parameter' => 'half', 'dependency' => 'total', 'default' => 50],
            ['id' => 'total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100],
        ]]];
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, []), [], 10);
        self::assertEquals(['total' => 100, 'half' => 50, 'quarter' => 25, 'run' => 0], $jobs[0]['parameters']);
    }

    public function testOrdinaryPercentageCannotUseRetainedValuesFromAnInactiveSourceGroup(): void
    {
        $groups = [
            ['id' => 'hidden', 'depends' => 'enabled', 'dependsValue' => 'true', 'elements' => [['id' => 'base', 'type' => 'integer', 'parameter' => 'base', 'default' => 100]]],
            ['id' => 'shown', 'elements' => [['id' => 'share', 'type' => 'percentage', 'parameter' => 'share', 'dependency' => 'base', 'default' => 50]]],
        ];
        $this->expectException(HttpError::class);
        ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, ['enabled' => false]), [], 10);
    }

    public function testLegacyMalformedDescriptorListsFailExplicitlyInsteadOfProducingAnEmptyJob(): void
    {
        foreach (['_v3Elements' => 'corrupt', 'elements' => 'corrupt'] as $field => $value) {
            try { ParameterCompiler::configurations(CHRONOS_TEST_ROOT, [$field => $value], 10); self::fail('Malformed descriptors were accepted.'); }
            catch (HttpError $error) { self::assertSame(422, $error->status); self::assertSame('validation_failed', $error->errorCode); }
        }
        foreach ([null, 5, 'bad', ['type' => 'integer', 'parameter' => null]] as $descriptor) {
            try { ParameterCompiler::configurations(CHRONOS_TEST_ROOT, ['_v3Elements' => [$descriptor]], 10); self::fail('An invalid descriptor was accepted.'); }
            catch (HttpError $error) { self::assertSame(422, $error->status); }
        }
    }


    public function testDropdownDefaultsMatchDisplayLabelsAndRejectUnavailableSubmittedChoices(): void
    {
        $groups = [['id' => 'base', 'elements' => [['id' => 'choice', 'type' => 'dropdown', 'parameter' => 'mode', 'options' => 'a,b', 'names' => 'Alpha,Beta', 'default' => 'Beta']]]];
        $values = ParameterCompiler::values($groups, []);
        self::assertSame('b', $values['mode']);
        self::assertSame('b', ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, $values, [], 10)[0]['parameters']['mode']);
        $this->expectException(HttpError::class);
        ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ['mode' => 'unavailable'], [], 10);
    }

    public function testConditionalGroupsUseSeparateParentExecutionContext(): void
    {
        $groups = [];
        foreach (['runs' => '2', 'run-distribution' => 'order', 'phase_warmUp' => '0'] as $parent => $expected) {
            $groups[] = ['id' => 'group-' . count($groups), 'depends' => $parent, 'dependsValue' => $expected,
                'elements' => [['id' => 'field-' . count($groups), 'type' => 'integer', 'parameter' => 'value' . count($groups), 'default' => 7]]];
        }
        $values = ParameterCompiler::values($groups, ['runs' => 1, 'run-distribution' => 'alter', 'phase_warmUp' => true]);
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, $values, ['runs' => 2, 'distribution' => 'order', 'warmUp' => false], 10);
        self::assertCount(2, $jobs);
        foreach ($jobs as $index => $job) self::assertSame(['run' => $index, 'value0' => 7, 'value1' => 7, 'value2' => 7], $job['parameters']);
        $inactive = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, $values, ['runs' => 1, 'distribution' => 'alter', 'warmUp' => true], 10);
        self::assertSame(['run' => 0], $inactive[0]['parameters']);
        $owned = [['id' => 'owner', 'elements' => [['id' => 'own-runs', 'type' => 'string', 'parameter' => 'runs']]],
            ['id' => 'dependent', 'depends' => 'runs', 'dependsValue' => '2', 'elements' => [['id' => 'detail', 'type' => 'string', 'parameter' => 'detail']]]];
        foreach ([[], ['runs' => '']] as $draft) self::assertCount(1, ParameterCompiler::elements($owned, $draft, ['runs' => 2]));
        self::assertCount(2, ParameterCompiler::elements($owned, ['runs' => '2'], ['runs' => 1]));
    }


    public function testFlatGroupsAndValuesParametersAreNeverImplicitlyUnwrapped(): void
    {
        $groups = [['id' => 'base', 'elements' => [
            ['id' => 'group-list', 'type' => 'string-list', 'parameter' => 'groups', 'default' => 'fallback groups'],
            ['id' => 'value-list', 'type' => 'string-list', 'parameter' => 'values', 'default' => 'fallback values'],
        ]]];
        foreach ([['groups' => ['alpha', 'beta'], 'values' => []], ['groups' => [], 'values' => [0, false]]] as $input) {
            self::assertSame($input, ParameterCompiler::values($groups, $input));
        }
        $scalars = ['groups' => 'alpha,beta', 'values' => 'zero,false'];
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, $scalars), [], 10);
        self::assertCount(4, $jobs);
        self::assertEqualsCanonicalizing([
            ['groups' => 'alpha', 'values' => 'zero', 'run' => 0], ['groups' => 'beta', 'values' => 'zero', 'run' => 0],
            ['groups' => 'alpha', 'values' => 'false', 'run' => 0], ['groups' => 'beta', 'values' => 'false', 'run' => 0],
        ], array_column($jobs, 'parameters'));
    }

}
