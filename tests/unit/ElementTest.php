<?php

declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Tests\Support\KnownLegacyDefect;
use Element;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Util;

final class ElementTest extends TestCase
{
    use KnownLegacyDefect;

    private static function element(string $directory): Element
    {
        return new Element(CHRONOS_TEST_ROOT . '/libraries/elements/' . $directory);
    }

    #[DataProvider('elementKinds')]
    public function testEveryBundledKindDeclaresItsJobMultiplicity(
        string $directory,
        string $type,
        bool $multiJob,
    ): void {
        $element = self::element($directory);
        self::assertSame($type, $element->getType());
        self::assertSame($multiJob, $element->generatesMultiJob());
    }

    public static function elementKinds(): iterable
    {
        yield 'BLD-001 boolean' => ['boolean', 'boolean', false];
        yield 'BLD-002 checkbox selection' => ['checkbox-selection', 'checkbox-selection', true];
        yield 'BLD-003 dropdown' => ['dropdown', 'dropdown', false];
        yield 'BLD-004 integer' => ['integer', 'integer', false];
        yield 'BLD-005 interval' => ['interval', 'interval', true];
        yield 'BLD-006 depending percentage' => ['percentage-dep', 'percentage-dep', false];
        yield 'BLD-007 percentage interval' => ['percentage-interval', 'percentage-interval', true];
        yield 'BLD-008 percentage' => ['percentage', 'percentage', false];
        yield 'BLD-009 ratio' => ['ratio', 'ratio', true];
        yield 'BLD-010 software versions' => ['software-version-list', 'software-versions-list', true];
        yield 'BLD-011 string list' => ['string-list', 'string-list', true];
        yield 'BLD-012 string' => ['string', 'string', false];
    }

    public function testTheInventoryMatchesAllDiscoveredBundledControls(): void
    {
        $types = array_map(static fn (Element $element): string => $element->getType(), Util::getDefaultParameterElements());
        sort($types);
        self::assertSame([
            'boolean', 'checkbox-selection', 'dropdown', 'integer', 'interval',
            'percentage', 'percentage-dep', 'percentage-interval', 'ratio',
            'software-versions-list', 'string', 'string-list',
        ], $types, 'A newly added control needs processor and copy characterization.');
    }

    #[DataProvider('processorCases')]
    public function testEachControlProducesTheExpectedParameterConfigurations(
        string $directory,
        string $parameter,
        array $input,
        array $seed,
        array $expected,
    ): void {
        self::element($directory)->process($input, $parameter, $seed);
        self::assertSame($expected, $seed);
    }

