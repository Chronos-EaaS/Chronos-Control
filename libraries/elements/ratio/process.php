<?php

/**
 * Given values:
 * - $data: containing the full post data of the experiment creation
 * - $parameter: containing the name/basename of the parameter
 * - $allCDL: containing all current CDLs defined (this value is passed by reference, this is the only way to save stuff)
 */

/** @var $data array */
/** @var $parameter string */
/** @var $allCDL CDL_Library[] */

// when generating multi-job, we need to copy the current CDLs and apply our setting for every of them
// we have the interval, so we need to go from min to max in steps and copy all CDLs and add the corresponding setting for each of them
$newCDL = [];

// parse ratios
$start = explode(":", str_replace("%", "", $data[$parameter . "-start"]));
$end = explode(":", str_replace("%", "", $data[$parameter . "-end"]));
$step = str_replace("%", "", $data[$parameter . "-step"]);

if (sizeof($start) != 2 || sizeof($end) != 2) {
    throw new Exception("Invalid ratio detected!");
} else if (intval($start[0]) + intval($start[1]) != 100) {
    throw new Exception("Start ration does not equal 100%!");
} else if (intval($end[0]) + intval($end[1]) != 100) {
    throw new Exception("Start ration does not equal 100%!");
} else if (intval($step) <= 0 || intval($step) > 100) {
    throw new Exception("Invalid ratio step size!");
}

$params = explode(",", $parameter);

$ratio = [intval($start[0]), intval($start[1])];
for (; $ratio[0] <= $end[0]; $ratio[0] += intval($step), $ratio[1] -= intval($step)) {
    foreach ($allCDL as $cdl) {
        $ccdl = clone $cdl;
        $eval = $ccdl->getEvaluation();
        $eval->appendChild($ccdl->createElement($params[0], $ratio[0]));
        $eval->appendChild($ccdl->createElement($params[1], $ratio[1]));
        $newCDL[] = $ccdl;
    }
}

$allCDL = $newCDL;