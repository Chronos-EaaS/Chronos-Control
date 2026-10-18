<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Http\HttpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginalV2PercentageTest extends TestCase
{
    /** Literal fields from the original v2 percentage/render.template.html. */
    private function saved(string $base, string $percent, string $absolute): array
    {
        return ['elements' => ['base-input', 'share-input'],
            'base-input-type' => 'integer', 'base-input-parameter' => 'count', 'count' => $base,
            'share-input-type' => 'percentage', 'share-input-parameter' => 'share',
            'dependency-count' => 'share', 'share-percentage' => $percent, 'share' => $absolute,
            'runs' => '1', 'run-distribution' => 'alter'];
    }

    public static function absoluteValues(): iterable
    {
        yield 'integer' => ['80', '25', '20'];
        yield 'floored zero' => ['3', '25', '0'];
        yield 'fraction' => ['3', '25', '0.75'];
    }

    #[DataProvider('absoluteValues')]
    public function testOriginalV2OrdinaryPercentageRetainsItsSavedAbsoluteValue(string $base, string $percent, string $absolute): void
    {
        $saved = $this->saved($base, $percent, $absolute);
        $before = $saved;
        $jobs = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, $saved, 10);
        self::assertSame(['parameters' => ['count' => (int) $base, 'share' => $absolute, 'run' => 0]], $jobs[0]);
        self::assertSame('percentage', ParameterCompiler::legacyElements($saved)[1]['type']);
        self::assertSame($before, $saved);
    }

    public function testModernPercentageRecomputesWhileOriginalV2KeepsTheExactStoredAbsoluteText(): void
    {
        $saved = $this->saved('3', '25', '0.7500');
        $groups = [['id' => 'base', 'elements' => [
            ['id' => 'base-input', 'type' => 'integer', 'parameter' => 'count'],
            ['id' => 'share-input', 'type' => 'percentage', 'parameter' => 'share', 'dependency' => 'count', 'parameter-type' => 'float'],
        ]]];
        $legacy = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, $saved, 10);
        $modern = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ['count' => '3', 'share-percentage' => '25', 'share' => '999'], [], 10);
        self::assertSame('0.7500', $legacy[0]['parameters']['share']);
        self::assertSame('0.75', $modern[0]['parameters']['share']);
    }

    public function testModernPercentageStillRequiresAnAuthoritativeDependency(): void
    {
        $groups = [['id' => 'base', 'elements' => [['id' => 'share-input', 'type' => 'percentage', 'parameter' => 'share']]]];
        $this->expectException(HttpError::class);
        ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, ['share' => '20', 'share-percentage' => '25'], [], 10);
    }

    public function testOriginalV2DependingPercentageStillUsesItsTypedDependencyFields(): void
    {
        $saved = ['elements' => ['base-input', 'share-input'],
            'base-input-type' => 'integer', 'base-input-parameter' => 'count', 'count' => '3',
            'share-input-type' => 'percentage-dep', 'share-input-parameter' => 'share',
            'dependency-count' => 'share', 'share-type' => 'float', 'share-dependency' => 'count', 'share-percentage' => '25',
            'runs' => '1', 'run-distribution' => 'alter'];
        $before = $saved;
        $jobs = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, $saved, 10);
        self::assertSame(['count' => 3, 'share' => 0.75, 'run' => 0], $jobs[0]['parameters']);
        self::assertSame($before, $saved);
    }

    public function testOriginalV2PlainPercentageDoesNotClaimLegitimateDependencyAndTypeParameters(): void
    {
        $saved = $this->saved('80', '25', '20');
        $saved['elements'][] = 'description-input';
        $saved['description-input-type'] = 'string';
        $saved['description-input-parameter'] = 'share-dependency';
        $saved['share-dependency'] = 'an independent label';
        $saved['elements'][] = 'type-input';
        $saved['type-input-type'] = 'string';
        $saved['type-input-parameter'] = 'share-type';
        $saved['share-type'] = 'another independent label';
        $jobs = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, $saved, 10);
        self::assertSame(['count' => 80, 'share' => '20', 'share-dependency' => 'an independent label',
            'share-type' => 'another independent label', 'run' => 0], $jobs[0]['parameters']);
    }

    public function testOriginalV2PercentageIntervalKeepsItsAbsoluteSweepAndDependencyOrdinals(): void
    {
        $saved = ['elements' => ['base-input', 'share-input'],
            'base-input-type' => 'integer', 'base-input-parameter' => 'count', 'count' => '50',
            'share-input-type' => 'percentage-interval', 'share-input-parameter' => 'share',
            'dependency-count' => 'share', 'share-dependency' => 'count',
            'share-percentage-start' => '20', 'share-percentage-end' => '40', 'share-percentage-step' => '20',
            'share-start' => '10', 'share-end' => '20', 'share-step' => '10',
            'runs' => '1', 'run-distribution' => 'alter'];
        $before = $saved;
        $jobs = ParameterCompiler::configurations(CHRONOS_TEST_ROOT, $saved, 10);
        self::assertSame([10, 20], array_column(array_column($jobs, 'parameters'), 'share'));
        self::assertSame([50, 50], array_column(array_column($jobs, 'parameters'), 'count'));
        self::assertSame([1, 2], array_column($jobs, 'count-step'));
        self::assertSame($before, $saved);
    }
}
