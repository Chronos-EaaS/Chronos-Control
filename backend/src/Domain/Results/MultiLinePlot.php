<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

final class MultiLinePlot implements PlotRenderer
{
    public function render(array $jobs, array $definition): array
    {
        $parameters = Statistics::parameterNames($definition['parameter']);
        [$matrix, $seriesLabels] = JobGroups::mergeJobs($jobs, $parameters);
        [$changingParameters, $jobParameters] = JobGroups::getDifferentParameters($jobs);
        $colors = ['#00c0ef', '#3c8dbc', '#f56954'];
        $dataArray = ['datasets' => [], 'labels' => []];
        foreach ($seriesLabels as $index => $label) {
            $color = $colors[$index % count($colors)];
            $dataArray['datasets'][] = ['label' => $label,
                'backgroundColor' => $color, 'borderColor' => $color, 'fill' => false, 'data' => []];
        }
        foreach ($matrix as $row) {
            $representative = [];
            foreach ($row as $index => $group) {
                // Each run contributes once, regardless of iteration-parameter count.
                $values = Statistics::metricObservations($group, $definition['plotting'] ?? '');
                $dataArray['datasets'][$index]['data'][] = Statistics::mean($values);
                if (count($group) > 0 && count($representative) === 0) {
                    $representative = $group;
                }
            }
            $dataArray['labels'][] = Statistics::groupLabel($representative, $changingParameters, $jobParameters, $parameters);
        }
        return $dataArray;
    }
}