    public static function processorCases(): iterable
    {
        $seed = [['parameters' => ['fixed' => 'kept'], 'title' => 'Keep metadata']];
        foreach ([
            ['BLD-001 boolean false remains a string', 'boolean', 'enabled', ['enabled' => 'false'], 'false'],
            ['BLD-003 dropdown', 'dropdown', 'mode', ['mode' => 'parallel'], 'parallel'],
            ['BLD-004 integer input preserves transport type', 'integer', 'size', ['size' => '17'], '17'],
            ['BLD-008 browser-computed absolute percentage', 'percentage', 'share', ['share' => '25', 'share-percentage' => '50'], '25'],
            ['BLD-012 string is data, not markup', 'string', 'label', ['label' => 'café <value>'], 'café <value>'],
        ] as [$label, $directory, $parameter, $input, $value]) {
            yield $label => [$directory, $parameter, $input, $seed,
                [['parameters' => ['fixed' => 'kept', $parameter => $value], 'title' => 'Keep metadata']]];
        }
        yield 'BLD-002 selected checkboxes only' => [
            'checkbox-selection', 'mode',
            ['mode-slow' => 'on', 'mode-fast' => 'on', 'mode-off' => 'off', 'other-fast' => 'on'], $seed,
            [
                ['parameters' => ['fixed' => 'kept', 'mode' => 'slow'], 'title' => 'Keep metadata'],
                ['parameters' => ['fixed' => 'kept', 'mode' => 'fast'], 'title' => 'Keep metadata'],
            ],
        ];
        yield 'BLD-005 inclusive interval stops before exceeding maximum' => [
            'interval', 'size', ['size-min' => '2', 'size-max' => '7', 'size-step' => '2'], $seed,
            [
                ['parameters' => ['fixed' => 'kept', 'size' => 2], 'title' => 'Keep metadata'],
                ['parameters' => ['fixed' => 'kept', 'size' => 4], 'title' => 'Keep metadata'],
                ['parameters' => ['fixed' => 'kept', 'size' => 6], 'title' => 'Keep metadata'],
            ],
        ];
        yield 'BLD-006 depending integer percentage floors eleven quarters' => [
            'percentage-dep', 'share', ['share-dependency' => 'total', 'share-percentage' => '25', 'share-type' => 'int'],
            [['parameters' => ['total' => 11]]], [['parameters' => ['total' => 11, 'share' => 2.0]]],
        ];
        yield 'BLD-006 depending float percentage preserves fraction' => [
            'percentage-dep', 'share', ['share-dependency' => 'total', 'share-percentage' => '25', 'share-type' => 'float'],
            [['parameters' => ['total' => 11]]], [['parameters' => ['total' => 11, 'share' => 2.75]]],
        ];
        yield 'BLD-007 percentage interval tracks shared dependency step' => [
            'percentage-interval', 'share', ['share-dependency' => 'total', 'share-start' => 10, 'share-end' => 30, 'share-step' => 10],
            [['parameters' => ['fixed' => 'kept']]],
            [
                ['parameters' => ['fixed' => 'kept', 'share' => 10], 'total-step' => 1],
                ['parameters' => ['fixed' => 'kept', 'share' => 20], 'total-step' => 2],
                ['parameters' => ['fixed' => 'kept', 'share' => 30], 'total-step' => 3],
            ],
        ];
        yield 'BLD-009 complementary ratios' => [
            'ratio', 'reads,writes', ['reads,writes-start' => '0:100', 'reads,writes-end' => '100:0', 'reads,writes-step' => '50'],
            [['parameters' => []]],
            [
                ['parameters' => ['reads' => 0, 'writes' => 100]],
                ['parameters' => ['reads' => 50, 'writes' => 50]],
                ['parameters' => ['reads' => 100, 'writes' => 0]],
            ],
        ];
        yield 'BLD-010 software versions expand each version' => [
            'software-version-list', 'revision', ['revision-numberOfVersions' => 2, 'revision-version-0' => 'main', 'revision-version-1' => 'release/next'],
            [['parameters' => []]],
            [['parameters' => ['revision' => 'main']], ['parameters' => ['revision' => 'release/next']]],
        ];
        yield 'BLD-011 string list expands in input order' => [
            'string-list', 'dataset', ['dataset' => 'alpha,beta'], [['parameters' => []]],
            [['parameters' => ['dataset' => 'alpha']], ['parameters' => ['dataset' => 'beta']]],
        ];
    }

    #[DataProvider('copyCases')]
    public function testEachControlRestoresSavedExperimentValues(string $directory, array $saved, mixed $expected): void
    {
        self::assertSame($expected, self::element($directory)->copyValue($saved, ['id' => 'field', 'parameter' => 'value']));
    }

    public static function copyCases(): iterable
    {
        foreach ([
            ['BLD-001 boolean', 'boolean', 'false'],
            ['BLD-003 dropdown', 'dropdown', 'parallel'],
            ['BLD-004 integer', 'integer', '23'],
            ['BLD-011 string list', 'string-list', 'alpha,beta'],
            ['BLD-012 string', 'string', 'quoted "text"'],
        ] as [$label, $kind, $value]) {
            yield $label => [$kind, ['field-parameter' => 'value', 'value' => $value], $value];
        }
        yield 'BLD-002 checkbox values' => ['checkbox-selection',
            ['field-parameter' => 'value', 'value-red' => 'on', 'value-blue' => 'off', 'value-green' => 'on'], 'red,green'];
        yield 'BLD-005 interval preserves zero minimum' => ['interval',
            ['field-parameter' => 'value', 'value-min' => '0', 'value-max' => '8', 'value-step' => '2'], ['0', '8', '2']];
        yield 'BLD-006 depending percentage' => ['percentage-dep',
            ['field-parameter' => 'value', 'value-percentage' => '25'], '25'];
        yield 'BLD-007 percentage interval uses percentages, not absolute values' => ['percentage-interval',
            ['field-parameter' => 'value', 'value-percentage-start' => '0', 'value-percentage-end' => '100', 'value-percentage-step' => '20'], ['0', '100', '20']];
        yield 'BLD-008 percentage preserves zero' => ['percentage',
            ['field-parameter' => 'value', 'value-percentage' => '0'], '0'];
        yield 'BLD-009 ratio' => ['ratio',
            ['field-parameter' => 'value', 'value-start' => '0:100', 'value-end' => '100:0', 'value-step' => '50'], ['0:100', '100:0', '50']];
        yield 'BLD-010 software versions' => ['software-version-list',
            ['field-parameter' => 'value', 'value-numberOfVersions' => 2, 'value-version-0' => 'main', 'value-version-1' => 'next'], ['main', 'next']];
    }

