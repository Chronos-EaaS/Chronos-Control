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

class Rearranger
{
    public function seekAndSwap($array, $goal, $direction, $resultId)
    {
        if (gettype($array) == 'array') {
            foreach ($array as $key => $subarray) {
                if ($key == $resultId) { // Check if we're in the right result config
                    foreach ($subarray as $jobtype => $jobs) {
                        if (gettype($jobs) == 'array') {
                            foreach ($jobs as $number => $job) {
                                // Check if this is the job we want to move
                                $found = $this->isInside($job, $goal);
                                if ($found) {
                                    $array[$key][$jobtype] = $this->swap($jobs, $number, $direction);
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

    public function isInside($subarray, $goal)
    {
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

    public function swap($jobs, $goalkey, $direction)
    {
        $temp = '';
        $tempkey = 0;
        $counter = 0;
        if ($direction == 'up') {
            foreach ($jobs as $number => $job) {
                if ($number == $goalkey && $counter > 0) {
                    $jobs[$tempkey] = $job;
                    $jobs[$goalkey] = $temp;
                    return $jobs;
                } else if ($number == $goalkey && $counter == 0) {
                    //found, but already at head
                    return $jobs;
                }
                $counter++;
                $temp = $job;
                $tempkey = $number;
            }
        } else if ($direction == 'down') {
            $tempjob = '';
            foreach ($jobs as $number => $job) {
                if ($tempjob != '') {
                    $jobs[$goalkey] = $job;
                    $jobs[$number] = $tempjob;
                    return $jobs;
                } // if element is at last position, $temp will be set but nothing is swapped, foreach is over
                else if ($number == $goalkey) {
                    $tempjob = $job;
                }
            }
        }
        return $jobs;
    }
}