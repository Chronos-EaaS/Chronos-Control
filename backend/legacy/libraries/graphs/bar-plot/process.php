<?php

/** @var $jobs \DBA\Job[][] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$parameters = Results_Library::parameterNames($parameter);
[$changingParameters, $jobParameters] = Util::getDifferentParameters($jobs);
$dataset = ['label' => 'Runtime', 'borderColor' => '#00a65a', 'backgroundColor' => '#00a65a', 'data' => []];
$labels = [];
foreach ($jobs as $group) {
    if (count($group) === 0) {
        continue;
    }
    foreach ($parameters as $name) {
        $dataset['data'][] = Results_Library::mean(Results_Library::metricObservations($group, $name));
        $labels[] = count($parameters) > 1
            ? $name : Results_Library::groupLabel($group, $changingParameters, $jobParameters, $parameters);
    }
}
$plotData = json_encode(['datasets' => [$dataset], 'labels' => $labels], JSON_INVALID_UTF8_SUBSTITUTE);