    public function testASecondPercentageIntervalFollowsExistingStepsInsteadOfMultiplyingJobs(): void
    {
        $configurations = [
            ['parameters' => ['first' => 0], 'total-step' => 1],
            ['parameters' => ['first' => 10], 'total-step' => 2],
            ['parameters' => ['first' => 20], 'total-step' => 3],
        ];
        self::element('percentage-interval')->process([
            'second-dependency' => 'total', 'second-start' => 100, 'second-end' => 80, 'second-step' => -10,
        ], 'second', $configurations);
        self::assertSame([
            ['parameters' => ['first' => 0, 'second' => 100], 'total-step' => 1],
            ['parameters' => ['first' => 10, 'second' => 90], 'total-step' => 2],
            ['parameters' => ['first' => 20, 'second' => 80], 'total-step' => 3],
        ], $configurations);
    }

    public function testIntervalWithEqualEndpointsAndZeroStepCreatesExactlyOneConfiguration(): void
    {
        $configurations = [['parameters' => []]];
        self::element('interval')->process(['n-min' => 7, 'n-max' => 7, 'n-step' => 0], 'n', $configurations);
        self::assertSame([['parameters' => ['n' => 7]]], $configurations);
    }

    public function testZeroIntervalStepOverARangeIsRejected(): void
    {
        $configurations = [['parameters' => []]];
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid step size');
        self::element('interval')->process(['n-min' => 1, 'n-max' => 3, 'n-step' => 0], 'n', $configurations);
    }

    #[DataProvider('invalidRatioCases')]
    public function testInvalidRatiosAreRejected(string $parameter, string $start, string $end, int $step): void
    {
        $configurations = [['parameters' => []]];
        $this->expectException(Exception::class);
        self::element('ratio')->process([
            $parameter . '-start' => $start, $parameter . '-end' => $end, $parameter . '-step' => $step,
        ], $parameter, $configurations);
    }

    public static function invalidRatioCases(): iterable
    {
        yield 'bad start total' => ['reads,writes', '10:80', '100:0', 10];
        yield 'bad end total' => ['reads,writes', '0:100', '50:20', 10];
        yield 'malformed start' => ['reads,writes', '10', '100:0', 10];
        yield 'zero step' => ['reads,writes', '0:100', '100:0', 0];
        yield 'negative step' => ['reads,writes', '0:100', '100:0', -1];
        yield 'step over 100' => ['reads,writes', '0:100', '100:0', 101];
        yield 'wrong parameter count' => ['reads', '0:100', '100:0', 10];
    }

    #[DataProvider('invalidPercentageCases')]
    public function testDependingPercentageRejectsMissingOrInvalidDependencies(array $input, array $parameters): void
    {
        $configurations = [['parameters' => $parameters]];
        $this->expectException(Exception::class);
        self::element('percentage-dep')->process($input, 'share', $configurations);
    }

    public static function invalidPercentageCases(): iterable
    {
        yield 'missing dependency name' => [['share-percentage' => '25'], ['total' => 10]];
        yield 'missing dependency value' => [['share-dependency' => 'total', 'share-percentage' => '25'], []];
        yield 'non-numeric percentage' => [['share-dependency' => 'total', 'share-percentage' => 'invalid'], ['total' => 10]];
    }

    public function testUnselectedCheckboxesProduceNoConfigurations(): void
    {
        $configurations = [['parameters' => []]];
        self::element('checkbox-selection')->process(['mode-red' => 'off'], 'mode', $configurations);
        self::assertSame([], $configurations, 'The job-generation layer must reject an empty evaluation.');
    }

    public function testSoftwareVersionCopyAcceptsHistoricalCommaSeparatedValues(): void
    {
        self::assertSame(['main', 'release/next'], self::element('software-version-list')->copyValue(
            ['revision' => 'main,release/next'], ['id' => 'new-field', 'parameter' => 'revision'],
        ));
    }

    #[Group('known-defect')]
    public function testCopiedIntegerZeroMustSurviveRatherThanRevertingToADefault(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('BLD-004-ZERO', 'Legacy empty() drops a copied zero.', function (): void {
            self::assertSame('0', self::element('integer')->copyValue(
                ['field-parameter' => 'value', 'value' => '0'], ['id' => 'field', 'parameter' => 'value'],
            ));
        });
    }

    #[Group('known-defect')]
    public function testNegativeIntervalStepRequiresValidationBeforeItCanBeExecuted(): void
    {
        self::markTestIncomplete('BLD-023: the legacy ascending interval with a negative step can loop forever. '
            . 'Do not execute it in the unit process; add a bounded rejection regression when validation is implemented.');
    }
}
