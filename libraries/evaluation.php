<?php

use DBA\Evaluation;
use DBA\Event;
use DBA\Experiment;
use DBA\Factory;
use DBA\Job;
use DBA\QueryFilter;

class Evaluation_Library {
    private $jobs;
    /** @var $experiment Experiment */
    private $experiment;

    public function __construct($experiment) {
        $this->jobs = [];
        $this->experiment = $experiment;
    }

    /**
     * @param $description
     * @param $configuration []
     * @throws Exception
     */
    public function addEvaluationJob($description, $configuration) {
        $job = [];
        $job['description'] = $description;
        $job['type'] = 2;
        $job['configuration'] = $configuration;
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
        $count = 1;
        $jobBuffer = [];
        foreach ($this->jobs as $jobData) {
            $jobData['phase_prepare'] = isset($data['phase_prepare']) ? boolval($data['phase_prepare']) : true;
            $jobData['phase_warmUp'] = isset($data['phase_warmUp']) ? boolval($data['phase_warmUp']) : true;
            $jobData['phase_execute'] = isset($data['phase_execute']) ? boolval($data['phase_execute']) : true;
            $jobData['phase_analyze'] = isset($data['phase_analyze']) ? boolval($data['phase_analyze']) : true;
            $jobData['phase_clean'] = isset($data['phase_clean']) ? boolval($data['phase_clean']) : true;

            $configuration = json_encode($jobData['configuration']);
            $phases = Util::calcPhasesBitMask($jobData['phase_prepare'], $jobData['phase_warmUp'], $jobData['phase_execute'], $jobData['phase_analyze'], $jobData['phase_clean']);
            $status = Define::JOB_STATUS_SCHEDULED;

            $job = new Job(0,
                $this->experiment->getUserId(),
                $jobData['description'],
                $this->experiment->getSystemId(),
                $environment,
                $phases, $configuration, $status, 0, '',
                date('Y-m-d H:i:s'), null, null,
                $evaluation->getId(),
                $count,
                ''
            );
            $count++;

            $jobBuffer[] = $job;
        }
        Factory::getJobFactory()->massSave($jobBuffer);

        $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
        $allJobs = Factory::getJobFactory()->filter([Factory::FILTER => $qF]);
        $events = [];
        foreach ($allJobs as $j) {
            $events[] = new Event(0, "<a href='/job/detail/id=" . $j->getId() . "'>Job</a> Created", date('Y-m-d H:i:s'),
                "A new job was created for evaluation '" . $evaluation->getName() . "'.", Define::EVENT_JOB, $j->getId(), Auth_Library::getInstance()->getUserID());
        }
        Factory::getEventFactory()->massSave($events);

        print_r($allJobs);

        // generate correct names for jobs
        $arr = Util::getDifferentParameters($allJobs);
        $changingParameters = $arr[0];
        $jobParameters = $arr[1];
        $labelUpdateSet = [];
        $identifierUpdateSet = [];
        $titleUpdateSet = [];
        foreach ($allJobs as $job) {
            /** @var $job Job */
            $label = [];
            $identifier = [];
            foreach ($changingParameters as $changingParameter) {
                $part = $changingParameter . ": " . $jobParameters[$job->getId()][$changingParameter];
                $label[] = $part;
                if ($changingParameter != 'run') {
                    $identifier[] = $part;
                }
            }
            $configuration = $job->getConfiguration();
            $configuration[DEFINE::CONFIGURATION_TITLE] = "Job[" . implode(", ", $identifier) . "]";
            $titleUpdateSet[] = new MassUpdateSet($job->getId(), json_encode($configuration));
            $labelUpdateSet[] = new MassUpdateSet($job->getId(), "Job[" . implode(", ", $label) . "]");
            $identifierUpdateSet[] = new MassUpdateSet($job->getId(), sha1(implode(",", $identifier)));
        }
        Factory::getJobFactory()->massSingleUpdate(Job::JOB_ID, Job::CONFIGURATION, $titleUpdateSet);
        Factory::getJobFactory()->massSingleUpdate(Job::JOB_ID, Job::DESCRIPTION, $labelUpdateSet);
        Factory::getJobFactory()->massSingleUpdate(Job::JOB_ID, Job::CONFIGURATION_IDENTIFIER, $identifierUpdateSet);
    }
}