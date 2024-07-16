<?php

/**
 * Analyze the log of a Chronos job using predefined keywords
 */
class Logalyzer_Library {
    private $job;
    private $system;
    private $log;
    private $warningKeys;
    private $errorKeys;

    /**
     * @throws Exception
     */
    public function __construct($job) {
        // TODO singleton if a logalyzer for this job already exists? Is that possible?
        $this->job = $job;
        $this->system = new System($this->job->getSystemId());
        $path = UPLOADED_DATA_PATH . '/log/' . $job->getId() . '.log';
        $log = Util::readFileContents($path);
        if ($log === false) {
            $this->log = "";
        } else {
            $this->log = $log;
        }

        // Grab keyword arrays at creation of this object. Changes during a job run make the result outdated, but consistent
        $this->warningKeys = json_decode($this->system->getLogalyzerWarningKeywords());
        $this->errorKeys = json_decode($this->system->getLogalyzerErrorKeywords());
        $this->calculateAndSetHash();

    }

    /**
     * @param string $keyword
     * @param string $target
     * @param bool $regex
     * @return int
     */
    public function countLogOccurances(string $keyword, string $target, bool $regex = false) {
        if ($regex) {
            return preg_match_all($keyword, $target);
        }
        else {
            return substr_count($target, $keyword);
        }
    }
    private function checkHashDifference() {
        $errorArray = json_decode($this->system->getLogalyzerErrorKeywords);
        $warningArray = json_decode($this->system->getLogalyzerWarningKeywords);
        $mergedArray = array_merge($errorArray, $warningArray);
        $mergedJson = json_encode($mergedArray);

        if($this->job->getLogalyzerHash() == hash('sha1', $mergedJson)) {
            return false;
        }
        else {
            return true;
        }
    }
    public function examineEntireLog() {
        // Check if there have been changes to the log
        if ($this->checkHashDifference()) {
            // Count occurrences of all defined keywords.
            $warningCount = 0;
            $errorCount = 0;
            foreach ($this->warningKeys as $key) {
                if(str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                    // $key is regex
                    $warningCount += $this->countLogOccurances($key, $this->log, true);
                }
                else {
                    $warningCount += $this->countLogOccurances($key, $this->log);
                }
            }
            foreach ($this->errorKeys as $key) {
                if(str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                    // $key is regex.
                    $errorCount += $this->countLogOccurances($key, $this->log, true);
                }
                else {
                    $errorCount += $this->countLogOccurances($key, $this->log);
                }
            }
            // Log is reexamined using up-to-date keys, save new hash.
            $this->job->setLogalyzerCountWarnings($warningCount);
            $this->job->setLogalyzerCountErrors($errorCount);
            $this->calculateAndSetHash();
        }
    }
    public function examineLogLine($logLine) {
        foreach ($this->warningKeys as $key) {
            if(str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                // $key is regex
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine, true); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountWarnings();
                }
            }
            else {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountWarnings();
                }
            }
        }
        foreach ($this->errorKeys as $key) {
            if (str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                // $key is regex.
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine, true); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountWarnings();
                }
            } else {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountWarnings();
                }
            }
        }
    }

    /**
     * @param string $identifier add $key as warning or error?
     * @param string $key name of new keyword
     * @return void
     */
    public function addKey(string $identifier, string $key) {
        if ($identifier == 'warning') {
            $warningArray = json_decode($this->system->getLogalyzerWarningKeywords);
            $warningArray[] =  $key;
            $warningArray = json_encode($warningArray);
            $this->system->setLogalyzerWarningKeywords($warningArray);

        }
        else if ($identifier == 'error') {
            $errorArray = json_decode($this->system->getLogalyzerErrorKeywords);
            $errorArray[] =  $key;
            $errorArray = json_encode($errorArray);
            $this->system->setLogalyzerWarningKeywords($errorArray);
        }
        else {
            echo "identifier not recognized.";
        }
    }
    /**
     * @param string $identifier remove $key from warning or error
     * @param string $key name of keyword to be deleted
     * @return void
     */
    public function removeKey(string $identifier, string $key) {
        if ($identifier == 'warning') {
            $warningArray = json_decode($this->system->getLogalyzerWarningKeywords);
            if (($index = array_search($key, $warningArray)) !== false) {
                unset($warningArray[$index]);
                $this->system->setLogalyzerWarningKeywords(json_encode($warningArray));
            }
        }
        else if ($identifier == 'error') {
            $errorArray = json_decode($this->system->getLogalyzerErrorKeywords);
            if (($index = array_search($key, $errorArray)) !== false) {
                unset($errorArray[$index]);
                $this->system->setLogalyzerErrorKeywords(json_encode($errorArray));
            }

        }
        else {
            echo "identifier not recognized.";
        }
    }
    /**
     * gets current keywords from system, calculates its hash and saves it into this jobs db
     * @return void
     */
    function calculateAndSetHash() {
        $mergedArray = array_merge($this->errorKeys, $this->warningKeys);
        $mergedJson = json_encode($mergedArray);
        $this->job->setLogalyzerHash(hash('sha1', $mergedJson));
    }

    /**
     * @return string
     */
    function getHash() {
        return $this->job->getLogalyzerHash();
    }
}