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

$arr = Util::getDifferentParameters($jobs);
$changingParameters = $arr[0];
$jobParameters = $arr[1];

$colorIndex = 0;
foreach ($jobs as $group) {
    $results = [];
    foreach ($group as $job) {
        $results[] = json_decode($job->getResult(), true);
    }
    if (is_array($parameter)) {
        foreach ($parameter as $p) {
            if (isset($results[0][$p])) {
                $sum = 0;
                foreach ($results as $r) {
                    $sum += $r[$p];
                }
                if (isset($parameterData[$p])) {
                    $parameterData[$p]['data'][] = floatval($sum / sizeof($group));
                } else {
                    $parameterData[$p] = [];
                    $parameterData[$p]['data'] = [floatval($sum / sizeof($group))];
                    $parameterData[$p]['label'] = $p;
                    $parameterData[$p]['backgroundColor'] = $colors[$colorIndex % sizeof($colors)];
                    $parameterData[$p]['borderColor'] = $colors[$colorIndex % sizeof($colors)];
                    $parameterData[$p]['fill'] = false;
                    $colorIndex++;
                }
            }
        }

        $label = [];
        foreach ($changingParameters as $changingParameter) {
            if (in_array($changingParameter, $parameter)) {
                continue;
            } else if ($changingParameter == 'run') {
                continue;
            }
            $label[] = $changingParameter . ": " . $jobParameters[$group[0]->getId()][$changingParameter];
        }
        $labels[] = "Jobs[" . implode(", ", $label) . "]";
    } else if (isset($results[0][$parameter])) {
        if (substr($results[0][$parameter], 0, 1) === "[" && substr($results[0][$parameter], -1) === "]") {
            $sums = [];
            $counts = [];
            function updateSumsAndCounts($array, &$sums, &$counts) {
                foreach ($array as $index => $value) {
                    if (isset($sums[$index])) {
                        $sums[$index] += $value;
                        $counts[$index] += 1;
                    } else {
                        $sums[$index] = $value;
                        $counts[$index] = 1;
                    }
                }
            }
            foreach ($results as $r) {
                $str = trim($r[$parameter], "[]");
                $array = explode(", ", $str);
                updateSumsAndCounts($array, $sums, $counts);
            }

            $averages = [];
            foreach ($sums as $index => $sum) {
                $averages[$index] = $sum / $counts[$index];
            }

            if (isset($parameterData[$parameter])) {
                // This should not happen. Arrays are only supported for job specific results.
                return;
            }
            $parameterData[$parameter] = [];
            $parameterData[$parameter]['data'] = $averages;
            $parameterData[$parameter]['label'] = $parameter;
            $parameterData[$parameter]['backgroundColor'] = $colors[$colorIndex % sizeof($colors)];
            $parameterData[$parameter]['borderColor'] = $colors[$colorIndex % sizeof($colors)];
            $parameterData[$parameter]['fill'] = false;
        } else {
            $sum = 0;
            foreach ($results as $r) {
                $sum += $r[$parameter];
            }
            if (isset($parameterData[$parameter])) {
                $parameterData[$parameter]['data'][] = floatval($sum / sizeof($group));
            } else {
                $parameterData[$parameter] = [];
                $parameterData[$parameter]['data'] = [floatval($sum / sizeof($group))];
                $parameterData[$parameter]['label'] = $parameter;
                $parameterData[$parameter]['backgroundColor'] = $colors[$colorIndex % sizeof($colors)];
                $parameterData[$parameter]['borderColor'] = $colors[$colorIndex % sizeof($colors)];
                $parameterData[$parameter]['fill'] = false;
                $colorIndex++;
            }
        }

        $label = [];
        foreach ($changingParameters as $changingParameter) {
            if ($changingParameter == $parameter) {
                continue;
            } else if ($changingParameter == 'run') {
                continue;
            }
            $label[] = $changingParameter . ": " . $jobParameters[$group[0]->getId()][$changingParameter];
        }
        $labels[] = "Jobs[" . implode(", ", $label) . "]";
    }
}

foreach ($parameterData as $pData) {
    $dataArray['datasets'][] = $pData;
    $dataArray['labels'] = $labels;
}

$plotData = json_encode($dataArray);