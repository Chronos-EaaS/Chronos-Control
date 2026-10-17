<?php

/** @var $jobs \DBA\Job[][] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$dataArray = ['datasets' => [], 'labels' => []];
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];
$parameters = Results_Library::parameterNames($parameter);
$groups = array_values(array_filter($jobs, static function ($group) { return count($group) > 0; }));
[$changingParameters, $jobParameters] = Util::getDifferentParameters($groups);

if (count($parameters) === 1 && Results_Library::hasArrayMetric($groups, $parameters[0])) {
    $width = 0;
    foreach ($groups as $index => $group) {
        $values = Results_Library::arrayMetricMeans($group, $parameters[0]);
        $width = max($width, count($values));
        $color = $colors[$index % count($colors)];
        $label = $parameters[0];
        if (count($groups) > 1) {
            $label .= ' - ' . Results_Library::groupLabel($group, $changingParameters, $jobParameters);
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
            $dataset['data'][] = Results_Library::mean(Results_Library::metricObservations($group, $name));
        }
        $dataArray['datasets'][] = $dataset;
    }
    foreach ($groups as $group) {
        $dataArray['labels'][] = Results_Library::groupLabel($group, $changingParameters, $jobParameters, $parameters);
    }
}
$plotData = json_encode($dataArray, JSON_INVALID_UTF8_SUBSTITUTE);
