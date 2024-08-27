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

use DBA\Factory;

/**
 * Analyze the log of a Chronos job
 * Keywords can be customized in the 'Systems' UI
 *
 * Results are displayed inside a job's detail page
 */
class Logalyzer_Library {
    private $job;
    private $system;
    private $log;
    private $system_pattern;
    private $job_pattern;

    /**
     * Logalyzer constructor
     * Initializes a Logalyzer object.
     *
     * Variant 1: If used in conjunction with a specific job it takes the job as parameter
     * Actions involving a specific job, such as analyzing log contents require the job parameter.
     *
     * Variant 2: If not used in conjunction with a specific job no parameter is needed.
     * This second variant is often used for UI interaction such as creation/deletion of patterns for a system.
     *
     * @param Job $job The Chronos Job being analyzed by the class object created here
     */
    public function __construct($job = null) {
        $this->job = $job;
        if($this->job != null) {
            $this->system = Factory::getSystemFactory()->get($this->job->getSystemId());
            $this->loadPatterns();

        }
    }

    /**
     * Returns the Job of this Logalyzer Instance, might return Null if no job has been defined
     * @return Job|null
     */
    public function getJob() {
        return $this->job;
    }
    /**
     * Returns the System of this Logalyzer Instance
     * @return DBA\System
     */
    public function getSystem() {
        return $this->system;
    }

    /**
     * Select the desired system and fetch the stored patterns from the database
     * @param DBA\System $system The system to fetch patterns from
     * @return void
     */
    public function setSystemAndLoadPattern($system) {
        $this->system = $system;
        $this->loadPatterns();
    }

    /**
     * Allows retroactively setting a Job for an existing Logalyzer object.
     * @param DBA\Job $job The Job to analyze
     * @return void
     */
    public function setJob($job) {
        $this->job = $job;
    }
    /**
     * Searches the $keyword in the $target using an appropriate search function for the type defined by $regex
     * @param string $keyword Pattern to be searched
     * @param string $target Target text, such as a log file or log line
     * @param string $regex Takes the form of 'string' or 'regex'.
     * @return int  Returns the amount of occurrences of $keyword in $target as an integer.
     */
    public function countLogOccurances(string $keyword, string $target, string $regex) {
        if ($regex === 'regex') {
            return preg_match_all($keyword, $target);
        } elseif ($regex === 'string') {
            return substr_count($target, $keyword);
        }
        else {
            return 0;
        }
    }

    /**
     * Checks the hash of a system's patterns and the patterns used to analyze a Job.
     * Returns true if the hash values differ.
     * @return bool
     */
    private function checkHashDifference() {
        $results = json_decode($this->job->getLogalyzerResults(), true);
        return !($results['hash'] === hash('sha1', json_encode($this->system_pattern)));
    }

    /**
     * Load and read the entire logfile counting the occurrences of the pattern, saving the result in the database
     * @return void
     */
    public function examineEntireLog() {
        $path = UPLOADED_DATA_PATH . '/log/' . $this->job->getId() . '.log';
        $log = Util::readFileContents($path);
        if ($log === false) {
            $this->log = "";
        } else {
            $this->log = $log;
        }
        $this->createEmptyJobLogalyzerResults();
        $hash = $this->calculateSystemHash();

        foreach($this->system_pattern['result'] as $pattern) {
            $number = $this->countLogOccurances($pattern['pattern'], $this->log, $pattern['regex']);
            $found = false;
            foreach($this->job_pattern['result'] as $result) {
                if(isset($result['logLevel'],$result['pattern'],$result['regex'],$result['type']) && $pattern['logLevel'] === $result['logLevel'] && $pattern['pattern'] === $result['pattern'] && $pattern['regex'] === $result['regex'] && $pattern['type'] === $result['type']) {
                    $result['count'] += $number;
                    $found = true;
                }
            }
            if(!$found) {
                $pattern['count'] = $number;
                $this->job_pattern['result'][] = $pattern;
            }
        }
        $this->job_pattern['hash'] = $hash;
        $this->job->setLogalyzerResults(json_encode($this->job_pattern));
        Factory::getJobFactory()->update($this->job);
    }

