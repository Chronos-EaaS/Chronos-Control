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
    /**
     * @var mixed
     */
    private $array;
    /**
     * @var string
     */
    private $goal;

    public function seekAndSwap($array, $goal, $direction) {
        if (gettype($array) == 'array') {
            foreach ($array as $element) {
                if (gettype($element) == 'array') {
                    $this->seekAndSwap($element, $goal, $direction);
                }
                else if (gettype($element) == 'string' && $element == $goal) {
                    $array = $this->swap($array, $goal, $direction);
                }
            }
            return $array;
        }
        else {
            return "error, element not found?";
        }
    }
    public function swap($array, $goal, $direction) {
        $temp = '';
        $counter = 0;
        if ($direction == 'up') {
            foreach ($array as $element) {
                if ($element == $goal && $counter > 0) {
                    $temp2 = $element;
                    $array[$temp] = $element;
                    $array[$temp2] = $temp;
                    //return $array; in place or not?
                    break;
                }
                else if ($element == $goal && $counter == 0) {
                    echo "found, but already at head";
                    break;
                }
                $counter++;
                $temp = $element;
            }
        }
        else if ($direction == 'down') {
            $temp = '';
            foreach ($array as $element) {
                if ($temp != '') { // previous element in temp2 is the goal, $element is the one to be swapped
                    echo "initiating swap...\n";
                    echo "Array before swap: \n" . $array . "\n";
                    $array[$temp] = $element;
                    $array[$element] = $temp;
                    echo "Array after swap: \n" . $array . "\n";
                    break;
                }
                if ($element == $goal && $counter < count($array)) { // TODO count(array) or -1?
                    echo "found " . $element . "\n";
                    $temp = $element;
                    //return $array; in place or not?
                }
                else if ($element == $goal && $counter == count($array)) {
                    echo "found, but already at end";
                }
                $counter++;
            }
        }
        return $array;
    }
}