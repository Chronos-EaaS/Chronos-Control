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
                                    $temparray = $jobs;
                                    $tempkey = $number;
                                    echo "Element found at key: " . $tempkey . "\n";
                                    //print_r($array, false);
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            if(isset($temparray) && isset($tempkey)) {
                echo "\nInitiating swap.. Key: " . $tempkey . "\n";
                $temparray = $this->swap($temparray, $goal, $tempkey, $direction);
                echo "Returning the array: \n";
                print_r($temparray, false);
                return $temparray;
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
    public function swap($array, $goal, $goalkey, $direction) {
        //echo "swap received '" . $direction . "' and this array: \n";
        //print_r($array, false);
        $temp = '';
        $tempkey = 0;
        $counter = 0;
        if ($direction == 'up') {
            foreach ($array as $key => $element) {
                if ($element == $goal && $counter > 0) {
                    $array[$tempkey] = $element;
                    $array[$goalkey] = $temp;
                    return $array;
                }
                else if ($element == $goal && $counter == 0) {
                    echo "found, but already at head";
                    return $array;
                }
                $counter++;
                $temp = $element;
                $tempkey = $key;
            }
        }
        else if ($direction == 'down') {
            $temp = '';
            foreach ($array as $key => $element) {
                if ($temp != '') { // previous element in temp2 is the goal, $element is the one to be swapped
                    //echo "Array before swap: \n";
                    //print_r($array, false);
                    $array[$goalkey] = $element;
                    $array[$key] = $temp;
                    //echo "Array after swap: \n";
                    //print_r($array, false);
                    return $array;
                }
                // if element is at last position, $temp will be set but nothing is swapped, foreach is over
                else if ($key == $goalkey) {
                    //echo "Found array containing the value at key: " . $key . "\n";
                    $temp = $element;
                }
            }
        }
        return $array;
    }
}