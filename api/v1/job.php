<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

use DBA\ContainFilter;
use DBA\Event;
use DBA\Factory;
use DBA\Job;
use DBA\QueryFilter;

class Job_API extends API {

    public $get_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function get() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }
        $id = trim($this->get['id']);
        $job = null;
        if (is_numeric($id)) {
            $job = Factory::getJobFactory()->get($id);
            if (!$job) {
                $this->setStatusCode(API::STATUS_NUM_JOB_DOES_NOT_EXIST);
                throw new Exception('Job does not exist!');
            }
        } else if ($id == 'next') {
            if (!empty($this->get['supports'])) {
                $environment = Define::DEFAULT_ENVIRONMENT_NAME;
                if (!empty($this->get['environment'])) {
                    $environment = trim($this->get['environment']);
                }
                $filters = [];
                if ($this->get['supports'] == 'demoall') { // demo mode to match all systems
                    // we accept all systems now
                } else {
                    $supports = Systems_Library::getArrayFromString($this->get['supports']);
                    $filters[] = new ContainFilter(Job::SYSTEM_ID, $supports);
                }
                $filters[] = new QueryFilter(Job::ENVIRONMENT, "system-".$environment, "=");
                $filters[] = new QueryFilter(Job::STATUS, Define::JOB_STATUS_SCHEDULED, "=");
                $job = Factory::getJobFactory()->filter([Factory::FILTER => $filters], true);
            } else {
                throw new Exception('No list of supported systems provided!');
            }
            if (!$job) {
                $this->setStatusCode(API::STATUS_NUM_NO_JOB_IN_QUEUE);
                $this->setError('No job in queue!');
                exit();
            }
        }

        $evaluation = Factory::getEvaluationFactory()->get($job->getEvaluationId());
        $user = Factory::getUserFactory()->get($job->getUserId());
        $experiment = Factory::getExperimentFactory()->get($evaluation->getExperimentId());
        $system = Factory::getSystemFactory()->get($experiment->getSystemId());

        // Change to int's
        $data = new stdClass();
        $data->id = intval($job->getId());
        $data->user = intval($job->getUserId());
        $data->status = intval($job->getStatus());
        $data->progress = intval($job->getProgress());
        $data->username = $user->getUsername();
        $data->phases = intval($job->getPhases());
        $data->user = intval($user->getId());
        $data->name = $evaluation->getName();
        $data->description = $job->getDescription();
        $sys = new System($system->getId());
        $data->system = $sys->getIdentifier();
        $data->environment = $job->getEnvironment();
        $data->cdl = Util::jobToCDL($job);

        $data->created = $job->getCreated();
        $data->started = $job->getStarted();
        $data->finished = $job->getFinished();

        if (isset($this->get['withLog']) && $this->get['withLog'] == true) {
            $path = UPLOADED_DATA_PATH . '/log/' . $job->getId() . '.log';
            $log = Util::readFileContents($path);
            if ($log === false) {
                $data->log = "";
            } else {
                $data->log = $log;
            }
        }
        $this->add($data);
    }

    public $post_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function post() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }
        $id = trim($this->get['id']);
        $job = Factory::getJobFactory()->get($id);
        if (!$job) {
            $this->setStatusCode(API::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }
        if (empty($this->get['action'])) {
            throw new Exception('No action provided');
        }
        switch (strtolower($this->get['action'])) {
            case(strtolower('getUploadTarget')):
                if (Settings_Library::getInstance(0)->get('ftp', 'useFtpUploadForLocalClients')->getValue() && Ip_Library::cidrMatch($_SERVER['REMOTE_ADDR'], Settings_Library::getInstance(0)->get('ftp', 'localNetworkCIDR')->getValue())) {
                    // Client is in the local subnet, use FTP Upload
                    $this->add($this->getFtpUploadTarget($this->get['id']));
                } else {
                    // Client is not in the local subnet, use HTTP upload
                    $this->add($this->getHttpUploadTarget($this->get['id']));
                }
                break;
            case(strtolower('appendLog')):
                $this->appendLog($this->get['id']);
                break;
            case 'upload':
                $this->upload($job);
                break;
            default:
                throw new Exception('Unsupported action');
                break;
        }
    }


    public $patch_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function patch() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }

        $auth = Auth_Library::getInstance();
        $job = Factory::getJobFactory()->get($this->get['id']);
        $evaluation = Factory::getEvaluationFactory()->get($job->getEvaluationId());
        if (!$job) {
            $this->setStatusCode(API::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }

        if (!empty($this->request['status'])) {
            $oldStatus = $job->getStatus();
            $job->setStatus($this->request['status']);
            $event = new Event(0, "Job status changed", date('Y-m-d H:i:s'),
                "Job of evaluation '" . $evaluation->getName() . "' running in environment '" . $job->getEnvironment() . "' changed from " . Util::getStatusText($oldStatus) . " to " . Util::getStatusText($job->getStatus()) . ".",
                Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null);
            Factory::getEventFactory()->save($event);
        }
        if (isset($this->request['progress'])) {
            $job->setProgress($this->request['progress']);
        }
        if (!empty($this->request['currentPhase'])) {
            // Do nothing
            Logger_Library::getInstance()->notice("Received update for current phase. New Phase: " . $this->request['currentPhase']);
            $event = new Event(0, "Job changed phase", date('Y-m-d H:i:s'),
                "Job of evaluation '" . $evaluation->getName() . "' running in environment '" . $job->getEnvironment() . "' changed to phase " . $this->request['currentPhase'] . ".",
                Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null);
            Factory::getEventFactory()->save($event);
        }
        if (!empty($this->request['result'])) {
            $job->setResult($this->request['result']);
            $event = new Event(0, "Job sent results", date('Y-m-d H:i:s'),
                "Job of evaluation '" . $evaluation->getName() . "' running in environment '" . $job->getEnvironment() . "' has sent results.",
                Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null);
            Factory::getEventFactory()->save($event);
        }
        Factory::getJobFactory()->update($job);
    }



    // -------------------------
    // Private Method
    // -------------------------

    /**
     * @param $job Job
     * @throws Exception
     */
    private function upload($job) {
        global $_FILES;

        $fileUploadName = "result";
        if (!isset($_FILES[$fileUploadName]['error']) || is_array($_FILES[$fileUploadName]['error'])) {
            throw new Exception('Invalid parameters!');
        }

        // check for error values
        switch ($_FILES[$fileUploadName]['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new Exception('No file sent!');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception('Exceeded filesize limit!');
            default:
                throw new Exception('Unknown error!');
        }

        $filename = realpath(UPLOADED_DATA_PATH) . "/evaluation/" . $job->getId() . ".zip";
        if (!move_uploaded_file($_FILES[$fileUploadName]['tmp_name'], $filename)) {
            throw new Exception('Failed to move uploaded file to destination!');
        }
    }

    /**
     * @param $id
     * @return stdClass
     * @throws Exception
     */
    private function getFtpUploadTarget($id) {
        // FTP Config
        $data = new stdClass();
        $data->method = 'ftp';
        $data->hostname = Settings_Library::getInstance(0)->get('ftp', 'ftpServer')->getValue();
        $data->port = Settings_Library::getInstance(0)->get('ftp', 'ftpPort')->getValue();
        $data->username = Settings_Library::getInstance(0)->get('ftp', 'ftpUsername')->getValue();
        $data->password = Settings_Library::getInstance(0)->get('ftp', 'ftpPassword')->getValue();
        $job = Factory::getJobFactory()->get($id);
        $data->path = '/chronos/evaluation/';
        $data->filename = $job->getId() . '.zip';
        return $data;
    }

    /**
     * @param $id
     * @return stdClass
     * @throws Exception
     */
    private function getHttpUploadTarget($id) {
        // HTTP Config
        $data = new stdClass();
        $data->method = 'http';
        $data->path = '/api/v1/job';
        $data->hostname = Settings_Library::getInstance(0)->get('other', 'uploadedDataHostname')->getValue();
        return $data;
    }


    /**
     * @param $id
     * @throws Exception
     */
    private function appendLog($id) {
        $job = Factory::getJobFactory()->get($id);
        if (!$job) {
            $this->setStatusCode(API::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }
        if (empty($this->request['log'])) {
            throw new Exception('No or insufficient data provided.');
        }

        // check if the data directory is mounted and if not, mount it
        /*$mount = new Mount_Library();
        if ($mount->checkIfDataDirectoryIsMounted() === false) {
            Logger_Library::getInstance()->warning("Data directory is not mounted. Execute mount!");
            $mount->mountDataDirectory();
        }*/ // skip mount

        // write log to file
        if (!file_exists(UPLOADED_DATA_PATH . 'log')) {
            mkdir(UPLOADED_DATA_PATH . 'log');
        }
        file_put_contents(UPLOADED_DATA_PATH . 'log/' . $id . '.log', $this->request['log'], FILE_APPEND);
    }
}
