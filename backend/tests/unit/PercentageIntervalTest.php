<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Systems\ParameterSchema;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Domain\Parameters\PercentageIntervalProcessor;
use Chronos\Http\HttpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentageIntervalTest extends TestCase
{
    private function schema(int $base, array $intervals): array
    {
        $elements = [['id' => 'base', 'name' => 'Base', 'type' => 'integer', 'parameter' => 'total', 'default' => $base]];
        foreach ($intervals as $parameter => $definition) {
            $elements[] = array_replace(['id' => $parameter, 'name' => $parameter, 'type' => 'percentage-interval',
                'parameter' => $parameter, 'dependency' => 'total', 'parameter-type' => 'float'], $definition);
        }
        return ParameterSchema::validate([['id' => 'group', 'title' => 'Group', 'elements' => $elements]]);
    }

    private function compile(array $groups, array $execution = [], int $limit = 100): array
    {
        return ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ParameterCompiler::values($groups, []), $execution, $limit);
    }

    private function assertValues(array $expected, array $jobs, string $parameter = 'share'): void
    {
        self::assertCount(count($expected), $jobs);
        foreach ($expected as $index => $value) {
            self::assertIsFloat($jobs[$index]['parameters'][$parameter]);
            self::assertEqualsWithDelta($value, $jobs[$index]['parameters'][$parameter], 1e-12);
        }
    }

    public function testAdvertisedFloatIntervalGeneratesFractionalValues(): void
    {
        $groups = $this->schema(3, ['share' => ['default-start' => 10, 'default-end' => 30, 'default-step' => 10]]);
        $this->assertValues([0.3, 0.6, 0.9], $this->compile($groups));
    }

    public function testDescendingFloatIntervalKeepsTheExistingNormalizedOrder(): void
    {
        $groups = $this->schema(3, ['share' => ['default-start' => 30, 'default-end' => 10, 'default-step' => -10]]);
        $this->assertValues([0.3, 0.6, 0.9], $this->compile($groups));
    }

    public function testFloatEndpointRoundingDoesNotDropTheLastJob(): void
    {
        $groups = $this->schema(1, ['share' => ['default-start' => 10, 'default-end' => 30, 'default-step' => 10]]);
        $this->assertValues([0.1, 0.2, 0.3], $this->compile($groups));
    }

    public function testFloatIntervalStopsBeforeAnUnalignedEndValue(): void
    {
        $groups = $this->schema(1, ['share' => ['default-start' => 10, 'default-end' => 35, 'default-step' => 10]]);
        $this->assertValues([0.1, 0.2, 0.3], $this->compile($groups));
    }

    public function testSharedDependencyUsesFractionalSignedStepsWithoutMultiplyingJobs(): void
    {
        $groups = $this->schema(3, [
            'share' => ['default-start' => 10, 'default-end' => 30, 'default-step' => 10],
            'remainder' => ['default-start' => 90, 'default-end' => 70, 'default-step' => -10],
        ]);
        $jobs = $this->compile($groups);
        $this->assertValues([0.3, 0.6, 0.9], $jobs);
        $this->assertValues([2.7, 2.4, 2.1], $jobs, 'remainder');
        self::assertSame([1, 2, 3], array_column($jobs, 'total-step'));
        foreach ($jobs as $job) self::assertEqualsWithDelta(3, $job['parameters']['share'] + $job['parameters']['remainder'], 1e-12);
    }

    public function testAConstantFractionalValueAllowsZeroStep(): void
    {
        $groups = $this->schema(3, ['share' => ['default-start' => 10, 'default-end' => 10, 'default-step' => 0]]);
        $jobs = $this->compile($groups);
        $this->assertValues([0.3], $jobs);
        self::assertArrayNotHasKey('total-step', $jobs[0]);
    }

    public static function distributions(): array
    {
        return [['alter'], ['order']];
    }

    #[DataProvider('distributions')]
    public function testFractionalExpansionAndRunsRespectTheExactJobLimit(string $distribution): void
    {
        $groups = $this->schema(1, ['share' => ['default-start' => 10, 'default-end' => 30, 'default-step' => 10]]);
        $jobs = $this->compile($groups, ['runs' => 2, 'distribution' => $distribution], 6);
        self::assertCount(6, $jobs);
        $this->expectException(HttpError::class);
        $this->compile($groups, ['runs' => 2, 'distribution' => $distribution], 5);
    }

    public function testAWellFormedTinyStepIsRejectedBeforeAllocatingAnUnboundedRange(): void
    {
        $groups = $this->schema(1, ['share' => ['default-start' => 10, 'default-end' => 20, 'default-step' => '0.0000000000000001']]);
        try { $this->compile($groups, [], 100); self::fail('The range exceeds the configured limit.'); }
        catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('parameters', $error->fields);
        }
    }

    public function testIntegerIntervalsKeepTheirExistingFloorAndWireTypes(): void
    {
        $groups = $this->schema(3, ['share' => ['parameter-type' => 'int', 'default-start' => 0, 'default-end' => 100, 'default-step' => 50]]);
        $jobs = $this->compile($groups);
        self::assertSame([0, 1, 2, 3], array_column(array_column($jobs, 'parameters'), 'share'));
        self::assertSame([1, 2, 3, 4], array_column($jobs, 'total-step'));
    }

    public function testAStandaloneProcessorPreservesExistingIntegerConfigurationShape(): void
    {
        $jobs = [['parameters' => []]];
        (new PercentageIntervalProcessor())->process(['share-start' => 10, 'share-end' => 30, 'share-step' => 10, 'share-dependency' => 'total'], 'share', $jobs);
        self::assertSame([
            ['parameters' => ['share' => 10], 'total-step' => 1],
            ['parameters' => ['share' => 20], 'total-step' => 2],
            ['parameters' => ['share' => 30], 'total-step' => 3],
        ], $jobs);
    }

    public static function invalidSteps(): array
    {
        return [[10, 30, 0], [10, 30, -10], [30, 10, 10]];
    }

    #[DataProvider('invalidSteps')]
    public function testInvalidFloatStepsRemainActionableValidationFailures(int $start, int $end, int $step): void
    {
        $groups = $this->schema(3, ['share' => ['default-start' => $start, 'default-end' => $end, 'default-step' => $step]]);
        try { $this->compile($groups); self::fail('The invalid direction or zero step was accepted.'); }
        catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('parameters.share-step', $error->fields);
        }
    }
}
