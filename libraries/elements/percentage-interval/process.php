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
if (intval($data[$parameter . "-step"]) == 0 && intval($data[$parameter . "-start"]) != intval($data[$parameter . "-end"])) {
    throw new Exception("Invalid step size causing endless loop!");
}

if ($data[$parameter . "-start"] > $data[$parameter . "-end"]) {
    $start = $data[$parameter . "-end"];
    $end = $data[$parameter . "-start"];
    $step = -$data[$parameter . "-step"];
} else {
    $start = $data[$parameter . "-start"];
    $end = $data[$parameter . "-end"];
    $step = $data[$parameter . "-step"];
}

$count = 1;
if (isset($allConfigurations[0][$data[$parameter . "-dependency"] . "-step"])) {
    foreach ($allConfigurations as $configuration) {
        $s = $configuration[$data[$parameter . "-dependency"] . "-step"];
        $value = $start + $step * ($s - 1);

        $copy = $configuration;
        $copy[Define::CONFIGURATION_PARAMETERS][$parameter] = $value;
        $newConfigurations[] = $copy;
    }
} else {
    if (intval($start) == intval($end)) {
        $step = 1;
    }
    for ($i = intval($start); $i <= intval($end); $i += intval($step), $count++) {
        foreach ($allConfigurations as $configuration) {
            $copy = $configuration;
            $copy[Define::CONFIGURATION_PARAMETERS][$parameter] = $i;
            if (intval($start) != intval($end)) {
                // if the current setting is the special case that there will be no range due to start == end,
                // we don't have steps as this might mess up if other ranges in the same dependency should do steps
                $copy[$data[$parameter . "-dependency"] . "-step"] = $count;
            }
            $newConfigurations[] = $copy;
        }
    }
}
$allConfigurations = $newConfigurations;