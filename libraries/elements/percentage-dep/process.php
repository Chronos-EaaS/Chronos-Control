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

$dep = null;
if (isset($data[$parameter . "-dependency"])) {
    $dep = $data[$parameter . "-dependency"];
}
if ($dep === null) {
    throw new Exception("No dependency for percentage dependency element defined!");
}

$percentage = $data[$parameter . "-percentage"];

$newConfigurations = [];
foreach ($allConfigurations as $configuration) {
    $copy = $configuration;
    if (!isset($copy[Define::CONFIGURATION_PARAMETERS][$dep])) {
        throw new Exception("Dependency value for percentage dependency element is not set");
    }

    $value = $copy[Define::CONFIGURATION_PARAMETERS][$dep] * $percentage / 100;
    if ($data[$parameter . "-type"] == 'int') {
        $value = floor($value);
    }
    $copy[Define::CONFIGURATION_PARAMETERS][$parameter] = $value;
    $newConfigurations[] = $copy;
}
$allConfigurations = $newConfigurations;