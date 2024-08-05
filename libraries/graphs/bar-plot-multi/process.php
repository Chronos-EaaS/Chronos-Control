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

$arr = Util::getDifferentParameters($jobs);
$changingParameters = $arr[0];
$jobParameters = $arr[1];

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
        $label = [];
        foreach ($changingParameters as $changingParameter) {
            if ($changingParameter == $parameter) {
                continue;
            } else if ($changingParameter == 'run') {
                continue;
            }
            $label[] = $changingParameter . ": " . $jobParameters[$group[0]->getId()][$changingParameter];
        }
        array_push($runtimeChartData['labels'], "Jobs[" . implode(", ", $label) . "]");
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