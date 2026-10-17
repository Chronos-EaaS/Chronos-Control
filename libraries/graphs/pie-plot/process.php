<?php

/** @var $jobs \DBA\Job[][] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$parameters = Results_Library::parameterNames($parameter);
[$changingParameters, $jobParameters] = Util::getDifferentParameters($jobs);
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];
$dataset = ['data' => [], 'backgroundColor' => [], 'label' => 'Results'];
$labels = [];
foreach ($jobs as $group) {
    if (count($group) === 0) {
        continue;
    }
    foreach ($parameters as $name) {
        $dataset['data'][] = Results_Library::mean(Results_Library::metricObservations($group, $name));
        $dataset['backgroundColor'][] = $colors[count($labels) % count($colors)];
        $labels[] = count($parameters) > 1
            ? $name : Results_Library::groupLabel($group, $changingParameters, $jobParameters, $parameters);
    }
}
$plotData = json_encode(['datasets' => [$dataset], 'labels' => $labels], JSON_INVALID_UTF8_SUBSTITUTE);
