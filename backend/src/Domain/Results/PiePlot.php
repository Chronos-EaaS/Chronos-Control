<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

final class PiePlot implements PlotRenderer
{
    public function render(array $jobs, array $definition): array
    {
        $parameters = Statistics::parameterNames($definition['parameter']);
        [$changingParameters, $jobParameters] = JobGroups::getDifferentParameters($jobs);
        $colors = ['#00c0ef', '#3c8dbc', '#f56954'];
        $dataset = ['data' => [], 'backgroundColor' => [], 'label' => 'Results'];
        $labels = [];
        foreach ($jobs as $group) {
            if (count($group) === 0) {
                continue;
            }
            foreach ($parameters as $name) {
                $dataset['data'][] = Statistics::mean(Statistics::metricObservations($group, $name));
                $dataset['backgroundColor'][] = $colors[count($labels) % count($colors)];
                $labels[] = count($parameters) > 1
                    ? $name : Statistics::groupLabel($group, $changingParameters, $jobParameters, $parameters);
            }
        }
        return ['datasets' => [$dataset], 'labels' => $labels];
    }
}
