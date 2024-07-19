<?php

/**
 * Analyze the log of a Chronos job using predefined keywords
 */
class Logalyzer_Library {
    private $job;
    private $system;
    private $log;
    private $warningPatterns;
    private $errorPatterns;

    /**
     * @throws Exception
     */
    public function __construct($job) {
        $this->job = $job;
        $this->system = new System($this->job->getSystemId());

        // Grab keyword arrays at creation of this object. Changes during a job run make the result outdated, but consistent
        $this->warningPatterns = json_decode($this->system->getLogalyzerWarningPatterns());
        $this->errorPatterns = json_decode($this->system->getLogalyzerErrorPatterns());
        //$this->calculateAndSetHash();

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
        $errorArray = json_decode($this->system->getLogalyzerErrorPatterns);
        $warningArray = json_decode($this->system->getLogalyzerWarningPatterns);
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
        $path = UPLOADED_DATA_PATH . '/log/' . $this->job->getId() . '.log';
        $log = Util::readFileContents($path);
        if ($log === false) {
            $this->log = "";
        } else {
            $this->log = $log;
        }
        // Check if there have been changes to the log
        if ($this->checkHashDifference()) {
            // Count occurrences of all defined keywords.
            $warningCount = 0;
            $errorCount = 0;
            foreach ($this->warningPatterns as $key) {
                if(str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                    // $key is regex
                    $warningCount += $this->countLogOccurances($key, $this->log, true);
                }
                else {
                    $warningCount += $this->countLogOccurances($key, $this->log);
                }
            }
            foreach ($this->errorPatterns as $key) {
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
        if($this->job->getLogalyzerCountWarnings <= 10) {
            foreach ($this->warningPatterns as $key) {
                if (str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                    // $key is regex
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
        if($this->job->getLogalyzerCountErrors <= 10) {
            foreach ($this->errorPatterns as $key) {
                if (str_starts_with($key, "/") || str_starts_with($key, "#") || str_starts_with($key, "~")) {
                    // $key is regex.
                    for ($i = 0; $i < $this->countLogOccurances($key, $logLine, true); $i++) {
                        // TODO implement increment
                        $this->job->incrementLogalyzerCountErrors();
                    }
                } else {
                    for ($i = 0; $i < $this->countLogOccurances($key, $logLine); $i++) {
                        // TODO implement increment
                        $this->job->incrementLogalyzerCountErrors();
                    }
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
            $warningArray = json_decode($this->system->getLogalyzerWarningPatterns);
            $warningArray[] =  $key;
            $warningArray = json_encode($warningArray);
            $this->system->setLogalyzerWarningPatterns($warningArray);

        }
        else if ($identifier == 'error') {
            $errorArray = json_decode($this->system->getLogalyzerErrorPatterns);
            $errorArray[] =  $key;
            $errorArray = json_encode($errorArray);
            $this->system->setLogalyzerWarningPatterns($errorArray);
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
            $warningArray = json_decode($this->system->getLogalyzerWarningPatterns);
            if (($index = array_search($key, $warningArray)) !== false) {
                unset($warningArray[$index]);
                $this->system->setLogalyzerWarningPatterns(json_encode($warningArray));
            }
        }
        else if ($identifier == 'error') {
            $errorArray = json_decode($this->system->getLogalyzerPatterns);
            if (($index = array_search($key, $errorArray)) !== false) {
                unset($errorArray[$index]);
                $this->system->setLogalyzerErrorPatterns(json_encode($errorArray));
            }

        }
        else {
            echo "identifier not recognized.";
        }
    }
    /**
     * Allows calculating the hash before any operations are done
     * @return string
     */
    function calculateHash() {
        $mergedArray = array_merge($this->errorPatterns, $this->warningPatterns);
        $mergedJson = json_encode($mergedArray);
        return hash('sha1', $mergedJson);
    }

    /**
     * Allows for setting the hash after operations are done
     * @param $value
     * @return void
     */
    function setHash($value) {
        $this->job->setLogalyzerHash($value);
    }

    /**
     * @return string
     */
    function getHash() {
        return $this->job->getLogalyzerHash();
    }
}