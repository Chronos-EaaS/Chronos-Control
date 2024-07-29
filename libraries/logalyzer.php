<?php

use DBA\Factory;

/**
 * Analyze the log of a Chronos job
 * Keywords can be customized in the 'Systems' UI
 * Results are shown inside a job's detail page
 */
class Logalyzer_Library {
    private $job;
    private $system;
    private $log;
    private $data;
    private $results;

    /**
     * @throws Exception
     */
    public function __construct($job = null) {
        $this->job = $job;
        if($this->job != null) {
            $this->system = Factory::getSystemFactory()->get($this->job->getSystemId());
            $this->loadPatterns();

        }
    }
    public function getJob() {
        return $this->job;
    }
    public function getSystem() {
        return $this->system;
    }
    public function setSystemAndLoadPattern($system) {
        $this->system = $system;
        //$this->createBasicPatterns();
        //$this->savePatterns();
        $this->loadPatterns();
    }
    public function setJob($job) {
        $this->job = $job;
    }
    /**
     * @param string $keyword
     * @param string $target
     * @param bool $regex
     * @return int
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

    private function checkHashDifference() {
        $results = json_decode($this->job->getLogalyzerResults(), true);
        return !($results['hash'] === hash('sha1', json_encode($this->data)));
    }

    /**
     * Load and read the entire logfile counting the occurances of the pattern words and saving the result in the database
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
        $hash = $this->calculateHash();

        foreach($this->data['pattern'] as $pattern) {
            $number = $this->countLogOccurances($pattern['pattern'], $this->log, $pattern['regex']);
            $found = false;
            foreach($this->results['pattern'] as $result) {
                //print_r($result);
                if(isset($result['logLevel'],$result['pattern'],$result['regex'],$result['type']) && $pattern['logLevel'] === $result['logLevel'] && $pattern['pattern'] === $result['pattern'] && $pattern['regex'] === $result['regex'] && $pattern['type'] === $result['type']) {
                    $result['count'] += $number;
                    $found = true;
                }
            }
            if(!$found) {
                $pattern['count'] = $number;
                //print_r($pattern);
                $this->results['pattern'][] = $pattern;
            }
        }
        $this->results['hash'] = $hash;
        $this->job->setLogalyzerResults(json_encode($this->results));
        Factory::getJobFactory()->update($this->job);
    }

    /**
     * Reads a single logLine and increments error/warnings counters if a pattern word is present inside.
     * Number or occurances does not matter for now. One error logLine is considered one potential error event.
     * @param $logLine
     * @return void
     */
    public function examineLogLine($logLine) {
        //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "\n--------------------------------\n", FILE_APPEND);
        //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "Start of logalyzer checkLogline()\n", FILE_APPEND);

        $hash = $this->calculateHash();
        // Load existing result set
        $this->results = json_decode($this->job->getLogalyzerResults(), true);

        foreach($this->data['pattern'] as $index => $pattern) {
            //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "Checking pattern: " . $pattern['pattern'] . " at index: ".$index."\n", FILE_APPEND);
            $number = $this->countLogOccurances($pattern['pattern'], $logLine, $pattern['regex']);
            $isInResultSet = false;
            foreach($this->results['pattern'] as $result) {
                // Check if the result has been previously set in the job's result
                if (isset($result['logLevel'], $result['pattern'], $result['regex'], $result['type']) && $pattern['logLevel'] === $result['logLevel'] && $pattern['pattern'] === $result['pattern'] && $pattern['regex'] === $result['regex'] && $pattern['type'] === $result['type']) {
                    $isInResultSet = true;
                    if ($number >= 1) {
                        //$string = "Found result in result set, trying to increment.. " . $result["pattern"] . " by " . $number . "\n";
                        //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId() . '.log', $string, FILE_APPEND);
                        //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId() . '.log', print_r($this->results, true), FILE_APPEND);
                        Factory::getJobFactory()->incrementJobCountAtomically($this->job->getId(), $pattern['logLevel'], $pattern['pattern'], $pattern['regex'], $pattern['type'], $number);
                    }
                }
            }
            if(!$isInResultSet) {
                //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "\nNew result. Appending to result set..", FILE_APPEND);
                $pattern['count'] = $number;
                $this->results['pattern'][] = $pattern;
                if($this->results['hash'] === "" || $this->results['hash'] === null) {
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "\nHash was empty.. setting hash", FILE_APPEND);
                    $this->results['hash'] = $hash;
                }
                $this->job->setLogalyzerResults(json_encode($this->results));
                Factory::getJobFactory()->update($this->job);
                //$response1= Factory::getJobFactory()->logalyzerAppendNewResult($this->job->getId(), $pattern['logLevel'], $pattern['pattern'], $pattern['regex'], $pattern['type'], $hash, 0);
                /*if($response1 === false) {
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "\nDatabase incrementJobCountAtomically failed.\n", FILE_APPEND);
                }
                $response2 = Factory::getJobFactory()->incrementJobCountAtomically($this->job->getId(), $pattern['logLevel'], $pattern['pattern'], $pattern['regex'], $pattern['type'], $hash, $number);
                if($response2 === false) {
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "\nDatabase incrementJobCountAtomically failed.\n", FILE_APPEND);
                }*/
            }
        }
        //file_put_contents(UPLOADED_DATA_PATH . 'log/' . $this->job->getId(). '.log', "\nEnd of logalyzer checkLogline()\n", FILE_APPEND);
    }

    /**
     * Creates empty pattern objects
     * @return void
     */
    private function createBasicPatterns() {
        $this->data['hash'] = "";
        $this->data['pattern'] = array();
    }

    /**
     * Returns the arrays containing pattern
     * $identifier can be 'all', or the desired logLevel such as 'warn' or 'error'
     * @param string $logLevel
     * @param string $type 'regex' or 'string'
     * @return array
     */
    public function getPatterns(string $logLevel, string $type) {
        if ($this->data == null) {
            $this->createBasicPatterns();
        }
        if ($logLevel === 'all') {
            return $this->data['pattern'];
        } else {
            $temp = [];
            foreach ($this->data['pattern'] as $pattern) {
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
        if ($patterns != null) {
            $this->data = json_decode($patterns, true);
        }
        else {
            // Initial load of patterns returned null
            $this->createBasicPatterns();
            $this->savePatterns();
        }
    }

    /**
     * Saves a modified pattern to the systems database table
     * Is called when patterns changed
     * @return void
     */
    private function savePatterns() {
        $this->data['hash'] = hash('sha1', json_encode($this->data['pattern']));
        $this->system->setLogalyzerPatterns(json_encode($this->data));
        Factory::getSystemFactory()->update($this->system);
    }
    /**
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
            if(!in_array($array, $this->data['pattern'])) {
                $this->data['pattern'][] = $array;
                $this->savePatterns();
            }
        }
    }

    /**
     * @param string $logLevel
     * @param string $pattern 'the pattern string'
     * @param string $regex 'string' or 'regex'
     * @param string $type 'positive' or 'negative'
     * @return void
     */
    public function removeKey(string $logLevel, string $pattern, string $type)  {
        if($this->system == null) {
            echo 'System not defined\n';
        }
        else {
            $array = array('logLevel' => $logLevel, 'pattern' => $pattern, 'regex' => 'string', 'type' => $type);
            if(in_array($array, $this->data['pattern'])) {
                $index = array_search($array, $this->data['pattern']);
                unset($this->data['pattern'][$index]);
                $this->savePatterns();
            }
            else {
                $array['regex'] = 'regex';
                if(in_array($array, $this->data['pattern'])) {
                    $index = array_search($array, $this->data['pattern']);
                    unset($this->data['pattern'][$index]);
                    $this->savePatterns();
                }
            }
        }
    }
    private function createEmptyJobLogalyzerResults() {
        $this->results['hash'] = "";
        $this->results['pattern'] = array();
    }
    private function saveJobLogalyzerResults() {
        $this->job->setLogalyzerResults(json_encode($this->results));
        Factory::getJobFactory()->update($this->job);
    }
    /**
     * Allows calculating the hash before any operations are done
     * @return string
     */
    function calculateHash() {
        return hash('sha1', json_encode($this->data['pattern']));
    }
}
