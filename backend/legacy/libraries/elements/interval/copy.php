<?php

/**
 * Given values:
 * - $e: settings of the element from the parameter configuration
 * - $copyData: full postData from the experiment to copy
 * - $copyValue: write in here the copied value which is needed on the form to fill in
 */

/** @var $e array */
/** @var $copyData array */
/** @var $copyValue string */

// when generating multi-job, we need to copy the current configurations and apply our setting for every of them
// we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
if (!empty($copyData[$e['id'] . "-parameter"])) { // special handle for checkbox selections
    $min = false;
    $max = false;
    $step = false;
    if (isset($copyData[$copyData[$e['id'] . "-parameter"] . "-min"])) {
        $min = $copyData[$copyData[$e['id'] . "-parameter"] . "-min"];
    }
    if (isset($copyData[$copyData[$e['id'] . "-parameter"] . "-max"])) {
        $max = $copyData[$copyData[$e['id'] . "-parameter"] . "-max"];
    }
    if (isset($copyData[$copyData[$e['id'] . "-parameter"] . "-step"])) {
        $step = $copyData[$copyData[$e['id'] . "-parameter"] . "-step"];
    }
    $copyValue = [$min, $max, $step];
}