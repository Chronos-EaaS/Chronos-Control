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
    private $mustContainPatterns;
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
        $LOG_ERRORS_MAX = 10;
        // TODO change to constant when available
        while ($this->job->getLogalyzerCountWarnings <= $LOG_ERRORS_MAX && $this->job->getLogalyzerCountErrors <= $LOG_ERRORS_MAX) {
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
                if ($this->countLogOccurances($key, $logLine) > 0) {
                    Factory::getJobFactory()->incrementJobError('error', $this->job->getId());
                }
            }
        }
    }

    /**
     * Creates empty pattern objects
     * @return void
     */
    private function createBasicPatterns() {
        $this->data['warningPattern'] = ['string' => [], 'regex' => []];
        $this->data['errorPattern'] = ['string' => [], 'regex' => []];
        $this->data['mustContainPattern'] = ['string' => [], 'regex' => []];
        $this->warningPatterns['string'] = [];
        $this->warningPatterns['regex'] = [];
        $this->errorPatterns['string'] = [];
        $this->errorPatterns['regex'] = [];
        $this->mustContainPatterns['string'] = [];
        $this->mustContainPatterns['regex'] = [];
    }

    /**
     * Returns the arrays containing pattern
     * $identifier can be 'all', 'warning' or 'error'
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
            } elseif ($identifier === 'mustContainPattern') {
                return $this->data['mustContainPattern'];
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
            $this->mustContainPatterns = $this->data['mustContainPattern'];
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

        // Can be reduced to only
        // $this->data['mustContainPattern'] = $this->mustContainPatterns;
        if(isset($this->data['mustContainPattern'])) {
            $this->data['mustContainPattern'] = $this->mustContainPatterns;
        }
        else {
            $this->data['mustContainPattern'] = ['string' => [], 'regex' => []];
            $this->mustContainPatterns['string'] = [];
            $this->mustContainPatterns['regex'] = [];
        }
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
                // Check if $key is already in the array
                if (!(array_search($key, $this->warningPatterns[$type]))) {
                    $this->warningPatterns[$type][] = $key;
                }
            } elseif ($identifier == 'error') {
                if (!(array_search($key, $this->errorPatterns[$type]))) {
                    $this->errorPatterns[$type][] = $key;
                }
            } elseif ($identifier == 'mustContain') {
                if (!(array_search($key, $this->mustContainPatterns[$type]))) {
                    $this->mustContainPatterns[$type][] = $key;
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
            elseif ($identifier == 'mustContain') {
                if (($index = array_search($key, $this->mustContainPatterns['string'])) !== false) {
                    unset($this->mustContainPatterns['string'][$index]);
                    $this->mustContainPatterns['string'] = array_values($this->mustContainPatterns['string']);
                }
                if (($index = array_search($key, $this->mustContainPatterns['regex'])) !== false) {
                    unset($this->mustContainPatterns['regex'][$index]);
                    $this->mustContainPatterns['regex'] = array_values($this->mustContainPatterns['regex']);
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
