<?php

use DBA\Evaluation;
use DBA\Event;
use DBA\Experiment;
use DBA\Job;

class Evaluation_Library {
    private $jobs;
    /** @var $experiment Experiment */
    private $experiment;

    public function __construct($experiment) {
        $this->jobs = array();
        $this->experiment = $experiment;
    }

    /**
     * @param $description
     * @param $cdl CDL_Library
     * @throws Exception
     */
    public function addEvaluationJob($description, $cdl) {
        $job = [];
        $job['description'] = $description;
        $job['type'] = 2;
        $job['cdl'] = $cdl->toXML();
        $job['user'] = Auth_Library::getInstance()->getUserID();
        $job['system'] = $this->experiment->getSystemId();
        $this->jobs[] = $job;
    }

    /**
     * @param $environment string
     * @param $data array
     * @param $evaluation Evaluation
     * @throws Exception
     */
    public function generateJobs($environment, &$data, $evaluation) {
        global $FACTORIES;

        $count = 1;
        $allJobs = [];
        foreach ($this->jobs as $jobData) {
            $jobData['phase_prepare'] = isset($data['phase_prepare']) ? boolval($data['phase_prepare']) : true;
            $jobData['phase_warmUp'] = isset($data['phase_warmUp']) ? boolval($data['phase_warmUp']) : true;
            $jobData['phase_execute'] = isset($data['phase_execute']) ? boolval($data['phase_execute']) : true;
            $jobData['phase_analyze'] = isset($data['phase_analyze']) ? boolval($data['phase_analyze']) : true;
            $jobData['phase_clean'] = isset($data['phase_clean']) ? boolval($data['phase_clean']) : true;

            $cdl = trim($jobData['cdl']);
            $phases = Util::calcPhasesBitMask($jobData['phase_prepare'], $jobData['phase_warmUp'], $jobData['phase_execute'], $jobData['phase_analyze'], $jobData['phase_clean']);
            $status = Define::JOB_STATUS_SCHEDULED;

            $job = new Job(0,
                $this->experiment->getUserId(),
                $jobData['description'],
                $this->experiment->getSystemId(),
                $environment,
                $phases, $cdl, $status, 0, '',
                date('Y-m-d H:i:s'), null, null,
                $evaluation->getId(),
                $count
            );
            $count++;

            $job = $FACTORIES::getJobFactory()->save($job);
            $allJobs[] = $job;
            $event = new Event(0, "<a href='/job/detail/id=" . $job->getId() . "'>Job</a> Created", date('Y-m-d H:i:s'),
                "A new job was created for evaluation '" . $evaluation->getName() . "'.", Define::EVENT_JOB, $job->getId(), Auth_Library::getInstance()->getUserID());
            $FACTORIES::getEventFactory()->save($event);
        }

        // generate correct names for jobs
        $arr = Util::getDifferentParameters($allJobs);
        $changingParameters = $arr[0];
        $jobParameters = $arr[1];
        foreach ($allJobs as $job) {
            /** @var $job Job */
            $label = [];
            foreach ($changingParameters as $changingParameter) {
                $label[] = $changingParameter . ": " . $jobParameters[$job->getId()][$changingParameter];
            }
            $job->setDescription("Job[" . implode(", ", $label) . "]");
            $FACTORIES::getJobFactory()->update($job);
        }
    }
}