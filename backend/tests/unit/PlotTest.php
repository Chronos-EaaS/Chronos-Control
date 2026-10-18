<?php

declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Tests\Support\JobFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Chronos\Domain\Results\BuiltinPlots;
use Chronos\Domain\Results\Statistics;
use Chronos\Domain\Results\JobGroups;

final class PlotTest extends TestCase
{
    private static function renderData(string $directory, array $groups, array $definition): array
    {
        // Assert the public JSON chart shape, including JSON numeric semantics.
        return json_decode(json_encode(BuiltinPlots::render($directory, $groups, $definition),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheRegistryExposesAllFiveSupportedChartTypes(): void
    {
        $types = BuiltinPlots::types();
        sort($types);
        self::assertSame(['bar-plot', 'box-plot', 'line-plot', 'line-plot-multi', 'pie-plot'], $types);
    }

    #[DataProvider('simplePlotKinds')]
    public function testScalarPlotsAverageRunsWithoutLosingConfigurations(string $directory): void
    {
        $data = self::renderData($directory, JobFixture::groups(), ['parameter' => 'latency']);

        // Independently known means: (0+4+8)/3=4, then 8, 12 and 16.
        self::assertSame([4, 8, 12, 16], $data['datasets'][0]['data']);
        self::assertSame([
            'Jobs[workers: 1, batch: small]', 'Jobs[workers: 2, batch: small]',
            'Jobs[workers: 1, batch: large]', 'Jobs[workers: 2, batch: large]',
        ], $data['labels']);
        self::assertStringNotContainsString('run:', json_encode($data['labels'], JSON_THROW_ON_ERROR));
    }

    public static function simplePlotKinds(): iterable
    {
        yield 'PLT-001 bar' => ['bar-plot'];
        yield 'PLT-002 pie' => ['pie-plot'];
        yield 'PLT-003 line' => ['line-plot'];
    }

    public function testBarPlotCanCompareMultipleMetricsInEachConfiguration(): void
    {
        $data = self::renderData('bar-plot', JobFixture::groups(), ['parameter' => 'latency,throughput']);
        self::assertSame([4, 60, 8, 24, 12, 6, 16, 30], $data['datasets'][0]['data']);
        self::assertSame(['latency', 'throughput', 'latency', 'throughput',
            'latency', 'throughput', 'latency', 'throughput'], $data['labels']);
    }

    public function testPiePlotMatchesItsLegendAndValuesAcrossMultipleMetrics(): void
    {
        $data = self::renderData('pie-plot', JobFixture::groups(), ['parameter' => 'latency,throughput']);
        self::assertSame([4, 60, 8, 24, 12, 6, 16, 30], $data['datasets'][0]['data']);
        self::assertCount(8, $data['datasets'][0]['backgroundColor']);
        self::assertSame(['latency', 'throughput', 'latency', 'throughput',
            'latency', 'throughput', 'latency', 'throughput'], $data['labels']);
    }

    public function testLinePlotCreatesOneSeriesPerMetric(): void
    {
        $data = self::renderData('line-plot', JobFixture::groups(), ['parameter' => 'latency,throughput']);
        self::assertCount(2, $data['datasets']);
        self::assertSame('latency', $data['datasets'][0]['label']);
        self::assertSame([4, 8, 12, 16], $data['datasets'][0]['data']);
        self::assertSame('throughput', $data['datasets'][1]['label']);
        self::assertSame([60, 24, 6, 30], $data['datasets'][1]['data']);
    }

    public function testLinePlotAveragesEachPositionOfAnArrayResult(): void
    {
        $jobs = [[
            JobFixture::make(1, ['run' => 0], ['samples' => '[2, 4, 6]']),
            JobFixture::make(2, ['run' => 1], ['samples' => '[6, 8, 10]']),
        ]];
        $data = self::renderData('line-plot', $jobs, ['parameter' => 'samples']);
        self::assertSame([4, 6, 8], $data['datasets'][0]['data']);
        self::assertSame([0, 1, 2], $data['labels']);
    }

    public function testMultiParameterLinePlotGroupsSeriesByWorkersAndLabelsByBatch(): void
    {
        $data = self::renderData('line-plot-multi', JobFixture::groups(), [
            'parameter' => 'workers', 'plotting' => 'latency',
        ]);
        self::assertSame(['Jobs[batch: small]', 'Jobs[batch: large]'], $data['labels']);
        self::assertSame([1, 2], array_column($data['datasets'], 'label'));
        self::assertSame([4, 12], $data['datasets'][0]['data']);
        self::assertSame([8, 16], $data['datasets'][1]['data']);
    }

    public function testBoxPlotRetainsOneDistributionPerConfiguration(): void
    {
        $data = self::renderData('box-plot', JobFixture::groups(), [
            'parameter' => 'workers', 'plotting' => 'latency',
        ]);
        self::assertFalse($data['notEnoughRuns']);
        self::assertSame(['Jobs[batch: small]', 'Jobs[batch: large]'], $data['labels']);
        self::assertSame([1, 2], array_column($data['datasets'], 'label'));
        self::assertSame([
            'lower_outlier' => 0, 'min' => 0, 'q1' => 0, 'median' => 4,
            'q3' => 8, 'max' => 8, 'higher_outlier' => 8,
            'whiskerMin' => 0, 'whiskerMax' => 8, 'outliers' => [],
        ], $data['datasets'][0]['data'][0]);
        self::assertSame(12, $data['datasets'][0]['data'][1]['median']);
        self::assertSame(8, $data['datasets'][1]['data'][0]['median']);
        self::assertSame(16, $data['datasets'][1]['data'][1]['median']);
    }

    public function testBoxPlotReportsInsufficientRunsAndBecomesReadyWhenDataArrives(): void
    {
        $groups = JobFixture::groups();
        $definition = ['parameter' => 'workers', 'plotting' => 'latency'];
        $partialGroups = array_map(static fn (array $group): array => array_slice($group, 0, 2), $groups);
        $partial = self::renderData('box-plot', $partialGroups, $definition);
        self::assertTrue($partial['notEnoughRuns']);
        self::assertSame([null, null], $partial['datasets'][0]['data']);
        self::assertFalse(self::renderData('box-plot', $groups, $definition)['notEnoughRuns']);
    }

    public function testConfigurationGroupingAcceptsArrayAndSerializedConfigurationRepresentations(): void
    {
        $first = JobFixture::make(1, ['workers' => 1, 'run' => 0], ['latency' => '2']);
        $second = JobFixture::make(2, ['workers' => 2, 'run' => 0], ['latency' => '6'], false);
        [$changing, $parameters] = JobGroups::getDifferentParameters([[$first], [$second]]);
        self::assertSame(['workers'], $changing);
        self::assertSame([1 => ['workers' => 1, 'run' => 0], 2 => ['workers' => 2, 'run' => 0]], $parameters);
        self::assertIsArray($first->getConfiguration(), 'Legacy job generation relies on in-memory JSON normalization.');
        self::assertSame(['workers' => 1, 'run' => 0], $first->getConfiguration()['parameters']);
    }

    public function testMultipleIterationParametersMustNotDuplicateThePlottedMetric(): void
    {
        $data = self::renderData('line-plot-multi', JobFixture::groups(), [
            'parameter' => 'workers,batch', 'plotting' => 'latency',
        ]);
        self::assertSame(['Jobs[]'], $data['labels']);
        self::assertSame([
            'workers: 1, batch: small', 'workers: 2, batch: small',
            'workers: 1, batch: large', 'workers: 2, batch: large',
        ], array_column($data['datasets'], 'label'));
        self::assertSame([[4], [8], [12], [16]], array_column($data['datasets'], 'data'));
    }

    public function testArrayPlotLabelsCoverAllAveragedPositionsWhenRunsHaveDifferentLengths(): void
    {
        $data = self::renderData('line-plot', [[
            JobFixture::make(1, ['run' => 0], ['samples' => '[2, 4, 6]']),
            JobFixture::make(2, ['run' => 1], ['samples' => '[6, 8]']),
        ]], ['parameter' => 'samples']);
        self::assertSame([4, 6, 6], $data['datasets'][0]['data']);
        self::assertSame([0, 1, 2], $data['labels']);
    }

    public function testBoxPlotQuartilesRetainFractionsInAnOddSample(): void
    {
        $statistics = Statistics::boxPlotCalculation([1, 2, 3, 4, 5]);
        self::assertSame(1.5, $statistics['q1']);
        self::assertSame(4.5, $statistics['q3']);
    }

    public function testBoxPlotWhiskersAreObservedSamplesWithinTheFences(): void
    {
        $statistics = Statistics::boxPlotCalculation([10, 14, 18]);
        self::assertSame(10.0, $statistics['whiskerMin']);
        self::assertSame(18.0, $statistics['whiskerMax']);
        self::assertSame([], $statistics['outliers']);
    }

    #[DataProvider('boxPlotSamples')]
    public function testBoxStatisticsUseTukeyMedianOfHalvesAndObservedWhiskers(
        array $sample,
        array $expected,
    ): void {
        $original = $sample;
        $statistics = Statistics::boxPlotCalculation($sample);
        self::assertNotNull($statistics);
        foreach ($expected as $field => $value) {
            if (is_array($value)) {
                self::assertEquals($value, $statistics[$field], $field);
            } else {
                self::assertEqualsWithDelta($value, $statistics[$field], 1e-12, $field);
            }
        }
        self::assertSame($original, $sample, 'Statistics must not sort or rewrite raw input.');
    }

    public static function boxPlotSamples(): iterable
    {
        yield 'odd excludes the central observation' => [
            [5, 1, 4, 2, 3], ['q1' => 1.5, 'median' => 3, 'q3' => 4.5, 'whiskerMin' => 1, 'whiskerMax' => 5, 'outliers' => []],
        ];
        yield 'even splits into equal halves' => [
            [1, 2, 3, 4, 5, 6], ['q1' => 2, 'median' => 3.5, 'q3' => 5, 'whiskerMin' => 1, 'whiskerMax' => 6, 'outliers' => []],
        ];
        yield 'four observations retain both middle points' => [
            [1, 2, 3, 4], ['q1' => 1.5, 'median' => 2.5, 'q3' => 3.5],
        ];
        yield 'two observations' => [[1, 2], ['q1' => 1, 'median' => 1.5, 'q3' => 2]];
        yield 'one observation' => [[7], ['q1' => 7, 'median' => 7, 'q3' => 7, 'whiskerMin' => 7, 'whiskerMax' => 7]];
        yield 'negative and zero observations' => [
            [-10, -8, -4, 0, 2], ['q1' => -9, 'median' => -4, 'q3' => 1, 'min' => -10, 'max' => 2, 'outliers' => []],
        ];
        yield 'zero interquartile range' => [
            [0, 0, 0, 0, 0, 0, 0, 0, 100], ['q1' => 0, 'median' => 0, 'q3' => 0, 'whiskerMin' => 0, 'whiskerMax' => 0, 'outliers' => [100]],
        ];
        yield 'both tails outside 1.5 IQR fences' => [
            [-100, 0, 1, 2, 3, 4, 5, 6, 7, 8, 100],
            ['min' => -100, 'lower_outlier' => -100, 'q1' => 1, 'median' => 4, 'q3' => 7,
                'max' => 100, 'higher_outlier' => 100, 'whiskerMin' => 0, 'whiskerMax' => 8, 'outliers' => [-100, 100]],
        ];
        yield 'observations exactly on a fence are inliers' => [
            [-6, 0, 1, 2, 3, 4, 10], ['q1' => 0, 'q3' => 4, 'whiskerMin' => -6, 'whiskerMax' => 10, 'outliers' => []],
        ];
        yield 'decimal observations' => [[0.1, 0.2, 0.3, 0.4], ['q1' => 0.15, 'median' => 0.25, 'q3' => 0.35]];
        yield 'missing values are not zero observations' => [
            [null, [], 'invalid', '4', '8', '12'], ['q1' => 4, 'median' => 8, 'q3' => 12, 'min' => 4, 'max' => 12],
        ];
    }

    public function testEmptyAndNonFiniteBoxSamplesHaveNoStatistics(): void
    {
        self::assertNull(Statistics::boxPlotCalculation([]));
        self::assertNull(Statistics::boxPlotCalculation([null, [], '', 'invalid', INF, -INF, NAN]));
    }

    public function testFiniteMeanAndMedianDoNotOverflowAnIntermediateSum(): void
    {
        self::assertSame(PHP_FLOAT_MAX, Statistics::mean([PHP_FLOAT_MAX, PHP_FLOAT_MAX]));
        self::assertSame(0.0, Statistics::boxPlotCalculation([-PHP_FLOAT_MAX, PHP_FLOAT_MAX])['median']);
        self::assertEqualsWithDelta(1 / 3, Statistics::mean([1e16, 1, -1e16]), 1e-15);
        self::assertSame(5e-324, Statistics::boxPlotCalculation([5e-324, 5e-324])['median']);
    }

    #[DataProvider('simplePlotKinds')]
    public function testScalarPlotsAverageOnlyValidSamplesAndKeepMissingGroupsAligned(string $directory): void
    {
        $groups = [
            [JobFixture::make(1, ['workers' => 1, 'run' => 0], []),
                JobFixture::make(2, ['workers' => 1, 'run' => 1], ['latency' => 0]),
                JobFixture::make(3, ['workers' => 1, 'run' => 2], ['latency' => 10])],
            [JobFixture::make(4, ['workers' => 2, 'run' => 0], ['latency' => null]),
                JobFixture::make(5, ['workers' => 2, 'run' => 1], ['latency' => 'invalid']),
                JobFixture::make(6, ['workers' => 2, 'run' => 2], ['latency' => []])],
        ];
        $data = self::renderData($directory, $groups, ['parameter' => 'latency']);
        self::assertSame([5, null], $data['datasets'][0]['data']);
        self::assertSame(['Jobs[workers: 1]', 'Jobs[workers: 2]'], $data['labels']);
    }

    public function testInvalidResultDocumentsAndNonFiniteNumbersAreExcludedFromTheMean(): void
    {
        $group = [];
        foreach (['broken JSON', 'null', null, '{"latency":1e999}', '{"latency":[]}', '{"latency":0}', '{"latency":4}'] as $id => $raw) {
            $job = JobFixture::make($id + 1, ['run' => $id], []);
            $job->setResult($raw);
            $group[] = $job;
        }
        $data = self::renderData('line-plot', [$group], ['parameter' => 'latency']);
        self::assertSame([2], $data['datasets'][0]['data']);
    }

    public function testArrayMeansSupportNativeAndStringArraysAndMissingPositions(): void
    {
        $data = self::renderData('line-plot', [[
            JobFixture::make(1, ['run' => 0], ['samples' => [2, null, 'invalid', 'missing']]),
            JobFixture::make(2, ['run' => 1], ['samples' => '[6,8,10,12,14]']),
            JobFixture::make(3, ['run' => 2], []),
        ]], ['parameter' => 'samples']);
        self::assertSame([4, 8, 10, 12, 14], $data['datasets'][0]['data']);
        self::assertSame([0, 1, 2, 3, 4], $data['labels']);
    }

    public function testMissingArrayPositionsAreGapsAndZeroIsStillAValidObservation(): void
    {
        $data = self::renderData('line-plot', [[
            JobFixture::make(1, ['run' => 0], ['samples' => '[0,null]']),
            JobFixture::make(2, ['run' => 1], ['samples' => '[0,"missing"]']),
        ]], ['parameter' => 'samples']);
        self::assertSame([0, null], $data['datasets'][0]['data']);
        self::assertSame([0, 1], $data['labels']);
    }

    public function testArrayResultsFromDifferentConfigurationsRemainSeparateSeries(): void
    {
        $data = self::renderData('line-plot', [
            [JobFixture::make(1, ['workers' => 1, 'run' => 0], ['samples' => '[1,2,3]'])],
            [JobFixture::make(2, ['workers' => 2, 'run' => 0], ['samples' => '[4,5]'])],
        ], ['parameter' => 'samples']);
        self::assertSame([[1, 2, 3], [4, 5, null]], array_column($data['datasets'], 'data'));
        self::assertSame(['samples - Jobs[workers: 1]', 'samples - Jobs[workers: 2]'], array_column($data['datasets'], 'label'));
        self::assertSame([0, 1, 2], $data['labels']);
    }

    public function testSparseIterationCombinationsCannotShiftAValueIntoAnotherSeries(): void
    {
        $groups = JobFixture::groups();
        $sparse = [$groups[3], $groups[0], $groups[2]];
        $definition = ['parameter' => 'workers', 'plotting' => 'latency'];
        $line = self::renderData('line-plot-multi', $sparse, $definition);
        self::assertSame([2, 1], array_column($line['datasets'], 'label'));
        self::assertSame(['Jobs[batch: large]', 'Jobs[batch: small]'], $line['labels']);
        self::assertSame([[16, null], [12, 4]], array_column($line['datasets'], 'data'));
        $box = self::renderData('box-plot', $sparse, $definition);
        self::assertFalse($box['notEnoughRuns'], 'A missing configuration is not a run with a missing metric.');
        self::assertNull($box['datasets'][0]['data'][1]);
        self::assertSame(4, $box['datasets'][1]['data'][1]['median']);
    }

    public function testIterationParameterCountDoesNotInventExtraRunsForABoxPlot(): void
    {
        $groups = array_map(static fn (array $group): array => array_slice($group, 0, 2), JobFixture::groups());
        $box = self::renderData('box-plot', $groups, ['parameter' => 'workers,batch', 'plotting' => 'latency']);
        self::assertTrue($box['notEnoughRuns']);
        self::assertSame([[null], [null], [null], [null]], array_column($box['datasets'], 'data'));
    }

    public function testMissingMetricDoesNotCountAsABoxPlotObservation(): void
    {
        $group = [JobFixture::make(1, ['workers' => 1, 'run' => 0], ['latency' => 2]),
            JobFixture::make(2, ['workers' => 1, 'run' => 1], ['latency' => 4]),
            JobFixture::make(3, ['workers' => 1, 'run' => 2], [])];
        $box = self::renderData('box-plot', [$group], ['parameter' => 'workers', 'plotting' => 'latency']);
        self::assertTrue($box['notEnoughRuns']);
        self::assertSame([null], $box['datasets'][0]['data']);
    }

    public function testEveryProcessorHandlesEmptyInputWithoutWarningsOrInventedValues(): void
    {
        foreach (['bar-plot', 'pie-plot', 'line-plot', 'line-plot-multi', 'box-plot'] as $directory) {
            $data = self::renderData($directory, [[], []], ['parameter' => 'workers', 'plotting' => 'latency']);
            self::assertSame([], $data['labels'], $directory);
            foreach ($data['datasets'] as $dataset) {
                self::assertSame([], $dataset['data'], $directory);
            }
        }
    }

    public function testCalculationsNeverRewriteRawResultPayloads(): void
    {
        $groups = JobFixture::groups();
        $jobs = array_merge(...$groups);
        $raw = array_map(static fn ($job) => $job->getResult(), $jobs);
        foreach (['bar-plot', 'pie-plot', 'line-plot', 'line-plot-multi', 'box-plot'] as $directory) {
            self::renderData($directory, $groups, ['parameter' => 'workers', 'plotting' => 'latency']);
        }
        self::assertSame($raw, array_map(static fn ($job) => $job->getResult(), $jobs));
    }
    public function testBooleanMetricsRetainLegacyZeroOneSemanticsInAllFiveProcessors(): void
    {
        $groups = [[
            JobFixture::make(1, ['workers' => 1, 'run' => 0], ['latency' => false]),
            JobFixture::make(2, ['workers' => 1, 'run' => 1], ['latency' => true]),
            JobFixture::make(3, ['workers' => 1, 'run' => 2], ['latency' => true]),
        ]];
        foreach (['bar-plot', 'pie-plot', 'line-plot', 'line-plot-multi', 'box-plot'] as $directory) {
            $data = self::renderData($directory, $groups, [
                'parameter' => in_array($directory, ['box-plot', 'line-plot-multi'], true) ? 'workers' : 'latency',
                'plotting' => 'latency',
            ]);
            if ($directory === 'box-plot') {
                self::assertFalse($data['notEnoughRuns']);
                self::assertSame(0, $data['datasets'][0]['data'][0]['min']);
                self::assertSame(1, $data['datasets'][0]['data'][0]['median']);
            } else {
                self::assertEqualsWithDelta(2 / 3, $data['datasets'][0]['data'][0], 1e-15, $directory);
            }
        }
    }

    public function testBooleanArraySamplesRetainLegacyZeroOneSemantics(): void
    {
        $data = self::renderData('line-plot', [[
            JobFixture::make(1, ['run' => 0], ['samples' => [false, true]]),
            JobFixture::make(2, ['run' => 1], ['samples' => '[true,false]']),
        ]], ['parameter' => 'samples']);
        self::assertSame([0.5, 0.5], $data['datasets'][0]['data']);
        self::assertSame([0, 1], $data['labels']);
    }

    public function testNumericMetricIdentifiersAndOpacityRetainExistingScalarCoercion(): void
    {
        $jobs = [[JobFixture::make(1, ['run' => 0], [42 => 6]), JobFixture::make(2, ['run' => 1], [42 => 10])]];
        $chart = self::renderData('bar-plot', $jobs, ['parameter' => 42]);
        self::assertSame([8], $chart['datasets'][0]['data']);
        self::assertSame('rgba(255,0,0,0.50)', Statistics::colorToRGBA('#ff0000', '0.50'));
    }

}
