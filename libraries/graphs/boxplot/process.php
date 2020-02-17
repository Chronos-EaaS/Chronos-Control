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
    $parameterData[$i]['backgroundColor'] = Results_Library::colorToRGBA($colors[$colorIndex % sizeof($colors)], 0.5);
    $parameterData[$i]['borderColor'] = $colors[$colorIndex % sizeof($colors)];
    $parameterData[$i]['borderWidth'] = 1;
    $colorIndex++;
}

$arr = Util::getDifferentParameters($jobs);
$changingParameters = $arr[0];
$jobParameters = $arr[1];
$notEnoughRuns = false;

foreach ($jobGroups as $jobGroup) {
    /** @var $jobGroup Job[][] */
    $jobIds = [];
    $index = 0;
    foreach ($jobGroup as $j) {
        $arr = [];
        foreach ($j as $job) {
            $results = json_decode($job->getResult(), true);
            foreach ($parameter as $p) {
                if (isset($results[$allData['plotting']])) {
                    $arr[] = floatval($results[$allData['plotting']]);
                }
            }
        }
        if (sizeof($arr) < 2) {
            $notEnoughRuns = true;
            break;
        }
        $parameterData[$index]['data'][] = Results_Library::boxPlotCalculation($arr);
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
$dataArray['notEnoughRuns'] = $notEnoughRuns;

$plotData = json_encode($dataArray);