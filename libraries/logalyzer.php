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
     * Reads a submitted logLine and updates the database object with a new result json object
     * @param $logLine
     * @return void
     */
    public function examineLogLine($logLine) {
        $start = microtime(true);
        $hash = $this->calculateHash();
        // Load existing result set
        $this->results = json_decode($this->job->getLogalyzerResults(), true);

        foreach($this->data['pattern'] as $index => $pattern) {
            $number = $this->countLogOccurances($pattern['pattern'], $logLine, $pattern['regex']);
            $isInResultSet = false;
            foreach($this->results['pattern'] as $result) {
                // Check if the result has been previously set in the job's result
                if (isset($result['logLevel'], $result['pattern'], $result['regex'], $result['type']) && $pattern['logLevel'] === $result['logLevel'] && $pattern['pattern'] === $result['pattern'] && $pattern['regex'] === $result['regex'] && $pattern['type'] === $result['type']) {
                    $isInResultSet = true;
                    if ($number >= 1) {
                        Factory::getJobFactory()->incrementJobCountAtomically($this->job->getId(), $pattern['pattern'], $number);
                    }
                }
            }
            if(!$isInResultSet) {
                $pattern['count'] = $number;
                $this->results['pattern'][] = $pattern;
                if($this->results['hash'] === "" || $this->results['hash'] === null) {
                    $this->results['hash'] = $hash;
                }
                $this->job->setLogalyzerResults(json_encode($this->results));
                Factory::getJobFactory()->update($this->job);
            }
        }
        $end = microtime(true);
        if($logLine == "SendMail\n") {
            $this->mailResults();
        }
        else {
            $this->logTime($start, $end);
        }
    }
    private function mailResults() {
        $to = "p.buetler@stud.unibas.ch";
        $subj = "Eval Results";
        $from = 'chronos@stud.unibas.ch';
        $from_name = 'chronos';
        $path = UPLOADED_DATA_PATH . '/log/time.log';
        mail($to, $subj, file_get_contents($path));
        // Empty the file afterwards
        file_put_contents($path, "");

    }
    private function logTime($start, $end) {
        $path = UPLOADED_DATA_PATH . '/log/time.log';
        file_put_contents($path, (($end-$start)*1000).",", FILE_APPEND);
    }
    /**
     * Creates empty pattern for a system
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

    /**
     * Populates the local variables for a new Job Result
     * @return void
     */
    private function createEmptyJobLogalyzerResults() {
        $this->results['hash'] = "";
        $this->results['pattern'] = array();
    }

    /**
     * @return string
     */
    function calculateHash() {
        return hash('sha1', json_encode($this->data['pattern']));
    }
}
