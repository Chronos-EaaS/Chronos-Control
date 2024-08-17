<?php


use DBA\Event;
use DBA\Factory;

class Job_API extends API {

    public $get_access = Auth_Library::A_PUBLIC;

    public function get() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }
        $id = trim($this->get['id']);
        $job = null;
        if (is_numeric($id)) {
            $job = Factory::getJobFactory()->get($id);
            if (!$job) {
                throw new Exception('Job does not exist!');
            }
        }

        $data = new stdClass();
        $data->id = intval($job->getId());
        $data->status = intval($job->getStatus());
        $data->progress = intval($job->getProgress());
        $data->phases = Util::getExecutedPhases($job->getPhases());
        $data->currentPhase = empty($job->getCurrentPhase()) ? "" : Define::JOB_PHASE_NAMES[$job->getCurrentPhase()];

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

        if (isset($this->request['description'])) {
            $job->setDescription($this->request['description']);
        }
        if (isset($this->request['status'])) {
            $oldStatus = $job->getStatus();
            $job->setStatus($this->request['status']);
            if ($job->getStatus() == Define::JOB_STATUS_SCHEDULED) {
                $event = new Event(0, "Job has been rescheduled", date('Y-m-d H:i:s'),
                    "Job of evaluation '" . $evaluation->getName() . "' running in environment '" . $job->getEnvironment() . "' has been rescheduled. Previous status has been: " . Util::getStatusText($oldStatus),
                    Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null, null);
            } else if ($job->getStatus() == Define::JOB_STATUS_ABORTED) {
                $event = new Event(0, "Job has been aborted", date('Y-m-d H:i:s'),
                    "Job of evaluation '" . $evaluation->getName() . "' running in environment '" . $job->getEnvironment() . "' has been aborted. Previous status has been: " . Util::getStatusText($oldStatus),
                    Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null, null);
            } else {
                $event = new Event(0, "Job status changed", date('Y-m-d H:i:s'),
                    "Job of evaluation '" . $evaluation->getName() . "' running in environment '" . $job->getEnvironment() . "' has manually been changed from " . Util::getStatusText($oldStatus) . " to " . Util::getStatusText($job->getStatus()) . ".",
                    Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null, null);
            }
            Factory::getEventFactory()->save($event);
            if ($this->request['status'] == Define::JOB_STATUS_SCHEDULED) {
                $job->setCurrentPhase(null);
                $job->setProgress(0);
            }
        }
        if (isset($this->request['progress'])) {
            $job->setProgress($this->request['progress']);
        }
        Factory::getJobFactory()->update($job);
    }


}