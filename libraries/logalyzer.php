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
    private $warningPatterns;
    private $errorPatterns;
    private $mandatoryPatterns;
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
        return !($this->job->getLogalyzerHash() === hash('sha1', json_encode('sha1', $this->data)));
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
        // Check if there have been changes to the log
        $hash = $this->calculateHash();
        $mandatoryPatternPresent = 0;
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
            $warningCount += $count;
            if($warningCount >= $LOG_ERRORS_MAX) {
                break;
            }
        }
        foreach ($this->errorPatterns['regex'] as $key) {
            $errorCount += $this->countLogOccurances($key, $this->log, true);
            if($errorCount >= $LOG_ERRORS_MAX) {
                break;
            }
        }
        foreach ($this->errorPatterns['string'] as $key) {
            $errorCount += $this->countLogOccurances($key, $this->log);
            if($errorCount >= $LOG_ERRORS_MAX) {
                break;
            }
        }
        // If no mandatory pattern is defined
        if (count($this->mandatoryPatterns['string'])==0 && count($this->mandatoryPatterns['regex']) == 0) {
            $mandatoryPatternPresent = 1;
        }
        else {
            foreach ($this->mandatoryPatterns['regex'] as $key) {
                if ($this->countLogOccurances($key, $this->log, true) > 0) {
                    $mandatoryPatternPresent = 1;
                }
            }
            foreach ($this->mandatoryPatterns['string'] as $key) {
                if ($this->countLogOccurances($key, $this->log) > 0) {
                    $mandatoryPatternPresent = 1;
                }
            }
        }
        $this->job->setLogalyzerContainsMandatoryPattern($mandatoryPatternPresent);
        $this->job->setLogalyzerWarningCount($warningCount);
        $this->job->setLogalyzerErrorCount($errorCount);
        $this->job->setLogalyzerHash($hash);
        Factory::getJobFactory()->update($this->job);
    }

    /**
     * Reads a single logLine and increments error/warnings counters if a pattern word is present inside.
     * Number or occurances does not matter for now. One error logLine is considered one potential error event.
     * @param $logLine
     * @return void
     */
    public function examineLogLine($logLine) {
        echo "Type: " . gettype($logLine) . "\n";
        echo "Line: " . $logLine . "\n";
        $hash = $this->calculateHash();
        foreach ($this->warningPatterns['regex'] as $key) {
            if ($this->countLogOccurances($key, $logLine, true) > 0) {
                Factory::getJobFactory()->incrementJobError('warning', $this->job->getId());
            }
        }
        foreach ($this->warningPatterns['string'] as $key) {
            if ($this->countLogOccurances($key, $logLine) > 0) {
                Factory::getJobFactory()->incrementJobError('warning', $this->job->getId());
            }
        }
        foreach ($this->errorPatterns['regex'] as $key) {
            if ($this->countLogOccurances($key, $logLine, true) > 0) {
                Factory::getJobFactory()->incrementJobError('error', $this->job->getId());
            }
        }
        foreach ($this->errorPatterns['string'] as $key) {
            echo "checking for error key: " . $key . "\n";
            if ($this->countLogOccurances($key, $logLine) > 0) {
                echo "error increment\n";
                Factory::getJobFactory()->incrementJobError('error', $this->job->getId());
            }
        }
        // If no mandatory pattern is defined
        if (count($this->mandatoryPatterns['string'])==0 && count($this->mandatoryPatterns['regex']) == 0) {
            $this->job->setLogalyzerContainsMandatoryPattern(1);
        } else {
            $mandatoryPatternPresent = 0;
            foreach ($this->mandatoryPatterns['regex'] as $key) {
                if ($this->countLogOccurances($key, $this->log, true) > 0) {
                    $this->job->setLogalyzerContainsMandatoryPattern(1);
                    Factory::getJobFactory()->update($this->job);
                }
            }
            foreach ($this->mandatoryPatterns['string'] as $key) {
                if ($this->countLogOccurances($key, $this->log) > 0) {
                    $this->job->setLogalyzerContainsMandatoryPattern(1);
                    Factory::getJobFactory()->update($this->job);
                }
            }
        }
        if($this->job->getLogalyzerHash == null) {
            $this->job->setLogalyzerHash($hash);
            Factory::getJobFactory()->update($this->job);
        }
    }

    /**
     * Creates empty pattern objects
     * @return void
     */
    private function createBasicPatterns() {
        $this->data['warningPattern'] = ['string' => [], 'regex' => []];
        $this->data['errorPattern'] = ['string' => [], 'regex' => []];
        $this->data['mandatoryPattern'] = ['string' => [], 'regex' => []];
        $this->warningPatterns['string'] = [];
        $this->warningPatterns['regex'] = [];
        $this->errorPatterns['string'] = [];
        $this->errorPatterns['regex'] = [];
        $this->mandatoryPatterns['string'] = [];
        $this->mandatoryPatterns['regex'] = [];
    }

    /**
     * Returns the arrays containing pattern
     * $identifier can be 'all', 'warning' or 'error' or 'mandatory
     * @param $identifier
     * @return array|mixed
     */
    public function getPatterns($identifier) {
        if($this->system->getLogalyzerPatterns() == null) {
            $this->createBasicPatterns();
            $this->savePatterns();
        }
        $patterns = $this->system->getLogalyzerPatterns();
        if ($patterns != null) {
            $this->data = json_decode($patterns, true);
            if ($identifier === 'all') {
                return $this->data;
            }elseif ($identifier === 'warning') {
                return $this->data['warningPattern'];
            } elseif ($identifier === 'error') {
                return $this->data['errorPattern'];
            } elseif ($identifier === 'mandatory') {
                return $this->data['mandatoryPattern'];
            } else {
                echo "Error in getpatterns";
                return [];
            }
        } else {
            echo "Error in getpatterns";
            return [];
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
            $this->warningPatterns = $this->data['warningPattern'];
            $this->errorPatterns = $this->data['errorPattern'];
            $this->mandatoryPatterns = $this->data['mandatoryPattern'];
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
        $this->data['warningPattern'] = $this->warningPatterns;
        $this->data['errorPattern'] = $this->errorPatterns;
        $this->data['mandatoryPattern'] = $this->mandatoryPatterns;

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
                if (!in_array($key, $this->warningPatterns[$type])) {
                    $this->warningPatterns[$type][] = $key;
                }
            } elseif ($identifier == 'error') {
                if (!in_array($key, $this->errorPatterns[$type])) {
                    $this->errorPatterns[$type][] = $key;
                }
            } elseif ($identifier == 'mandatory') {
                if (!in_array($key, $this->mandatoryPatterns[$type])) {
                    $this->mandatoryPatterns[$type][] = $key;
                }
            } else {
                echo "Error in identifier or isRegex inside logalyzer.";
            }
            $this->savePatterns();
        }
    }

    /**
     * @param string $identifier is $key a warning or error?
     * @param string $key key to delete
     * @return void
     */
    public function removeKey(string $identifier, string $key)  {
        if ($this->system == null) {
            echo 'System not defined\n';
        }
        else {
            if ($identifier == 'warning') {
                if (($index = array_search($key, $this->warningPatterns['string'])) !== false) {
                    unset($this->warningPatterns['string'][$index]);
                }
                if (($index = array_search($key, $this->warningPatterns['regex'])) !== false) {
                    unset($this->warningPatterns['string'][$index]);
                }
            }
            elseif ($identifier == 'error') {
                if (($index = array_search($key, $this->errorPatterns['string'])) !== false) {
                    unset($this->errorPatterns['string'][$index]);
                    $this->errorPatterns['string'] = array_values($this->errorPatterns['string']);
                }
                if (($index = array_search($key, $this->errorPatterns['regex'])) !== false) {
                    unset($this->errorPatterns['regex'][$index]);
                    $this->errorPatterns['regex'] = array_values($this->errorPatterns['regex']);
                }
            }
            elseif ($identifier == 'mandatory') {
                if (($index = array_search($key, $this->mandatoryPatterns['string'])) !== false) {
                    unset($this->mandatoryPatterns['string'][$index]);
                    $this->mandatoryPatterns['string'] = array_values($this->mandatoryPatterns['string']);
                }
                if (($index = array_search($key, $this->mandatoryPatterns['regex'])) !== false) {
                    unset($this->mandatoryPatterns['regex'][$index]);
                    $this->mandatoryPatterns['regex'] = array_values($this->mandatoryPatterns['regex']);
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
}
