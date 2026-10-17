<?php

/** @var $jobs \DBA\Job[][] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$parameters = Results_Library::parameterNames($parameter);
[$matrix, $seriesLabels] = Util::mergeJobs($jobs, $parameters);
[$changingParameters, $jobParameters] = Util::getDifferentParameters($jobs);
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
        $values = Results_Library::metricObservations($group, $allData['plotting'] ?? '');
        $dataArray['datasets'][$index]['data'][] = Results_Library::mean($values);
        if (count($group) > 0 && count($representative) === 0) {
            $representative = $group;
        }
    }
    $dataArray['labels'][] = Results_Library::groupLabel($representative, $changingParameters, $jobParameters, $parameters);
}
$plotData = json_encode($dataArray, JSON_INVALID_UTF8_SUBSTITUTE);
