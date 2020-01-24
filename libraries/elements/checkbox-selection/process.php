<?php

/**
 * Given values:
 * - $data: containing the full post data of the experiment creation
 * - $parameter: containing the name/basename of the parameter
 * - $allConfigurations: containing all current configurations defined (this value is passed by reference, this is the only way to save stuff)
 */

/** @var $data array */
/** @var $parameter string */
/** @var $allConfigurations [][] */

// when generating multi-job, we need to copy the current configurations and apply our setting for every of them
// we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
$newConfiguration = [];
foreach ($data as $param => $val) {
    if (strpos($param, $parameter . "-") === 0) {
        $opt = str_replace($parameter . "-", "", $param);
        if ($data[$parameter . "-" . $opt] == 'on') {
            foreach ($allConfigurations as $configuration) {
                $copy = $configuration;
                $copy[Define::CONFIGURATION_PARAMETERS][$parameter] = $opt;
                $newConfiguration[] = $copy;
            }
        }
    }
}

$allConfigurations = $newConfiguration;