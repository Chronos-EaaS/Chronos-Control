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
$newConfigurations = [];

$numOfVersions = $data[$parameter . "-numberOfVersions"];
for ($i = 0; $i < $numOfVersions; $i++) {
    foreach ($allConfigurations as $configuration) {
        $copy = $configuration;
        $copy[Define::CONFIGURATION_PARAMETERS][$parameter] = $data[$parameter . "-version-" . $i];
        $newConfigurations[] = $copy;
    }
}


$allConfigurations = $newConfigurations;