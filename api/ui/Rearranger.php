<?php
/*
The MIT License (MIT)

Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */
class Rearranger {
    public function seekAndSwap($array, $goal, $direction, $resultId) {
        if (gettype($array) == 'array') {
            foreach ($array as $key => $subarray) {
                //echo "key: " . $key . "resultId: " . $resultId . "\n";
                if ($key == $resultId) { // Only change order in the current result config
                    foreach ($subarray as $jobtype => $jobs) {
                        if(gettype($jobs) == 'array') {
                            foreach ($jobs as $number => $job) {
                                $found = $this->searchInside($job, $goal);
                                if ($found) {
                                    echo "\nInitiating swap.. Key: " . $number . "\n";
                                    $array[$key][$jobtype] = $this->swap($jobs, $goal, $number, $direction);
                                    echo "Element found at key: " . $number . "\n";
                                    //print_r($array, false);
                                    return $array;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $array;
    }
    public function searchInside ($subarray, $goal): bool
    {
        //echo "goal: " . $goal . "\n";
        //print_r($subarray, false);
        if (gettype($subarray) == 'array') {
            foreach ($subarray as $key => $element) {
                if (gettype($element) == 'string' && $element == $goal) {
                    echo "Found " . $element . "\n";
                    return true;
                }
            }
            return false;
        }
        return false;
    }
    public function swap($jobs, $goal, $goalkey, $direction) {
        //echo "swap received '" . $direction . "' and this array: \n";
        //print_r($array, false);
        $temp = '';
        $tempkey = 0;
        $counter = 0;
        if ($direction == 'up') {
            foreach ($jobs as $number => $job) {
                if ($number == $goalkey && $counter > 0) {
                    $jobs[$tempkey] = $job;
                    $jobs[$goalkey] = $temp;
                    return $jobs;
                }
                else if ($number == $goalkey && $counter == 0) {
                    echo "found, but already at head";
                    return $jobs;
                }
                $counter++;
                $temp = $job;
                $tempkey = $number;
            }
        }
        else if ($direction == 'down') {
            $tempjob = '';
            foreach ($jobs as $number => $job) {
                if ($tempjob != '') { // previous element in temp2 is the goal, $element is the one to be swapped
                    //echo "Array before swap: \n";
                    //print_r($array, false);
                    $jobs[$goalkey] = $job;
                    $jobs[$number] = $tempjob;
                    //echo "Array after swap: \n";
                    //print_r($jobs, false);
                    return $jobs;
                }
                // if element is at last position, $temp will be set but nothing is swapped, foreach is over
                else if ($number == $goalkey) {
                    //echo "Found array containing the value at key: " . $key . "\n";
                    $tempjob = $job;
                }
            }
        }
        return $jobs;
    }
}