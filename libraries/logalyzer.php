<?php

use DBA\Factory;

/**
 * Analyze the log of a Chronos job using predefined keywords
 */
class Logalyzer_Library {
    private $job;
    private $system;
    private $log;
    private $warningPatterns;
    private $errorPatterns;
    private $data;

    /**
     * @throws Exception
     */
    public function __construct($job) {
        $this->job = $job;
        $this->system = Factory::getSystemFactory()->get($this->job->getSystemId());
        $this->assignPatterns();
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
        } else {
            return substr_count($target, $keyword);
        }
    }

    private function checkHashDifference() {
        // TODO check if returns the right value
        return !($this->job->getLogalyzerHash() == hash('sha1', $this->data));
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
        $hash = $this->calculateHash();

        // Count occurrences of all defined keywords.
        $warningCount = 0;
        $errorCount = 0;

        while ($warningCount <= 10 && $errorCount <= 10) {
            foreach ($this->warningPatterns['regex'] as $key) {
                $warningCount += $this->countLogOccurances($key, $this->log, true);
            }
            foreach ($this->warningPatterns['string'] as $key) {
                $warningCount += $this->countLogOccurances($key, $this->log);
            }
            foreach ($this->errorPatterns['regex'] as $key) {
                $errorCount += $this->countLogOccurances($key, $this->log, true);
            }
            foreach ($this->errorPatterns['string'] as $key) {
                $errorCount += $this->countLogOccurances($key, $this->log);
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
            foreach ($this->errorPatterns['string'] as $key) {
                for ($i = 0; $i < $this->countLogOccurances($key, $logLine); $i++) {
                    // TODO implement increment
                    $this->job->incrementLogalyzerCountErrors();
                }
            }
        }
    }
    private function assignPatterns() {
        $this->data = json_decode($this->system->getLogalyzerPatterns(), true);
        if($this->data != null) {
            $this->warningPatterns = $this->data['warningPattern'];
            $this->errorPatterns = $this->data['errorPattern'];
        }
        else {
            $this->data['warningPattern'] = ['string' => [], 'regex' => []];
            $this->data['errorPattern'] = ['string' => [], 'regex' => []];
            $this->warningPatterns['string'] = [];
            $this->warningPatterns['regex'] = [];
            $this->errorPatterns['string'] = [];
            $this->errorPatterns['regex'] = [];
        }
    }
    private function savePatterns() {
        $this->data['warningPattern'] = $this->warningPatterns;
        $this->data['errorPattern'] = $this->errorPatterns;
        $this->system->setLogalyzerPatterns(json_encode($this->data));
    }
    /**
     * @param string $identifier 'log level'
     * @param string $type 'string' or 'regex'
     * @param string $key new key
     * @return void
     */
    public function addKey(string $identifier, string $type, string $key) {
        // Avoid changing local array for concurrency? Could save the new $key in a copy and save that copy
        if ($identifier == 'warning') {
            $this->warningPatterns[$type][] = $key;
        }
        elseif ($identifier == 'error') {
            $this->errorPatterns[$type][] = $key;
        }
        else {
            echo "Error in identifier or isRegex inside logalyzer.";
        }
        $this->savePatterns();
    }

    /**
     * @param string $identifier is $key a warning or error?
     * @param string $type 'string' or 'regex'
     * @param string $key key to delete
     * @return void
     */
    public function removeKey(string $identifier, string $type, string $key) {
        if ($identifier == 'warning') {
            if (($index = array_search($key, $this->warningPatterns[$type])) !== false) {
                unset($this->warningPatterns[$type][$index]);
            }
        }
        else if ($identifier == 'error') {
            if (($index = array_search($key, $this->errorPatterns[$type])) !== false) {
                unset($this->errorPatterns[$type][$index]);
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
        return hash('sha1', json_encode($this->data));
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
