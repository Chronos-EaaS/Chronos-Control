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
    public function setSystem($system) {
        $this->system = $system;
        // Can only grab patterns after a system is defined
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
    public function countLogOccurances(string $keyword, string $target, bool $regex = false) {
        if ($regex) {
            return preg_match_all($keyword, $target);
        } else {
            return substr_count($target, $keyword);
        }
    }

    private function checkHashDifference() {
        // TODO check if returns the right value
        return !($this->job->getLogalyzerHash() === hash('sha1', $this->data));
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
        $this->job->setLogalyzerHash($hash);
        //$this->job = $this->job->save($this->job);
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
    private function createBasicPatterns() {
        //echo 'creating basic pattern.\n';
        $this->data['warningPattern'] = ['string' => [], 'regex' => []];
        $this->data['errorPattern'] = ['string' => [], 'regex' => []];
        $this->warningPatterns['string'] = [];
        $this->warningPatterns['regex'] = [];
        $this->errorPatterns['string'] = [];
        $this->errorPatterns['regex'] = [];
        //$this->savePatterns();
    }
    public function getPatterns($identifier) {
        if($this->system->getLogalyzerPatterns() == null) {
            echo "getPatterns creates pattern \n";
            $this->createBasicPatterns();
            echo "getPatterns saves pattern \n";
            $this->savePatterns();
        }
        $patterns = $this->system->getLogalyzerPatterns();
        if ($patterns != null) {
            $this->data = json_decode($patterns, true);
            if ($identifier === 'warning') {
                echo "loaded pattern: ";
                print_r($this->data['warningPattern']);
                return $this->data['warningPattern'];
            } elseif ($identifier === 'error') {
                echo "loaded pattern: ";
                print_r($this->data['errorPattern']);
                return $this->data['errorPattern'];
            } else {
                return [];
            }
        } else {
            return [];
        }
    }
    public function loadPatterns() {
        $patterns = $this->system->getLogalyzerPatterns();
        if ($patterns != null) {
            $this->data = json_decode($patterns, true);
            $this->warningPatterns = $this->data['warningPattern'];
            $this->errorPatterns = $this->data['errorPattern'];
        }
        else {
            echo 'loadPatterns creates patterns';
            $this->createBasicPatterns();
            $this->savePatterns();
        }
    }
    private function savePatterns() {
        $this->data['warningPattern'] = $this->warningPatterns;
        $this->data['errorPattern'] = $this->errorPatterns;
        $encoded = json_encode($this->data);
        echo 'Saved to DB:' . $encoded;
        $this->system->setLogalyzerPatterns($encoded);
        //$this->system = Factory::getSystemFactory()->save($this->system);
    }
    /**
     * @param string $identifier 'log level'
     * @param string $type 'string' or 'regex'
     * @param string $key new key
     * @return void
     */
    public function addKey(string $identifier, string $type, string $key) {
        // Avoid changing local array for concurrency? Could save the new $key in a copy and save that copy
        if($this->system == null) {
            echo 'System not defined\n';
        }
        else {
            if ($identifier == 'warning') {
                $this->warningPatterns[$type][] = $key;
            } elseif ($identifier == 'error') {
                $this->errorPatterns[$type][] = $key;
            } else {
                echo "Error in identifier or isRegex inside logalyzer.";
            }
            $this->savePatterns();
        }
    }

    /**
     * @param string $identifier is $key a warning or error?
     * @param string $type 'string' or 'regex'
     * @param string $key key to delete
     * @return void
     */
    public function removeKey(string $identifier, string $type, string $key)
    {
        if ($this->system == null) {
            echo 'System not defined\n';
        }
        else {
            if ($identifier == 'warning') {
                if (($index = array_search($key, $this->warningPatterns[$type])) !== false) {
                    unset($this->warningPatterns[$type][$index]);
                }
            }
            elseif ($identifier == 'error') {
                if (($index = array_search($key, $this->errorPatterns[$type])) !== false) {
                    unset($this->errorPatterns[$type][$index]);
                }
            }
            else {
                echo "identifier not recognized.";
            }
            $this->savePatterns();
        }
    }
    /**
     * Allows calculating the hash before any operations are done
     * @return string
     */
    function calculateHash() {
        return hash('sha1', json_encode($this->data));
    }

    /**
     * @return string
     */
    function getHash() {
        return $this->job->getLogalyzerHash();
    }
}
