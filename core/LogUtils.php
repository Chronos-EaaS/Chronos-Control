<?php

class LogUtils {
    private $job;
    private $log;

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
    public function countLogOccurances(string $keyword): int {

        return substr_count($this->log, $keyword);
    }
}

?>