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
    public function setSystemAndLoadPattern($system) {
        $this->system = $system;
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
        $LOG_ERRORS_MAX = 10;
        // TODO change to constant when available
        foreach ($this->warningPatterns['regex'] as $key) {
            $warningCount += $this->countLogOccurances($key, $this->log, true);
            if($warningCount >= $LOG_ERRORS_MAX) {
                break;
            }
        }
        foreach ($this->warningPatterns['string'] as $key) {
            $count = $this->countLogOccurances($key, $this->log);
            echo 'For key: ' . $key . ' found: ' . $count . "\n";
            $warningCount += $count;
            if($warningCount >= $LOG_ERRORS_MAX) {
                echo 'too many warnings';
                break;
            }
        }
        foreach ($this->errorPatterns['regex'] as $key) {
            $errorCount += $this->countLogOccurances($key, $this->log, true);
            if($warningCount >= $LOG_ERRORS_MAX) {
                break;
            }
        }
        foreach ($this->errorPatterns['string'] as $key) {
            $errorCount += $this->countLogOccurances($key, $this->log);
            if($warningCount >= $LOG_ERRORS_MAX) {
                break;
            }
        }

        $this->job->setLogalyzerWarningCount($warningCount);
        $this->job->setLogalyzerErrorCount($errorCount);
        $this->job->setLogalyzerHash($hash);
        Factory::getJobFactory()->update($this->job);
    }

    public function examineLogLine($logLine) {
        $LOG_ERRORS_MAX = 10;
        // TODO change to constant when available
        while ($this->job->getLogalyzerCountWarnings <= $LOG_ERRORS_MAX && $this->job->getLogalyzerCountErrors <= $LOG_ERRORS_MAX) {
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
        $this->data['warningPattern'] = ['string' => [], 'regex' => []];
        $this->data['errorPattern'] = ['string' => [], 'regex' => []];
        $this->warningPatterns['string'] = [];
        $this->warningPatterns['regex'] = [];
        $this->errorPatterns['string'] = [];
        $this->errorPatterns['regex'] = [];
    }
    public function getPatterns($identifier) {
        if($this->system->getLogalyzerPatterns() == null) {
            $this->createBasicPatterns();
            $this->savePatterns();
        }
        $patterns = $this->system->getLogalyzerPatterns();
        if ($patterns != null) {
            $this->data = json_decode($patterns, true);
            if ($identifier === 'warning') {
                return $this->data['warningPattern'];
            } elseif ($identifier === 'error') {
                return $this->data['errorPattern'];
            } else {
                echo "Error in getpatterns";
                return [];
            }
        } else {
            echo "Error in getpatterns";
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
            // Initial load of patterns returned null
            $this->createBasicPatterns();
            $this->savePatterns();
        }
    }
    private function savePatterns() {
        $this->data['warningPattern'] = $this->warningPatterns;
        $this->data['errorPattern'] = $this->errorPatterns;
        $encoded = json_encode($this->data);
        $this->system->setLogalyzerPatterns($encoded);
        Factory::getSystemFactory()->update($this->system);
    }
    /**
     * @param string $identifier 'log level'
     * @param string $type 'string' or 'regex'
     * @param string $key new key
     * @return void
     */
    public function addKey(string $identifier, string $type, string $key) {
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
                    $this->errorPatterns[$type] = array_values($this->errorPatterns[$type]);
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
