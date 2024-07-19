<?php

/**
 * Analyze the log of a Chronos job using predefined keywords
 */
class Logalyzer_Library
{
    private $job;
    private $system;
    private $log;
    private $warningPatterns;
    private $errorPatterns;
    private $data;

    /**
     * @throws Exception
     */
    public function __construct($job)
    {
        $this->job = $job;
        $this->system = new System($this->job->getSystemId());
        $this->assignPatterns();
    }

    /**
     * @param string $keyword
     * @param string $target
     * @param bool $regex
     * @return int
     */
    public function countLogOccurances(string $keyword, string $target, bool $regex = false)
    {
        if ($regex) {
            return preg_match_all($keyword, $target);
        } else {
            return substr_count($target, $keyword);
        }
    }

    private function checkHashDifference($current)
    {
        // TODO check if returns the right value
        return !($this->job->getLogalyzerHash() == $current);
    }

    public function examineEntireLog()
    {
        $path = UPLOADED_DATA_PATH . '/log/' . $this->job->getId() . '.log';
        $log = Util::readFileContents($path);
        if ($log === false) {
            $this->log = "";
        } else {
            $this->log = $log;
        }
        // Check if there have been changes to the log
        $hash = $this->calculateHash();

        // Count occurrences of all defined keywords.
        $warningCount = 0;
        $errorCount = 0;
        foreach ($this->warningPatterns as $array) {
            foreach ($array as $key) {
                if ($key === 'regex') {
                    $warningCount += $this->countLogOccurances($key, $this->log, true);
                } else {
                    $warningCount += $this->countLogOccurances($key, $this->log);
                }
            }
        }
        foreach ($this->errorPatterns as $array) {
            foreach ($array as $key) {
                if ($key === 'regex') {
                    $errorCount += $this->countLogOccurances($key, $this->log, true);
                } else {
                    $errorCount += $this->countLogOccurances($key, $this->log);
                }
            }
        }
        $this->job->setLogalyzerCountWarnings($warningCount);
        $this->job->setLogalyzerCountErrors($errorCount);
        $this->setHash($hash);
    }

    public function examineLogLine($logLine) {
        // TODO more elegant solution
        while ($this->job->getLogalyzerCountWarnings <= 10 && $this->job->getLogalyzerCountErrors <= 10) {
            foreach ($this->warningPatterns['regex'] as $key) {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine, true); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountWarnings();
                }
            }
            foreach ($this->warningPatterns['string'] as $key) {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountWarnings();
                }
            }
            foreach ($this->errorPatterns['regex'] as $key) {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine, true); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountErrors();
                }
            }
            foreach ($this->errorPatterns['regex'] as $key) {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountErrors();
                }
            }
        }
    }
    private function assignPatterns(){
        $this->data = json_decode($this->job->getLogalyzerPattern(), true);
        $this->warningPatterns = $this->data['warningPattern'];
        $this->errorPatterns = $this->data['errorPattern'];
    }
    private function savePatterns(){
        $this->data['warningPattern'] = $this->warningPatterns;
        $this->data['errorPattern'] = $this->errorPatterns;
        $this->job->setLogalyzerPattern(json_encode($this->data));
    }
    /**
     * @param string $identifier add $key as warning or error?
     * @param string $key name of new keyword
     * @return void
     */
    public function addKey(string $identifier, string $isRegex, string $key) {

        if ($identifier == 'warning') {
            if($isRegex) {
                $this->warningPatterns['regex'][] = $key;
            }
            else {
                $this->warningPatterns['string'][] = $key;
            }
        }
        else if ($identifier == 'error') {
            if($isRegex) {
                $this->errorPatterns['regex'][] = $key;
            }
            else {
                $this->errorPatterns['string'][] = $key;
            }
        }
        else {
            echo "identifier not recognized.";
        }
        $this->savePatterns();
    }

    /**
     * @param string $identifier is $key a warning or error?
     * @param bool $isRegex
     * @param string $key name of keyword to be deleted
     * @return void
     */
    public function removeKey(string $identifier, bool $isRegex, string $key) {
        if ($identifier == 'warning') {
            if($isRegex) {
                if (($index = array_search($key, $this->warningPatterns['regex'])) !== false) {
                    unset($this->warningPatterns['regex'][$index]);
                }
            }
            else {
                if (($index = array_search($key, $this->warningPatterns['string'])) !== false) {
                    unset($this->warningPatterns['string'][$index]);
                }
            }
        }
        else if ($identifier == 'error') {
            if($isRegex) {
                if (($index = array_search($key, $this->errorPatterns['regex'])) !== false) {
                    unset($this->errorPatterns['regex'][$index]);
                }
            }
            else {
                if (($index = array_search($key, $this->errorPatterns['string'])) !== false) {
                    unset($this->errorPatterns['string'][$index]);
                }
            }
        }
        else {
            echo "identifier not recognized.";
        }
        $this->savePatterns();
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