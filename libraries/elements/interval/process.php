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
if (intval($data[$parameter . "-step"]) == 0) {
    throw new Exception("Invalid step size causing endless loop!");
}
for ($i = intval($data[$parameter . "-min"]); $i <= intval($data[$parameter . "-max"]); $i += intval($data[$parameter . "-step"])) {
    foreach ($allCDL as $cdl) {
        $ccdl = clone $cdl;
        $eval = $ccdl->getEvaluation();
        $eval->appendChild($ccdl->createElement($parameter, $i));
        $newCDL[] = $ccdl;
    }
}
$allCDL = $newCDL;