    /**
     * Reads a submitted log line and updates the database object with a new result json object
     * @param $logLine
     * @return void
     */
    public function examineLogLine($logLine) {
        // Load existing result set
        $json = $this->system->getLogalyzerPatterns();
        if($json === null) {
            return;
        }
        $json = $this->job->getLogalyzerResults();
        if($json === null) {
            $this->createEmptyJobLogalyzerResults();
        }
        else {
            $this->job_pattern = json_decode($json, true);
        }
        $hash = $this->calculateSystemHash();
        $resultCollection = [];
        $LOG_ERRORS_MAX = 50; // TODO change to constant from constants.php
        foreach($this->system_pattern['result'] as $index => $pattern) {
            $number = $this->countLogOccurances($pattern['pattern'], $logLine, $pattern['regex']);
            $isInResultSet = false;
            foreach($this->job_pattern['result'] as $result) {
                // Check if the result has been previously set in the job's result
                if (isset($result['logLevel'], $result['pattern'], $result['regex'], $result['type']) && $pattern['logLevel'] === $result['logLevel'] && $pattern['pattern'] === $result['pattern'] && $pattern['regex'] === $result['regex'] && $pattern['type'] === $result['type'] && $result['count'] < $LOG_ERRORS_MAX) {
                    $isInResultSet = true;
                    if ($number >= 1) {
                        $pattern['count'] = $number;
                        $resultCollection[] = $pattern;
                    }
                }
            }
            if(!$isInResultSet) {
                $pattern['count'] = $number;
                $this->job_pattern['result'][] = $pattern;
                if($this->job_pattern['hash'] === "" || $this->job_pattern['hash'] === null) {
                    $this->job_pattern['hash'] = $hash;
                }

                $this->job->setLogalyzerResults(json_encode($this->job_pattern));
                Factory::getJobFactory()->update($this->job);
            }
        }
        if(!empty($resultCollection)) {
            Factory::getJobFactory()->incrementJobCountAtomically($this->job->getId(), $resultCollection);
        }
    }
    
    /**
     * Creates empty pattern for a system
     * @return void
     */
    private function createBasicPatterns() {
        $this->system_pattern['hash'] = "";
        $this->system_pattern['result'] = array();
    }

    /**
     * Fetch a systems' pattern as an array
     * $identifier can be 'all', or the desired logLevel such as 'warn' or 'error'
     * @param string $logLevel
     * @param string $type 'regex' or 'string'
     * @return array
     */
    public function getPatterns(string $logLevel, string $type) {
        if ($this->system_pattern == null) {
            $this->createBasicPatterns();
        }
        if ($logLevel === 'all') {
            return $this->system_pattern['result'];
        } else {
            $temp = [];
            foreach ($this->system_pattern['result'] as $pattern) {
                if ($pattern['logLevel'] == $logLevel && $pattern['type'] == $type) {
                    $temp[] = $pattern;
                }
            }
            return $temp;
        }
    }

    /**
     * Fetches the json object containing the systems pattern from the database.
     * Decodes the json object and populates local variables or creates an empty pattern if database returns null object
     * @return void
     */
    public function loadPatterns() {
        $patterns = $this->system->getLogalyzerPatterns();
        if (isset($patterns) && $patterns == null) {
            $this->system_pattern = json_decode($patterns, true);
        }
        else {
            // No patterns have been created for this system yet
            $this->createBasicPatterns();
        }
    }

    /**
     * Saves a modified pattern set to the systems database table
     * Is called whenever patterns changed
     * Hash value is updated
     * @return void
     */
    private function savePatterns() {
        $this->system_pattern['hash'] = hash('sha1', json_encode($this->system_pattern['result']));
        $this->system->setLogalyzerPatterns(json_encode($this->system_pattern));
        Factory::getSystemFactory()->update($this->system);
    }
    /**
     * Adds a new pattern defined in the System UI and saves it to the System database table if it is no duplicate
     * @param string $logLevel
     * @param string $pattern 'the pattern string'
     * @param string $regex 'string' or 'regex'
     * @param string $type 'positive' or 'negative'
     * @return void
     */
    public function addKey(string $logLevel, string $pattern, string $regex, string $type) {
        if($this->system == null) {
            echo 'System not defined\n';
        }
        else {
            $array = array('logLevel' => $logLevel, 'pattern' => $pattern, 'regex' => $regex, 'type' => $type);
            print_r($array);
            // Duplicate check
            if(!in_array($array, $this->system_pattern['result'])) {
                $this->system_pattern['result'][] = $array;
                $this->savePatterns();
            }
        }
    }

    /**
     * Search and drop the corresponding pattern for a System
     * @param string $logLevel currently supports 'warn' and 'error'
     * @param string $pattern 'the pattern string'
     * @param string $type 'positive' or 'negative'
     * @return void
     */
    public function removeKey(string $logLevel, string $pattern, string $type)  {
        if($this->system == null) {
            echo 'System not defined\n';
        }
        else {
            $array = array('logLevel' => $logLevel, 'pattern' => $pattern, 'regex' => 'string', 'type' => $type);
            // Check if it is a normal string
            if(in_array($array, $this->system_pattern['result'])) {
                $index = array_search($array, $this->system_pattern['result']);
                unset($this->system_pattern['result'][$index]);
                $this->savePatterns();
            }
            // Check if it is a regex
            else {
                $array['regex'] = 'regex';
                if(in_array($array, $this->system_pattern['result'])) {
                    $index = array_search($array, $this->system_pattern['result']);
                    unset($this->system_pattern['result'][$index]);
                    $this->savePatterns();
                }
            }
        }
    }

    /**
     * Populates the local variables for a new Job Result
     * @return void
     */
    private function createEmptyJobLogalyzerResults() {
        $this->job_pattern['hash'] = "";
        $this->job_pattern['result'] = array();
    }

    /**
     * Calculates the System hash on the fly
     * @return string
     */
    function calculateSystemHash() {
        return hash('sha1', json_encode($this->system_pattern['result']));
    }
}
