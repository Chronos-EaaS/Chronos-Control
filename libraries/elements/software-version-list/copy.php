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
    $parameterName = $copyData[$e['id'] . "-parameter"];
    if (empty($copyData[$parameterName . "-numberOfVersions"])) {
        var_dump($copyData);
        throw new Exception("Invalid number of versions!");
    }
    $numberOfVersions = $copyData[$parameterName . "-numberOfVersions"];

    $copyValue = [];
    for ($i = 0; $i < $numberOfVersions; $i++) {
        $copyValue[$i] = $copyData[$parameterName . "-version-" . $i];
    }
}