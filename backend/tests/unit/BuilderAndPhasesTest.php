<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Http\HttpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual workflow generation replaces the unused global Builder/form helpers. */
final class BuilderAndPhasesTest extends TestCase
{
    private static function workers(): array
    {
        return [['elements' => [['id' => 'workers', 'type' => 'interval', 'parameter' => 'workers']]]];
    }

    public function testMultipleControlsFormACartesianProductAndPreserveScalarFields(): void
    {
        $groups = [['elements' => [
            ['id' => 'dataset', 'type' => 'string-list', 'parameter' => 'dataset'],
            ['id' => 'workers', 'type' => 'interval', 'parameter' => 'workers'],
            ['id' => 'cache', 'type' => 'boolean', 'parameter' => 'cache'],
        ]]];
        $values = ['dataset' => 'alpha,beta', 'workers-min' => 1, 'workers-max' => 2, 'workers-step' => 1, 'cache' => 'false'];
        $configurations = ParameterCompiler::compile('', $groups, $values, [], 10);
        self::assertSame([
            ['parameters' => ['dataset' => 'alpha', 'workers' => 1, 'cache' => 'false', 'run' => 0]],
            ['parameters' => ['dataset' => 'beta', 'workers' => 1, 'cache' => 'false', 'run' => 0]],
            ['parameters' => ['dataset' => 'alpha', 'workers' => 2, 'cache' => 'false', 'run' => 0]],
            ['parameters' => ['dataset' => 'beta', 'workers' => 2, 'cache' => 'false', 'run' => 0]],
        ], $configurations);
    }

    public function testRunsAppliedAfterParametersAlternateConfigurationsWithinEachRun(): void
    {
        $configurations = ParameterCompiler::compile('', self::workers(),
            ['workers-min' => 1, 'workers-max' => 2, 'workers-step' => 1],
            ['runs' => 2, 'distribution' => 'alter'], 10);
        self::assertSame([
            ['parameters' => ['workers' => 1, 'run' => 0]],
            ['parameters' => ['workers' => 2, 'run' => 0]],
            ['parameters' => ['workers' => 1, 'run' => 1]],
            ['parameters' => ['workers' => 2, 'run' => 1]],
        ], $configurations);
    }

    public function testOrderedRunsKeepRunsTogetherForEachParameterValue(): void
    {
        $configurations = ParameterCompiler::compile('', self::workers(),
            ['workers-min' => 1, 'workers-max' => 2, 'workers-step' => 1],
            ['runs' => 2, 'distribution' => 'order'], 10);
        self::assertSame([
            ['parameters' => ['run' => 0, 'workers' => 1]],
            ['parameters' => ['run' => 1, 'workers' => 1]],
            ['parameters' => ['run' => 0, 'workers' => 2]],
            ['parameters' => ['run' => 1, 'workers' => 2]],
        ], $configurations);
    }

    #[DataProvider('invalidRuns')]
    public function testInvalidRunsAreRejectedWithoutChangingTheSubmittedValues(mixed $runs): void
    {
        $values = ['workers-min' => 1, 'workers-max' => 2, 'workers-step' => 1];
        $before = $values;
        try {
            ParameterCompiler::compile('', self::workers(), $values, ['runs' => $runs], 10);
            self::fail('Invalid runs were accepted.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('runs', $error->fields);
            self::assertSame($before, $values);
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
        $configurations = ParameterCompiler::compile('', self::workers(),
            ['workers-min' => 1, 'workers-max' => 1, 'workers-step' => 0], ['runs' => 2], 10);
        $configurations[0]['parameters']['workers'] = 99;
        self::assertSame(['parameters' => ['workers' => 1, 'run' => 1]], $configurations[1]);
    }
}
