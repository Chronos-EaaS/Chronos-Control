<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

final class BarPlot implements PlotRenderer
{
    public function render(array $jobs, array $definition): array
    {
        $parameters = Statistics::parameterNames($definition['parameter']);
        [$changingParameters, $jobParameters] = JobGroups::getDifferentParameters($jobs);
        $dataset = ['label' => 'Runtime', 'borderColor' => '#00a65a', 'backgroundColor' => '#00a65a', 'data' => []];
        $labels = [];
        foreach ($jobs as $group) {
            if (count($group) === 0) {
                continue;
            }
            foreach ($parameters as $name) {
                $dataset['data'][] = Statistics::mean(Statistics::metricObservations($group, $name));
                $labels[] = count($parameters) > 1
                    ? $name : Statistics::groupLabel($group, $changingParameters, $jobParameters, $parameters);
            }
        }
        return ['datasets' => [$dataset], 'labels' => $labels];
    }
}
