<?php

/**
 * Given values:
 * - $data: containing the full data of the results
 * - $parameter(s): containing the name(s)/basename(s) of the parameter which this plot should parse
 * - $plotData: put here the values which later will be passed to the plot
 */

use DBA\Job;

/** @var $jobs Job[][] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$dataArray = ['datasets' => []];
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];

$parameterData = [];
$labels = [];

$colorIndex = 0;
foreach($jobs as $group) {
    $results = [];
    foreach($group as $job){
        $results[] = json_decode($job->getResult(), true);
    }
    if (is_array($parameter)) {
        foreach ($parameter as $p) {
            if (isset($results[0][$p])) {
                $sum = 0;
                foreach($results as $r){
                    $sum += $r[$p];
                }
                if (isset($parameterData[$p])) {
                    $parameterData[$p]['data'][] = floatval($sum/sizeof($group));
                } else {
                    $parameterData[$p] = [];
                    $parameterData[$p]['data'] = [floatval($sum/sizeof($group))];
                    $parameterData[$p]['label'] = $p;
                    $parameterData[$p]['fillColor'] = $colors[$colorIndex];
                    $parameterData[$p]['strokeColor'] = $colors[$colorIndex];
                    $parameterData[$p]['pointColor'] = $colors[$colorIndex];
                    $parameterData[$p]['pointStrokeColor'] = $colors[$colorIndex];
                    $parameterData[$p]['pointHighlightFill'] = '#fff';
                    $parameterData[$p]['pointHighlightStroke'] = $colors[$colorIndex];
                    $colorIndex++;
                }
            }
        }
        $labels[] = "Job " . $group[0]->getInternalId();
    } else if (isset($results[0][$parameter])) {
        $sum = 0;
        foreach($results as $r){
            $sum += $r[$parameter];
        }
        if (isset($parameterData[$parameter])) {
            $parameterData[$parameter]['data'][] = floatval($sum/sizeof($group));
        } else {
            $parameterData[$parameter] = [];
            $parameterData[$parameter]['data'] = [floatval($sum/sizeof($group))];
            $parameterData[$parameter]['label'] = "Job " . $job->getInternalId();
            $parameterData[$parameter]['fillColor'] = $colors[$colorIndex];
            $parameterData[$parameter]['strokeColor'] = $colors[$colorIndex];
            $parameterData[$parameter]['pointColor'] = $colors[$colorIndex];
            $parameterData[$parameter]['pointStrokeColor'] = $colors[$colorIndex];
            $parameterData[$parameter]['pointHighlightFill'] = '#fff';
            $parameterData[$parameter]['pointHighlightStroke'] = $colors[$colorIndex];
            $colorIndex++;
        }
        $labels[] = "Job " . $group[0]->getInternalId();
    }
}

foreach ($parameterData as $pData) {
    $dataArray['datasets'][] = $pData;
    $dataArray['labels'] = $labels;
}

$plotData = json_encode($dataArray);