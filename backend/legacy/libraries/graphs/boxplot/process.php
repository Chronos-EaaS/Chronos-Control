<?php

/** @var $jobs \DBA\Job[][] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$parameters = Results_Library::parameterNames($parameter);
[$matrix, $seriesLabels] = Util::mergeJobs($jobs, $parameters);
[$changingParameters, $jobParameters] = Util::getDifferentParameters($jobs);
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];
$dataArray = ['datasets' => [], 'labels' => [], 'notEnoughRuns' => false];
foreach ($seriesLabels as $index => $label) {
    $color = $colors[$index % count($colors)];
    $dataArray['datasets'][] = ['label' => $label,
        'backgroundColor' => Results_Library::colorToRGBA($color, 0.5),
        'borderColor' => $color, 'borderWidth' => 1, 'data' => []];
}
foreach ($matrix as $row) {
    $representative = [];
    foreach ($row as $index => $group) {
        $values = Results_Library::metricObservations($group, $allData['plotting'] ?? '');
        $enoughRuns = count($values) >= 3;
        if (count($group) > 0) {
            if (!$enoughRuns) {
                $dataArray['notEnoughRuns'] = true;
            }
            if (count($representative) === 0) {
                $representative = $group;
            }
        }
        $dataArray['datasets'][$index]['data'][] = $enoughRuns ? Results_Library::boxPlotCalculation($values) : null;
    }
    $dataArray['labels'][] = Results_Library::groupLabel($representative, $changingParameters, $jobParameters, $parameters);
}
$plotData = json_encode($dataArray, JSON_INVALID_UTF8_SUBSTITUTE);
