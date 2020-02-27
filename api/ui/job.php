<?php


use DBA\Event;

class Job_API extends API {
    public $patch_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function patch() {
        global $FACTORIES;

        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }

        $auth = Auth_Library::getInstance();
        $job = $FACTORIES::getJobFactory()->get($this->get['id']);
        $evaluation = $FACTORIES::getEvaluationFactory()->get($job->getEvaluationId());
        if (!$job) {
            $this->setStatusCode(API::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }

        if (isset($this->request['description'])) {
            $job->setDescription($this->request['description']);
        }
        if (!empty($this->request['status'])) {
            $oldStatus = $job->getStatus();
            $job->setStatus($this->request['status']);
            $event = new Event(0, "Job status changed", date('Y-m-d H:i:s'),
                "Job of evaluation '" . $evaluation->getName() . "' running on deployment '" . $job->getEnvironment() . "' changed from " . Util::getStatusText($oldStatus) . " to " . Util::getStatusText($job->getStatus()) . ".",
                Define::EVENT_JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null);
            $FACTORIES::getEventFactory()->save($event);
        }
        if (isset($this->request['progress'])) {
            $job->setProgress($this->request['progress']);
        }
        $FACTORIES::getJobFactory()->update($job);
    }
}
