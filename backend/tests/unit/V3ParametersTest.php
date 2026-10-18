<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Http\HttpError;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Application\Systems\ParameterSchema;
use Chronos\Tests\Support\V2ExperimentFixture;
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
        self::assertNotContains('hidden', array_column(ParameterCompiler::elements($this->schema(), $values), 'id'));
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $this->schema(), $values, [], 10);
        self::assertArrayNotHasKey('hidden', $jobs[0]['parameters']);
        self::assertSame(0, $jobs[0]['parameters']['quantity']);
    }

    public function testConditionalFieldsActivateWithBooleanTrue(): void
    {
        $values=ParameterCompiler::values($this->schema(),['enabled'=>true,'version-version-0'=>'main']);
        self::assertContains('hidden', array_column(ParameterCompiler::elements($this->schema(), $values), 'id'));
        self::assertSame(99, ParameterCompiler::compile(CHRONOS_TEST_ROOT, $this->schema(), $values, [], 10)[0]['parameters']['hidden']);
    }

    public function testTrustedSchemaOverwritesForgedTypeAndDoesNotTrustClientElements(): void
    {
        $values=ParameterCompiler::values($this->schema(),['i-type'=>'custom-execution','i-parameter'=>'evil','elements'=>['evil'],'version-version-0'=>'main']);
        $elements = ParameterCompiler::elements($this->schema(), $values);
        self::assertSame('integer', $elements[0]['type']);
        self::assertSame('quantity', $elements[0]['parameter']);
        self::assertNotContains('evil', array_column($elements, 'id'));
        $parameters = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $this->schema(), $values, [], 10)[0]['parameters'];
        self::assertSame(0, $parameters['quantity']);
        self::assertArrayNotHasKey('evil', $parameters);
    }

    public function testRunsAndCartesianParametersRetainLegacyConfigurationShape(): void
    {
        $values=ParameterCompiler::values($this->schema(),['quantity'=>0,'enabled'=>false,'label'=>'Zürich & XML <value>','version-version-0'=>'main']);
        $legacy=V2ExperimentFixture::postData($this->schema()[0]['elements'],$values,['runs'=>2,'run-distribution'=>'alter']);
        $jobs=ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,10);
        self::assertCount(4,$jobs);self::assertSame([10,20,10,20],array_column(array_column($jobs,'parameters'),'size'));
        self::assertSame([0,0,1,1],array_column(array_column($jobs,'parameters'),'run'));
        foreach($jobs as$job){self::assertSame(0,$job['parameters']['quantity']);self::assertSame('false',$job['parameters']['enabled']);self::assertSame('Zürich & XML <value>',$job['parameters']['label']);self::assertArrayNotHasKey('hidden',$job['parameters']);}
    }

    public function testConfiguredMaximumIsCheckedBeforeCartesianExpansion(): void
    {
        $values=ParameterCompiler::values($this->schema(),['version-version-0'=>'main']);$legacy=V2ExperimentFixture::postData($this->schema()[0]['elements'],$values,['runs'=>100,'run-distribution'=>'alter']);
        $this->expectException(HttpError::class);ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,100);
    }

    public function testWrongDirectionIntervalIsRejectedBeforeLegacyLoop(): void
    {
        $values=ParameterCompiler::values($this->schema(),['size-min'=>10,'size-max'=>20,'size-step'=>-1,'version-version-0'=>'main']);$legacy=V2ExperimentFixture::postData($this->schema()[0]['elements'],$values);
        $this->expectException(HttpError::class);ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,100);
    }

    public function testDescendingIntervalIsBoundedAndRetainsEveryConfiguration(): void
    {
        $values=ParameterCompiler::values($this->schema(),['size-min'=>20,'size-max'=>10,'size-step'=>-10,'version-version-0'=>'main']);$legacy=V2ExperimentFixture::postData($this->schema()[0]['elements'],$values);
        $jobs=ParameterCompiler::configurations(CHRONOS_TEST_ROOT,$legacy,100);self::assertCount(2,$jobs);self::assertSame([10,20],array_column(array_column($jobs,'parameters'),'size'));
    }

    public function testConditionalCycleCannotExecuteAnything(): void
    {
        $groups=[['id'=>'g1','depends'=>'two','dependsValue'=>'x','elements'=>[['id'=>'a','type'=>'string','parameter'=>'one']]],['id'=>'g2','depends'=>'one','dependsValue'=>'x','elements'=>[['id'=>'b','type'=>'string','parameter'=>'two']]]];
        $this->expectException(HttpError::class);ParameterCompiler::compile(CHRONOS_TEST_ROOT,$groups,['one'=>'x','two'=>'x'],[],10);
    }

    public function testUnknownCustomTypeIsAnExplicitValidationError(): void
    {
        $groups=[['id'=>'g','depends'=>'','elements'=>[['id'=>'e','type'=>'custom','parameter'=>'x']]]];$this->expectException(HttpError::class);ParameterCompiler::compile(CHRONOS_TEST_ROOT,$groups,['x'=>'safe'],[],10);
    }

    public function testPercentagePreviewCannotOverrideServerDerivedValue(): void
    {
        $groups=[['id'=>'g','depends'=>'','elements'=>[['id'=>'base','type'=>'integer','parameter'=>'count','default'=>80],['id'=>'share','type'=>'percentage','parameter'=>'share','default'=>25,'dependency'=>'count','parameter-type'=>'int']]]];
        $values=ParameterCompiler::values($groups,['share'=>99999]);$jobs=ParameterCompiler::compile(CHRONOS_TEST_ROOT,$groups,$values,[],10);self::assertSame('20',$jobs[0]['parameters']['share']);
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
            // Original v2 submitted the already calculated absolute values.
            $saved = array_replace($values, ['half' => '50', 'quarter' => '25']);
            $legacy = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, V2ExperimentFixture::postData($ordered, $saved), 10);
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

    public function testConditionalGroupsUseTrustedPercentageValuesInsteadOfSubmittedPreviews(): void
    {
        $groups = [
            ['id' => 'base', 'elements' => [
                ['id' => 'total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100],
                ['id' => 'share', 'type' => 'percentage', 'parameter' => 'share', 'dependency' => 'total', 'default' => 50],
            ]],
            ['id' => 'shown', 'depends' => 'share', 'dependsValue' => '50',
                'elements' => [['id' => 'conditional', 'type' => 'integer', 'parameter' => 'conditional', 'default' => 7]]],
            ['id' => 'hidden', 'depends' => 'share', 'dependsValue' => '999',
                'elements' => [['id' => 'forged', 'type' => 'integer', 'parameter' => 'forged', 'default' => 9]]],
        ];
        foreach ([[], ['share' => 999]] as $input) {
            $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, $input), [], 10);
            self::assertSame(['total' => 100, 'share' => '50', 'conditional' => 7, 'run' => 0], $jobs[0]['parameters']);
        }
    }

    public function testPercentageVisibilityResolvesChainsAndIntervalControlsInEitherGroupOrder(): void
    {
        $groups = [
            ['id' => 'base', 'elements' => [
                ['id' => 'total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100],
                ['id' => 'half', 'type' => 'percentage', 'parameter' => 'half', 'dependency' => 'total', 'default' => 50],
                ['id' => 'quarter', 'type' => 'percentage', 'parameter' => 'quarter', 'dependency' => 'half', 'default' => 50],
                ['id' => 'portion', 'type' => 'percentage-interval', 'parameter' => 'portion', 'dependency' => 'half',
                    'default-start' => 50, 'default-end' => 100, 'default-step' => 50],
            ]],
            ['id' => 'chain', 'depends' => 'quarter', 'dependsValue' => '25',
                'elements' => [['id' => 'chain-value', 'type' => 'string', 'parameter' => 'chainValue', 'default' => 'present']]],
            ['id' => 'interval', 'depends' => 'portion-start', 'dependsValue' => '25',
                'elements' => [['id' => 'interval-value', 'type' => 'string', 'parameter' => 'intervalValue', 'default' => 'present']]],
        ];
        foreach ([$groups, array_reverse($groups)] as $ordered) {
            $values = ParameterCompiler::values($ordered, ['half' => -1, 'quarter' => -1, 'portion-start' => -1]);
            $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $ordered, $values, [], 10);
            self::assertCount(2, $jobs);
            foreach ($jobs as $job) {
                self::assertSame('present', $job['parameters']['chainValue']);
                self::assertSame('present', $job['parameters']['intervalValue']);
            }
        }
    }

    public function testInvalidPercentageDraftInAnInactiveGroupDoesNotBlockVisibleJobs(): void
    {
        $groups = [
            ['id' => 'base', 'elements' => [['id' => 'total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100]]],
            ['id' => 'inactive', 'depends' => 'enabled', 'dependsValue' => 'true', 'elements' => [
                ['id' => 'share', 'type' => 'percentage', 'parameter' => 'share', 'dependency' => 'total', 'default' => 'invalid'],
            ]],
            ['id' => 'also-inactive', 'depends' => 'share', 'dependsValue' => '50',
                'elements' => [['id' => 'detail', 'type' => 'integer', 'parameter' => 'detail', 'default' => 7]]],
        ];
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, ['enabled' => false, 'share' => 50]), [], 10);
        self::assertSame(['total' => 100, 'run' => 0], $jobs[0]['parameters']);
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

    public function testOriginalV2MalformedDescriptorsFailExplicitlyInsteadOfProducingAnEmptyJob(): void
    {
        foreach (['corrupt', ['not' => 'a list']] as $ids) {
            try { ParameterCompiler::configurations(CHRONOS_TEST_ROOT, ['elements' => $ids], 10); self::fail('Malformed descriptors were accepted.'); }
            catch (HttpError $error) { self::assertSame(422, $error->status); self::assertSame('validation_failed', $error->errorCode); }
        }
        foreach ([null, 5, ['bad']] as $id) {
            try { ParameterCompiler::configurations(CHRONOS_TEST_ROOT, ['elements' => [$id]], 10); self::fail('An invalid element ID was accepted.'); }
            catch (HttpError $error) { self::assertSame(422, $error->status); }
        }
        foreach ([[], ['field-type' => 'integer'], ['field-parameter' => 'count'], ['field-type' => 'integer', 'field-parameter' => null]] as $metadata) {
            try { ParameterCompiler::configurations(CHRONOS_TEST_ROOT, ['elements' => ['field']] + $metadata, 10); self::fail('An incomplete saved descriptor was accepted.'); }
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

    public function testOrdinaryParameterNamesCannotActAsLegacyCopyDescriptors(): void
    {
        $groups = [['id' => 'base', 'elements' => [
            ['id' => 'stable', 'type' => 'integer', 'parameter' => 'new', 'default' => 9],
            ['id' => 'label', 'type' => 'string', 'parameter' => 'stable-parameter'],
            ['id' => 'old', 'type' => 'integer', 'parameter' => 'old'],
            ['id' => 'choice', 'type' => 'checkbox-selection', 'parameter' => 'choice', 'options' => 'red,blue', 'default' => 'red'],
            ['id' => 'choice-label', 'type' => 'string', 'parameter' => 'choice-parameter'],
        ]]];
        $input = ['stable-parameter' => 'old', 'old' => 0, 'choice-parameter' => 'choice'];
        $values = ParameterCompiler::values($groups, $input);
        self::assertSame(9, $values['new']); self::assertSame('on', $values['choice-red']);
        self::assertSame('old', $values['stable-parameter']); self::assertSame(0, $values['old']);
        $legacy = ParameterCompiler::values($groups, $input + ['elements' => ['stable', 'choice']]);
        self::assertSame(0, $legacy['new']); self::assertFalse($legacy['choice-red']);
        self::assertSame(['stable-parameter' => 'old', 'old' => 0, 'choice-parameter' => 'choice'], $input);
    }

    public function testValidPrefixedParametersKeepTheirOwnVisibilityOwnerInEitherGroupOrder(): void
    {
        $groups = [
            ['id' => 'base', 'title' => 'Base', 'elements' => [
                ['id' => 'actual', 'name' => 'Actual', 'type' => 'integer', 'parameter' => 'a-b', 'default' => 7],
                ['id' => 'enabled', 'name' => 'Enabled', 'type' => 'boolean', 'parameter' => 'enabled', 'default' => false],
            ]],
            ['id' => 'inactive', 'title' => 'Inactive', 'depends' => 'enabled', 'dependsValue' => 'true', 'elements' => [
                ['id' => 'prefix', 'name' => 'Prefix', 'type' => 'string', 'parameter' => 'a', 'default' => 'retained'],
            ]],
            ['id' => 'detail', 'title' => 'Detail', 'depends' => 'a-b', 'dependsValue' => '7', 'elements' => [
                ['id' => 'detail-value', 'name' => 'Detail', 'type' => 'integer', 'parameter' => 'detail', 'default' => 9],
            ]],
        ];
        foreach ([$groups, array_reverse($groups)] as $ordered) {
            $valid = ParameterSchema::validate($ordered);
            $values = ParameterCompiler::values($valid, []);
            self::assertContains('detail-value', array_column(ParameterCompiler::elements($valid, $values), 'id'));
            $legacy = V2ExperimentFixture::postData(array_merge($groups[0]['elements'], $groups[2]['elements']), $values);
            self::assertContains('detail-value', $legacy['elements']);
            foreach ([ParameterCompiler::compile(CHRONOS_TEST_ROOT, $valid, $values, [], 10), ParameterCompiler::configurations(CHRONOS_TEST_ROOT, $legacy, 10)] as $jobs) {
                self::assertCount(1, $jobs); self::assertSame(7, $jobs[0]['parameters']['a-b']);
                self::assertSame(9, $jobs[0]['parameters']['detail']); self::assertArrayNotHasKey('a', $jobs[0]['parameters']);
            }
        }
    }

    public function testValidatedActualParameterWinsOverAnIntervalPreviewWithoutChangingItsBounds(): void
    {
        foreach ([false, true] as $inactive) {
            $groups = [
                ['id' => 'base', 'title' => 'Base', 'elements' => [
                    ['id' => 'total', 'name' => 'Total', 'type' => 'integer', 'parameter' => 'total', 'default' => 100],
                    ['id' => 'actual', 'name' => 'Actual start', 'type' => 'integer', 'parameter' => 'share-start', 'default' => 7],
                    ['id' => 'enabled', 'name' => 'Enabled', 'type' => 'boolean', 'parameter' => 'enabled', 'default' => false],
                ]],
                ['id' => 'interval', 'title' => 'Interval', 'depends' => $inactive ? 'enabled' : '', 'dependsValue' => 'true', 'elements' => [
                    ['id' => 'share', 'name' => 'Share', 'type' => 'percentage-interval', 'parameter' => 'share', 'dependency' => 'total',
                        'default-start' => 50, 'default-end' => 100, 'default-step' => 50],
                ]],
                ['id' => 'detail', 'title' => 'Detail', 'depends' => 'share-start', 'dependsValue' => '7', 'elements' => [
                    ['id' => 'detail-value', 'name' => 'Detail', 'type' => 'integer', 'parameter' => 'detail', 'default' => 9],
                ]],
            ];
            foreach ([$groups, array_reverse($groups)] as $ordered) {
                // This spelling is accepted by the public schema validator: it
                // does not overlap the interval's editable percentage fields.
                $valid = ParameterSchema::validate($ordered); $values = ParameterCompiler::values($valid, []);
                self::assertContains('detail-value', array_column(ParameterCompiler::elements($valid, $values), 'id'));
                $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $valid, $values, [], 10);
                self::assertCount($inactive ? 1 : 2, $jobs);
                foreach ($jobs as $job) { self::assertSame(7, $job['parameters']['share-start']); self::assertSame(9, $job['parameters']['detail']); }
                if (!$inactive) self::assertSame([50, 100], array_column(array_column($jobs, 'parameters'), 'share'));
                self::assertSame(7, $values['share-start']);
            }
        }
    }

}
