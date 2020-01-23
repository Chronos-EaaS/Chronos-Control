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
print_r($jobs);
foreach($jobs as $group) {
    $results = [];
    foreach($group as $job){
        $results[] = json_decode($job->getResult(), true);
    }
    foreach ($group as $job) {
        if (is_array($parameter)) {
            foreach ($parameter as $p) {
                if (isset($results[$p])) {
                    array_push($runtimeChartData['labels'], $p);
                    $sum = 0;
                    foreach($results as $r){
                        $sum += $r[$p];
                    }
                    array_push($dataArray, floatval($sum/sizeof($group)));
                }
            }
        } else if (isset($results[$parameter])) {
            array_push($runtimeChartData['labels'], "Job " . $job->getInternalId());
            $sum = 0;
            foreach($results as $r){
                $sum += $r[$parameter];
            }
            array_push($dataArray, floatval($results[$sum/sizeof($group)]));
        }
    }
}
$runtimeChartData['datasets'][] = [];
$runtimeChartData['datasets'][0]['label'] = "Runtime";
$runtimeChartData['datasets'][0]['fillColor'] = "#00a65a";
$runtimeChartData['datasets'][0]['strokeColor'] = "#00a65a";
$runtimeChartData['datasets'][0]['pointColor'] = "#00a65a";
$runtimeChartData['datasets'][0]['data'] = $dataArray;

$plotData = json_encode($runtimeChartData);