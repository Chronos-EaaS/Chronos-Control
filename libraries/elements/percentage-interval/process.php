<?php

/**
 * Given values:
 * - $data: containing the full post data of the experiment creation
 * - $parameter: containing the name/basename of the parameter
 * - $allConfigurations: containing all current configurations defined (this value is passed by reference, this is the only way to save stuff)
 */

/** @var $data array */
/** @var $parameter string */
/** @var $allConfigurations [] */

// when generating multi-job, we need to copy the current configurations and apply our setting for every of them
// we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
$newConfigurations = [];
if (intval($data[$parameter . "-step"]) == 0) {
    throw new Exception("Invalid step size causing endless loop!");
}
print_r($data);
for ($i = intval($data[$parameter . "-start"]); $i <= intval($data[$parameter . "-end"]); $i += intval($data[$parameter . "-step"])) {
    foreach ($allConfigurations as $configuration) {
        $copy = $configuration;
        $copy[Define::CONFIGURATION_PARAMETERS][$parameter] = $i;
        $newConfigurations[] = $copy;
    }
}
$allConfigurations = $newConfigurations;