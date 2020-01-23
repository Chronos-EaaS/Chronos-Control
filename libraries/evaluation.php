<?php

use DBA\Evaluation;
use DBA\Event;
use DBA\Experiment;
use DBA\Job;
use DBA\QueryFilter;

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
        $jobBuffer = [];
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

            $jobBuffer[] = $job;
        }
        $FACTORIES::getJobFactory()->massSave($jobBuffer);

        $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
        $allJobs = $FACTORIES::getJobFactory()->filter([$FACTORIES::FILTER => $qF]);
        $events = [];
        foreach($allJobs as $j) {
            $events[] = new Event(0, "<a href='/job/detail/id=" . $j->getId() . "'>Job</a> Created", date('Y-m-d H:i:s'),
                "A new job was created for evaluation '" . $evaluation->getName() . "'.", Define::EVENT_JOB, $j->getId(), Auth_Library::getInstance()->getUserID());
        }
        $FACTORIES::getEventFactory()->massSave($events);

        // generate correct names for jobs
        $arr = Util::getDifferentParameters($allJobs);
        $changingParameters = $arr[0];
        $jobParameters = $arr[1];
        $updateSet = [];
        foreach ($allJobs as $job) {
            /** @var $job Job */
            $label = [];
            foreach ($changingParameters as $changingParameter) {
                $label[] = $changingParameter . ": " . $jobParameters[$job->getId()][$changingParameter];
            }
            $updateSet[] = new MassUpdateSet($job->getId(), "Job[" . implode(", ", $label) . "]");
        }
        $FACTORIES::getJobFactory()->massSingleUpdate(Job::JOB_ID, Job::DESCRIPTION, $updateSet);
    }
}