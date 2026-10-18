<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

final class LinePlot implements PlotRenderer
{
    public function render(array $jobs, array $definition): array
    {
        $dataArray = ['datasets' => [], 'labels' => []];
        $colors = ['#00c0ef', '#3c8dbc', '#f56954'];
        $parameters = Statistics::parameterNames($definition['parameter']);
        $groups = array_values(array_filter($jobs, static function ($group) { return count($group) > 0; }));
        [$changingParameters, $jobParameters] = JobGroups::getDifferentParameters($groups);

        if (count($parameters) === 1 && Statistics::hasArrayMetric($groups, $parameters[0])) {
            $width = 0;
            foreach ($groups as $index => $group) {
                $values = Statistics::arrayMetricMeans($group, $parameters[0]);
                $width = max($width, count($values));
                $color = $colors[$index % count($colors)];
                $label = $parameters[0];
                if (count($groups) > 1) {
                    $label .= ' - ' . Statistics::groupLabel($group, $changingParameters, $jobParameters);
                }
                $dataArray['datasets'][] = [
                    'data' => $values, 'label' => $label,
                    'backgroundColor' => $color, 'borderColor' => $color, 'fill' => false,
                ];
            }
            $dataArray['labels'] = $width > 0 ? range(0, $width - 1) : [];
            foreach ($dataArray['datasets'] as &$dataset) {
                $dataset['data'] = array_pad($dataset['data'], $width, null);
            }
            unset($dataset);
        } else {
            foreach ($parameters as $index => $name) {
                $color = $colors[$index % count($colors)];
                $dataset = ['data' => [], 'label' => $name,
                    'backgroundColor' => $color, 'borderColor' => $color, 'fill' => false];
                foreach ($groups as $group) {
                    $dataset['data'][] = Statistics::mean(Statistics::metricObservations($group, $name));
                }
                $dataArray['datasets'][] = $dataset;
            }
            foreach ($groups as $group) {
                $dataArray['labels'][] = Statistics::groupLabel($group, $changingParameters, $jobParameters, $parameters);
            }
        }
        return $dataArray;
    }
}
