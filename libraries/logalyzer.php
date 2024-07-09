<?php

/**
 * Manage customizable keywords in job logs
 */
class Logalyzer_Library {
    private $job;
    private $log;
    private $logLength = -1;
    private $thresholdError = 1;
    private $thresholdWarning = 12;
    private $thresholdLogSize = 10000;
    private $warningKeys = ['WARNING:' => 0];
    private $errorKeys = ['ERROR:' => 0];
    private $warningAlert = false;
    private $errorAlert = false;


    public function __construct($job)
    {
        $this->job = $job;
        $path = UPLOADED_DATA_PATH . '/log/' . $job->getId() . '.log';
        $log = Util::readFileContents($path);
        if ($log === false) {
            $this->log = "";
        } else {
            $this->log = $log;
        }
    }

    /**
     * @param string $keyword
     * @return int
     */
    public function countLogOccurances(string $keyword) {
        return substr_count($this->log, $keyword);
    }

    public function examineLogAndSetAlert() {
        // Check if there have been changes to the log
        if ($this->job->getLogHash() == hash('sha256', $this->log)) {
            // no changes since last function call
            echo "same hash\n";
            return;
        }
        $this->job->setLogHash = hash('sha256', $this->log);
        // Check if log is too long
        $this->logLength = strlen($this->log);
        if ($this->logLength > $this->thresholdLogSize) {
            $this->job->SetSizeWarning(true);
        }
        // count occurrences of all defined keywords. Default keywords are 'error' and 'warning'
        foreach ($this->warningKeys as $key => $value) {
            $this->warningKeys[$key] = $this->countLogOccurances($key);
        }
        foreach ($this->errorKeys as $key => $value) {
            $this->errorKeys[$key] = $this->countLogOccurances($key);
        }

        // Check if errors/warnings are more than the threshold
        foreach ($this->warningKeys as $key => $value) {
            if ($value >= $this->thresholdWarning) {
                $this->job->setLogAlert('warning');
            }
        }
        foreach ($this->errorKeys as $key => $value) {
            if ($value >= $this->thresholdError) {
                $this->job->setLogAlert('error');
            }
        }
    }

    /**
     * @param int $size
     */
    // TODO Threshold not a static value but a percentage compared to the other job's logs
    public function setThresholdLogSize($size)
    {
        $this->thresholdLogSize = $size;
    }

    /**
     * @param int $size
     * @return int
     */
    public function getThresholdLogSize($size)
    {
        return $this->thresholdLogSize;
    }
    /**
     * @param string $identifier add $key as warning or error?
     * @param string $key name of new keyword
     */
    public function addKey(string $identifier, string $key) {
        if ($identifier == 'warning') {
            $this->warningKeys[$key] = 0;
        }
        else if ($identifier == 'error') {
            $this->errorKeys[$key] = 0;
        }
        else {
            echo "identifier not recognized.";
        }
    }
    /**
     * @param string $identifier remove $key as warning or error?
     * @param string $key name of keyword to be deleted
     */
    public function removeKey(string $identifier, string $key) {
        if ($identifier == 'warning') {
            unset($this->warningKeys[$key]);
        }
        else if ($identifier == 'error') {
            unset($this->errorKeys[$key]);
        }
        else {
            echo "identifier not recognized.";
        }
    }
}

?>