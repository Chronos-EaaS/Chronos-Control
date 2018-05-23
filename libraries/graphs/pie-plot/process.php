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

$dataArray = [];
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];

$colorIndex = 0;
foreach ($jobs as $job) {
    $results = json_decode($job->getResult(), true);
    if (is_array($parameter)) {
        foreach ($parameter as $p) {
            if (isset($results[$p])) {
                $entry = ['value' => floatval($results[$p]), 'color' => $colors[$colorIndex], 'highlight' => $colors[$colorIndex], 'label' => $p];
                $colorIndex++;
                array_push($dataArray, $entry);
            }
        }
    } else if (isset($results[$parameter])) {
        $entry = ['value' => floatval($results[$parameter]), 'color' => $colors[$colorIndex], 'highlight' => $colors[$colorIndex], 'label' => "Job " . $job->getInternalId()];
        $colorIndex++;
        array_push($dataArray, $entry);
    }
}

$plotData = json_encode($dataArray);