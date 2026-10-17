<?php

declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Tests\Support\JobFixture;
use Chronos\Tests\Support\KnownLegacyDefect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Plot;
use Results_Library;
use Util;

final class PlotTest extends TestCase
{
    use KnownLegacyDefect;

    private static function renderData(string $directory, array $groups, array $definition): array
    {
        $plot = new Plot(CHRONOS_TEST_ROOT . '/libraries/graphs/' . $directory);

        return json_decode($plot->process($groups, $definition), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheInventoryIncludesAllFiveBundledPlotProcessors(): void
    {
        $types = array_map(static fn (Plot $plot): string => $plot->getType(), Util::getDefaultResultPlots());
        sort($types);
        self::assertSame(['bar-plot', 'box-plot', 'line-plot', 'line-plot-multi', 'pie-plot'], $types);
        $boxplot = new Plot(CHRONOS_TEST_ROOT . '/libraries/graphs/boxplot');
        self::assertSame(['chartjs', 'boxplot'], $boxplot->getRequired());
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
        $data = self::renderData('boxplot', JobFixture::groups(), [
            'parameter' => 'workers', 'plotting' => 'latency',
        ]);
        self::assertFalse($data['notEnoughRuns']);
        self::assertSame(['Jobs[batch: small]', 'Jobs[batch: large]'], $data['labels']);
        self::assertSame([1, 2], array_column($data['datasets'], 'label'));
        self::assertSame([
            'lower_outlier' => 0, 'min' => 0, 'q1' => 0, 'median' => 4,
            'q3' => 8, 'max' => 8, 'higher_outlier' => 8,
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
        $partial = self::renderData('boxplot', $partialGroups, $definition);
        self::assertTrue($partial['notEnoughRuns']);
        self::assertArrayNotHasKey('data', $partial['datasets'][0]);
        self::assertFalse(self::renderData('boxplot', $groups, $definition)['notEnoughRuns']);
    }

    public function testConfigurationGroupingAcceptsArrayAndSerializedConfigurationRepresentations(): void
    {
        $first = JobFixture::make(1, ['workers' => 1, 'run' => 0], ['latency' => '2']);
        $second = JobFixture::make(2, ['workers' => 2, 'run' => 0], ['latency' => '6'], false);
        [$changing, $parameters] = Util::getDifferentParameters([[$first], [$second]]);
        self::assertSame(['workers'], $changing);
        self::assertSame([1 => ['workers' => 1, 'run' => 0], 2 => ['workers' => 2, 'run' => 0]], $parameters);
    }

    #[Group('known-defect')]
    public function testMultipleIterationParametersMustNotDuplicateThePlottedMetric(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('PLT-011', 'Legacy repeats the metric once for each iteration parameter.', function (): void {
            $data = self::renderData('line-plot-multi', JobFixture::groups(), [
                'parameter' => 'workers,batch', 'plotting' => 'latency',
            ]);
            self::assertSame(4, $data['datasets'][0]['data'][0], 'The first sample mean is four regardless of iteration parameter count.');
        });
    }

    #[Group('known-defect')]
    public function testArrayPlotLabelsMustCoverAllAveragedPositionsWhenRunsHaveDifferentLengths(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('PLT-010-ARRAY', 'Legacy takes label length from the final run instead of all positions.', function (): void {
            $data = self::renderData('line-plot', [[
                JobFixture::make(1, ['run' => 0], ['samples' => '[2, 4, 6]']),
                JobFixture::make(2, ['run' => 1], ['samples' => '[6, 8]']),
            ]], ['parameter' => 'samples']);
            self::assertSame([4, 6, 6], $data['datasets'][0]['data']);
            self::assertSame([0, 1, 2], $data['labels']);
        });
    }

    #[Group('known-defect')]
    public function testBoxPlotQuartilesMustRetainFractionsInAnOddSample(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('PLT-012-QUARTILES', 'Legacy rounds quartiles; statistical policy requires explicit correction.', function (): void {
            $statistics = Results_Library::boxPlotCalculation([1, 2, 3, 4, 5]);
            self::assertSame(1.5, $statistics['q1'], 'The lower half [1,2] has median 1.5.');
            self::assertSame(4.5, $statistics['q3'], 'The upper half [4,5] has median 4.5.');
        });
    }

    #[Group('known-defect')]
    public function testBoxPlotWhiskersMustNotExtendOutsideTheObservedSample(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('PLT-012-WHISKER', 'Legacy can place the lower whisker below the smallest observation.', function (): void {
            $statistics = Results_Library::boxPlotCalculation([10, 14, 18]);
            self::assertGreaterThanOrEqual(10, $statistics['min']);
            self::assertLessThanOrEqual(18, $statistics['max']);
        });
    }
}
