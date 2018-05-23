<?php

/**
 * Given values:
 * - $data: containing the full data of the results
 * - $parameter(s): containing the name(s)/basename(s) of the parameter which this plot should parse
 * - $plotData: put here the values which later will be passed to the plot
 */

use DBA\Job;

/** @var $jobs Job[] */
/** @var $parameter string|string[] */
/** @var $plotData string */

$dataArray = ['datasets' => []];
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];

$parameterData = [];
$labels = [];

$colorIndex = 0;
foreach ($jobs as $job) {
    $results = json_decode($job->getResult(), true);
    if (is_array($parameter)) {
        foreach ($parameter as $p) {
            if (isset($results[$p])) {
                if (isset($parameterData[$p])) {
                    $parameterData[$p]['data'][] = floatval($results[$p]);
                } else {
                    $parameterData[$p] = [];
                    $parameterData[$p]['data'] = [floatval($results[$p])];
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
        $labels[] = "Job " . $job->getInternalId();
    } else if (isset($results[$parameter])) {
        if (isset($results[$parameter])) {
            if (isset($parameterData[$parameter])) {
                $parameterData[$parameter]['data'][] = floatval($results[$parameter]);
            } else {
                $parameterData[$parameter] = [];
                $parameterData[$parameter]['data'] = [floatval($results[$parameter])];
                $parameterData[$parameter]['label'] = "Job " . $job->getInternalId();
                $parameterData[$parameter]['fillColor'] = $colors[$colorIndex];
                $parameterData[$parameter]['strokeColor'] = $colors[$colorIndex];
                $parameterData[$parameter]['pointColor'] = $colors[$colorIndex];
                $parameterData[$parameter]['pointStrokeColor'] = $colors[$colorIndex];
                $parameterData[$parameter]['pointHighlightFill'] = '#fff';
                $parameterData[$parameter]['pointHighlightStroke'] = $colors[$colorIndex];
                $colorIndex++;
            }
            $labels[] = "Job " . $job->getInternalId();
        }
    }
}

foreach ($parameterData as $pData) {
    $dataArray['datasets'][] = $pData;
    $dataArray['labels'] = $labels;
}

$plotData = json_encode($dataArray);