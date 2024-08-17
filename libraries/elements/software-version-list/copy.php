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

// when copy the experiment, we need to copy the current configurations and apply our setting for every of them
if (!empty($copyData[$e['id'] . "-parameter"])) {
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
} else {
    // Migrate from old elements, if there is a string element with the same parameter name, we use it
    $copyValue = [];
    $i = 0;
    if (!empty($copyData[$e['parameter']])) {
        foreach(explode(",", $copyData[$e['parameter']]) as $version) {
            $copyValue[$i] = $version;
            $i++;
        }
    }
}