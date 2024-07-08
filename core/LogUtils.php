<?php
use DBA\Job;
class LogUtils {
    private $job;
    private $log;
    private $logLength = -1;
    private $thresholdError = 1;
    private $thresholdWarning = 13;
    private $thresholdLogSize = 100;
    private $keyWordDict = ['error' => 0, 'warning' => 0];

    public function __construct($job) {
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
     * @returns int $count
     */
    public function countLogOccurances(string $keyword) {

        return substr_count($this->log, $keyword);
    }
    public function examineLogAndSetAlert() {
        // Check if log is too long
        $this->logLength = strlen($this->log);
        if ($this->logLength > $this->thresholdLogSize) {
            $this->job->SetSizeWarning(true);
        }
        // count occurances of all defined keywords. default keywords are 'error' and 'warning'
        // later, system admins can define more keywords, which will be added to the dict
        foreach ($this->keyWordDict as $key => $value) {
            $this->keyWordDict[$key] = $this->countLogOccurances($key);
        }
        if ($this->keyWordDict['warning'] >= $this->thresholdError) {
            $this->job->setLogAlert('warning');
        }
        else if ($this->keyWordDict['error'] >= $this->thresholdWarning) {
            $this->job->setLogAlert('error');
        }
    }
    // Will be changed in the systems settings
    public function setThresholdLogSize($size) {
        $this->thresholdLogSize = $size;
    }
    public function getThresholdLogSize($size) {
        return $this->thresholdLogSize;
    }
}

?>