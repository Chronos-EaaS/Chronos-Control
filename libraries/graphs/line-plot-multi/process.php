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
/** @var $allData array */ //these are all the values which are posted by this plot

$dataArray = ['datasets' => []];
$colors = ['#00c0ef', '#3c8dbc', '#f56954'];

$parameterData = [];
$labels = [];

if (!is_array($parameter)) {
    $parameter = explode(",", $parameter);
}

// put together jobs
$arr = Util::mergeJobs($jobs, $parameter);
$jobGroups = $arr[0];
$internLabels = $arr[1];

$colorIndex = 0;
for ($i = 0; $i < sizeof($jobGroups[0]); $i++) {
    $parameterData[$i]['label'] = $internLabels[$i];
    $parameterData[$i]['backgroundColor'] = $colors[$colorIndex % sizeof($colors)];
    $parameterData[$i]['borderColor'] = $colors[$colorIndex % sizeof($colors)];
    $parameterData[$i]['fill'] = false;
    $colorIndex++;
}

$arr = Util::getDifferentParameters($jobs);
$changingParameters = $arr[0];
$jobParameters = $arr[1];

foreach ($jobGroups as $jobGroup) {
    /** @var $jobGroup Job[][] */
    $jobIds = [];
    $index = 0;
    foreach ($jobGroup as $j) {
        $sum = 0;
        foreach ($j as $job) {
            $results = json_decode($job->getResult(), true);
            foreach ($parameter as $p) {
                if (isset($results[$allData['plotting']])) {
                    $sum += floatval($results[$allData['plotting']]);
                }
            }
        }
        $parameterData[$index]['data'][] = $sum / sizeof($j);
        $jobIds[] = $j[0]->getInternalId();
        $index++;
    }
    $label = [];
    foreach ($changingParameters as $changingParameter) {
        if (in_array($changingParameter, $parameter)) {
            continue;
        } else if ($changingParameter == 'run') {
            continue;
        }
        $label[] = $changingParameter . ": " . $jobParameters[$jobGroup[0][0]->getId()][$changingParameter];
    }

    $labels[] = "Jobs[" . implode(", ", $label) . "]";
}

foreach ($parameterData as $pData) {
    $dataArray['datasets'][] = $pData;
    $dataArray['labels'] = $labels;
}

$plotData = json_encode($dataArray);