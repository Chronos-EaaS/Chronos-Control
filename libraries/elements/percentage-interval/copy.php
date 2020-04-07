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
    $start = "";
    $end = "";
    $step = "";
    if (isset($copyData[$copyData[$e['id'] . "-parameter"] . "-percentage-start"])) {
        $start = $copyData[$copyData[$e['id'] . "-parameter"] . "-percentage-start"];
    }
    if (isset($copyData[$copyData[$e['id'] . "-parameter"] . "-percentage-end"])) {
        $end = $copyData[$copyData[$e['id'] . "-parameter"] . "-percentage-end"];
    }
    if (isset($copyData[$copyData[$e['id'] . "-parameter"] . "-percentage-step"])) {
        $step = $copyData[$copyData[$e['id'] . "-parameter"] . "-percentage-step"];
    }
    $copyValue = [$start, $end, $step];
}