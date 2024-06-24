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
    public function seekAndSwap($array, $goal, $direction) {
        if (gettype($array) == 'array') {
            foreach ($array as $key => $value) {
                $goalkey = $this->searchInside($value, $goal);
                if ($goalkey >= 0) {
                    $temparray = $array;
                    $tempkey = $key;
                    $goalkey = -1;
                    echo "Goal key is " . $tempkey . "\n";
                    echo "Initiating swap.. \n";
                    break;
                } else {
                    foreach ($array as $element) {
                        if (gettype($element) == 'array') {
                            $this->seekAndSwap($element, $goal, $direction);
                        }
                    }
                }
            }
            if(isset($temparray) && $goalkey >= 0) {
                return $this->swap($temparray, $goal, $goalkey, $direction);
            }
        }
        return $array;
    }
    public function searchInside ($subarray, $goal) {
        if (gettype($subarray) == 'array') {
            foreach ($subarray as $key => $element) {
                if (gettype($element) == 'string' && $element == $goal) {
                    echo "Array: \n" . print_r($subarray, false);
                    echo "Found " . $element . " at key: ". $key . "\n";
                    return $key;
                }
            }
            return -1;
        }
        return -1;
    }
    public function swap($array, $goal, $goalkey, $direction) {
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
                    echo "Array before swap: \n" . print_r($array, false) . "\n";
                    $array[$goalkey] = $element;
                    $array[$key] = $temp;
                    echo "Array after swap: \n" . print_r($array, false) . "\n";
                    return $array;
                }
                // if element is at last position, $temp will be set but nothing is swapped, foreach is over
                else if ($element == $goal) {
                    echo "Found " . $element . " at key: " . $key . "\n";
                    $temp = $element;
                }
            }
        }
        return $array;
    }
}