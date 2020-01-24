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
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];

$colorIndex = 0;
foreach($jobs as $group) {
    $results = [];
    foreach ($group as $job) {
        $results[] = json_decode($job->getResult(), true);
    }
    if (is_array($parameter)) {
        foreach ($parameter as $p) {
            if (isset($results[0][$p])) {
                $sum = 0;
                foreach($results as $r){
                    $sum += $r[$p];
                }
                $entry = ['value' => floatval($sum/sizeof($group)), 'color' => $colors[$colorIndex], 'highlight' => $colors[$colorIndex], 'label' => $p];
                $colorIndex++;
                array_push($dataArray, $entry);
            }
        }
    } else if (isset($results[0][$parameter])) {
        $sum = 0;
        foreach($results as $r){
            $sum += $r[$parameter];
        }
        $entry = ['value' => floatval($sum/sizeof($group)), 'color' => $colors[$colorIndex], 'highlight' => $colors[$colorIndex], 'label' => "Job " . $group[0]->getInternalId()];
        $colorIndex++;
        array_push($dataArray, $entry);
    }
}

$plotData = json_encode($dataArray);