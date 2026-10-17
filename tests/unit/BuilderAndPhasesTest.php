<?php

declare(strict_types=1);

namespace Chronos\Tests;

use Builder_Library;
use Element;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Util;

final class BuilderAndPhasesTest extends TestCase
{
    public function testMultipleMultiJobControlsFormACartesianProductAndPreserveScalarFields(): void
    {
        $configurations = [['parameters' => []]];
        (new Element(CHRONOS_TEST_ROOT . '/libraries/elements/string-list'))->process(['dataset' => 'alpha,beta'], 'dataset', $configurations);
        (new Element(CHRONOS_TEST_ROOT . '/libraries/elements/interval'))->process(['workers-min' => 1, 'workers-max' => 2, 'workers-step' => 1], 'workers', $configurations);
        (new Element(CHRONOS_TEST_ROOT . '/libraries/elements/boolean'))->process(['cache' => 'false'], 'cache', $configurations);

        self::assertSame([
            ['parameters' => ['dataset' => 'alpha', 'workers' => 1, 'cache' => 'false']],
            ['parameters' => ['dataset' => 'beta', 'workers' => 1, 'cache' => 'false']],
            ['parameters' => ['dataset' => 'alpha', 'workers' => 2, 'cache' => 'false']],
            ['parameters' => ['dataset' => 'beta', 'workers' => 2, 'cache' => 'false']],
        ], $configurations);
    }

    public function testRunsAppliedAfterParametersAlternateConfigurationsWithinEachRun(): void
    {
        $configurations = [['parameters' => ['workers' => 1]], ['parameters' => ['workers' => 2]]];
        Builder_Library::apply_runs(['runs' => '2'], $configurations);
        self::assertSame([
            ['parameters' => ['workers' => 1, 'run' => 0]],
            ['parameters' => ['workers' => 2, 'run' => 0]],
            ['parameters' => ['workers' => 1, 'run' => 1]],
            ['parameters' => ['workers' => 2, 'run' => 1]],
        ], $configurations);
    }

    public function testRunsAppliedBeforeAnIntervalKeepRunsTogetherForEachParameterValue(): void
    {
        $configurations = [['parameters' => []]];
        Builder_Library::apply_runs(['runs' => 2], $configurations);
        (new Element(CHRONOS_TEST_ROOT . '/libraries/elements/interval'))->process([
            'workers-min' => 1, 'workers-max' => 2, 'workers-step' => 1,
        ], 'workers', $configurations);
        self::assertSame([
            ['parameters' => ['run' => 0, 'workers' => 1]],
            ['parameters' => ['run' => 1, 'workers' => 1]],
            ['parameters' => ['run' => 0, 'workers' => 2]],
            ['parameters' => ['run' => 1, 'workers' => 2]],
        ], $configurations);
    }

    #[DataProvider('invalidRuns')]
    public function testNonPositiveOrNonNumericRunsAreRejectedWithoutMutatingTheInput(mixed $runs): void
    {
        $configurations = [['parameters' => ['workers' => 1], 'title' => 'Keep']];
        try {
            Builder_Library::apply_runs(['runs' => $runs], $configurations);
            self::fail('Invalid runs were accepted.');
        } catch (Exception $exception) {
            self::assertSame('Invalid run number!', $exception->getMessage());
            self::assertSame([['parameters' => ['workers' => 1], 'title' => 'Keep']], $configurations);
        }
    }

    public static function invalidRuns(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'non-numeric' => ['invalid'];
        yield 'empty' => [''];
    }

    public function testRunCopiesDoNotAliasEachOthersParameterArrays(): void
    {
        $configurations = [['parameters' => ['workers' => 1], 'title' => 'Keep']];
        Builder_Library::apply_runs(['runs' => 2], $configurations);
        $configurations[0]['parameters']['workers'] = 99;
        self::assertSame(['parameters' => ['workers' => 1, 'run' => 1], 'title' => 'Keep'], $configurations[1]);
    }

    #[DataProvider('phaseCombinations')]
    public function testEveryPhaseCombinationPreservesExcludeBitsEnabledFlagsAndExecutionOrder(
        array $enabled,
        int $mask,
        array $names,
    ): void {
        self::assertSame($mask, Util::calcPhasesBitMask(...$enabled));
        $object = Util::getObjectFromPhasesBitMask($mask);
        self::assertSame($enabled, [
            $object->phases_prepare, $object->phases_warmUp, $object->phases_execute,
            $object->phases_analyze, $object->phases_clean,
        ]);
        self::assertSame($names, Util::getExecutedPhases($mask));
    }

    public static function phaseCombinations(): iterable
    {
        // Specification table of enabled phase names in bit order, independent of
        // the production constants used by the implementation.
        $phaseNames = ['PREPARE', 'WARMUP', 'EXECUTE', 'ANALYZE', 'CLEAN'];
        for ($mask = 0; $mask <= 31; $mask++) {
            $enabled = []; $names = [];
            foreach ([1, 2, 4, 8, 16] as $index => $bit) {
                $enabled[] = ($mask & $bit) === 0;
                if (($mask & $bit) === 0) {
                    $names[] = $phaseNames[$index];
                }
            }
            yield 'BLD-030 excluded mask ' . $mask => [$enabled, $mask, $names];
        }
    }

    public function testPhaseFlagsCanBeAddedToAnExistingDataObjectWithoutLosingItsFields(): void
    {
        $object = new stdClass();
        $object->fixture = 'kept';
        self::assertSame($object, Util::getObjectFromPhasesBitMask(2, $object));
        self::assertSame('kept', $object->fixture);
        self::assertFalse($object->phases_warmUp);
        self::assertTrue($object->phases_execute);
    }
}
