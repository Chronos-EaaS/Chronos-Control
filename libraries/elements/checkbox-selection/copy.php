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
if (!empty($copyData[$e['id'] . "-parameter"])) { // special handle for checkbox selections
    $arr = [];
    foreach ($copyData as $key => $value) {
        if (strpos($key, $copyData[$e['id'] . "-parameter"] . "-") === 0 && $copyData[$key] == 'on') {
            $arr[] = str_replace($copyData[$e['id'] . "-parameter"] . "-", "", $key);
        }
    }
    $copyValue = implode(",", $arr);
}