<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

final class BoxPlot implements PlotRenderer
{
    public function render(array $jobs, array $definition): array
    {
        $parameters = Statistics::parameterNames($definition['parameter']);
        [$matrix, $seriesLabels] = JobGroups::mergeJobs($jobs, $parameters);
        [$changingParameters, $jobParameters] = JobGroups::getDifferentParameters($jobs);
        $colors = ['#00c0ef', '#3c8dbc', '#f56954'];
        $dataArray = ['datasets' => [], 'labels' => [], 'notEnoughRuns' => false];
        foreach ($seriesLabels as $index => $label) {
            $color = $colors[$index % count($colors)];
            $dataArray['datasets'][] = ['label' => $label,
                'backgroundColor' => Statistics::colorToRGBA($color, 0.5),
                'borderColor' => $color, 'borderWidth' => 1, 'data' => []];
        }
        foreach ($matrix as $row) {
            $representative = [];
            foreach ($row as $index => $group) {
                $values = Statistics::metricObservations($group, $definition['plotting'] ?? '');
                $enoughRuns = count($values) >= 3;
                if (count($group) > 0) {
                    if (!$enoughRuns) {
                        $dataArray['notEnoughRuns'] = true;
                    }
                    if (count($representative) === 0) {
                        $representative = $group;
                    }
                }
                $dataArray['datasets'][$index]['data'][] = $enoughRuns ? Statistics::boxPlotCalculation($values) : null;
            }
            $dataArray['labels'][] = Statistics::groupLabel($representative, $changingParameters, $jobParameters, $parameters);
        }
        return $dataArray;
    }
}
