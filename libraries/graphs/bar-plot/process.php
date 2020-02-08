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

$dataArray = [];
$runtimeChartData = [];
$runtimeChartData['datasets'] = [];
$runtimeChartData['labels'] = [];
foreach($jobs as $group) {
    $results = [];
    foreach($group as $job){
        $results[] = json_decode($job->getResult(), true);
    }
    if (is_array($parameter)) {
        foreach ($parameter as $p) {
            if (isset($results[0][$p])) {
                array_push($runtimeChartData['labels'], $p);
                $sum = 0;
                foreach($results as $r){
                    $sum += $r[$p];
                }
                array_push($dataArray, floatval($sum/sizeof($group)));
            }
        }
    } else if (isset($results[0][$parameter])) {
        array_push($runtimeChartData['labels'], "Job " . $group[0]->getInternalId());
        $sum = 0;
        foreach($results as $r){
            $sum += $r[$parameter];
        }
        array_push($dataArray, floatval($sum/sizeof($group)));
    }
}
$runtimeChartData['datasets'][] = [];
$runtimeChartData['datasets'][0]['label'] = "Runtime";
$runtimeChartData['datasets'][0]['borderColor'] = "#00a65a";
$runtimeChartData['datasets'][0]['backgroundColor'] = "#00a65a";
$runtimeChartData['datasets'][0]['data'] = $dataArray;

$plotData = json_encode($runtimeChartData);