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

use DBA\Evaluation;
use DBA\Event;
use DBA\Experiment;
use DBA\Job;
use DBA\QueryFilter;
use DBA\QueryFilterNoCase;

abstract class System extends Controller {

    protected $app;

    private $newEvaluationJobs;
    protected $postData;
    private $experiment;
    protected $projectId;

    /**
     * System constructor.
     * @param $getVars
     * @param $system
     * @throws Exception
     */
    public function __construct($getVars, $system) {
        global $FACTORIES;

        parent::__construct($getVars);
        $this->app = new stdClass();
        $this->app->system = Systems_Library::getSystem($system);
        $this->app->logger = Logger_Library::getInstance();
        $this->app->userId = Auth_Library::getInstance()->getUserID();
        $qF = new QueryFilterNoCase(\DBA\System::NAME, $this->app->system->uniqueName, "=");
        $system = $FACTORIES::getSystemFactory()->filter(array($FACTORIES::FILTER => $qF), true);

        $this->app->settings = Settings_Library::getInstance($system->getId());
        $this->app->defaultValues = $this->app->settings->getSection('defaultValues');

        if (isset($this->get['createEvaluation'])) {
            $this->experiment = $FACTORIES::getExperimentFactory()->get($this->get['createEvaluation']);
            $this->postData = json_decode($this->experiment->getPostData(), true);
        } else if (isset($this->get['projectId'])) {
            $this->projectId = $this->get['projectId'];
        }
    }

    /**
     * @throws Exception
     */
    public function __destruct() {
        global $FACTORIES;

        if ($this->view->getType() != 'system-api') {
            $this->view->assign('app', $this->app);

            // add predefined
            $settings = $this->app->settings;
            $predefined = json_decode($settings->get('predefined', $this->app->userId), true);
            if (!isset($predefined)) {
                $predefined = array();
            }
            $this->view->assign('predefined', $predefined);

            // add environments
            $settings = $this->app->settings;
            $environments = $settings->get('environments');
            if (!isset($environments)) {
                $environments = array();
            }
            $this->view->assign('environments', $environments);
        }

        $user = Auth_Library::getInstance()->getUser();
        if (!$this->postData && isset($this->post['name'])) {
            $this->postData = json_encode($this->post);
            $name = trim($this->post['name']);
            $description = trim($this->post['description']);
            $systemId = $this->app->system->id;

            $job['phase_prepare'] = isset($this->post['phase_prepare']) ? boolval($this->post['phase_prepare']) : true;
            $job['phase_warmUp'] = isset($this->post['phase_warmUp']) ? boolval($this->post['phase_warmUp']) : true;
            $job['phase_execute'] = isset($this->post['phase_execute']) ? boolval($this->post['phase_execute']) : true;
            $job['phase_analyze'] = isset($this->post['phase_analyze']) ? boolval($this->post['phase_analyze']) : true;
            $job['phase_clean'] = isset($this->post['phase_clean']) ? boolval($this->post['phase_clean']) : true;
            $phases = Util::calcPhasesBitMask($job['phase_prepare'], $job['phase_warmUp'], $job['phase_execute'], $job['phase_analyze'], $job['phase_clean']);

            $userId = Auth_Library::getInstance()->getUserID();

            $qF = new QueryFilter(Experiment::PROJECT_ID, trim($this->projectId), "=");
            $count = $FACTORIES::getExperimentFactory()->countFilter(array($FACTORIES::FILTER => $qF));
            $experiment = new Experiment(0, $name, $userId, $description, 2, $systemId, $phases, 0, date('Y-m-d H:i:s'), trim($this->post['projectId']), $this->postData, $count + 1);
            $experiment = $FACTORIES::getExperimentFactory()->save($experiment);

            $project = $FACTORIES::getProjectFactory()->get($experiment->getProjectId());

            $event = new Event(0, "New Experiment: <a href='/experiment/detail/id=" . $experiment->getId() . "'>$name</a>",
                date('Y-m-d H:i:s'), "A new experiment named '$name' was created for project '" . $project->getName() . "' by " . $user->getFirstname() . " " . $user->getLastname() . ".",
                Define::EVENT_EXPERIMENT, $experiment->getId(), $user->getId());
            $FACTORIES::getEventFactory()->save($event);

            $this->view->internalRedirect('experiment', 'detail', array('id' => $experiment->getId()));
        }

        // Create evaluation jobs
        if (!empty($this->newEvaluationJobs) && count($this->newEvaluationJobs) > 0) {
            // this will then be required when experiment is applied
            if (count($this->newEvaluationJobs) > MAX_JOBS_PER_EVALUATION) {
                throw new Exception("This would create more than " . MAX_JOBS_PER_EVALUATION . " jobs. Giving up!");
            }
            // Create evaluation
            $qF = new QueryFilter(Evaluation::EXPERIMENT_ID, $this->experiment->getId(), "=");
            $count = $FACTORIES::getEvaluationFactory()->countFilter(array($FACTORIES::FILTER => $qF));
            $evaluation = new Evaluation(0, $this->experiment->getName(), $this->experiment->getDescription(), $this->experiment->getSystemId(), $this->experiment->getId(), $count + 1);
            $evaluation = $FACTORIES::getEvaluationFactory()->save($evaluation);

            $event = new Event(0, "Evaluation Started: <a href='/evaluation/detail/id=" . $evaluation->getId() . "'>" . $evaluation->getName() . "</a>", date('Y-m-d H:i:s'),
                "A new evaluation of experiment '" . $this->experiment->getName() . "' was started.", Define::EVENT_EVALUATION, $evaluation->getId(), $user->getId());
            $FACTORIES::getEventFactory()->save($event);

            $count = 1;
            foreach ($this->newEvaluationJobs as $job) {
                if (empty($this->postData['environment'])) {
                    $job['environment'] = '';
                } else {
                    $job['environment'] = $this->postData['environment'];
                }
                $job['phase_prepare'] = isset($this->postData['phase_prepare']) ? boolval($this->postData['phase_prepare']) : true;
                $job['phase_warmUp'] = isset($this->postData['phase_warmUp']) ? boolval($this->postData['phase_warmUp']) : true;
                $job['phase_execute'] = isset($this->postData['phase_execute']) ? boolval($this->postData['phase_execute']) : true;
                $job['phase_analyze'] = isset($this->postData['phase_analyze']) ? boolval($this->postData['phase_analyze']) : true;
                $job['phase_clean'] = isset($this->postData['phase_clean']) ? boolval($this->postData['phase_clean']) : true;

                $cdl = trim($job['cdl']);

                $phases = Util::calcPhasesBitMask($job['phase_prepare'], $job['phase_warmUp'], $job['phase_execute'], $job['phase_analyze'], $job['phase_clean']);

                $status = Define::JOB_STATUS_SCHEDULED;

                $job = new Job(0,
                    $this->experiment->getUserId(),
                    $job['description'],
                    $this->experiment->getType(),
                    $this->experiment->getSystemId(),
                    $job['environment'],
                    $phases, $cdl, $status, 0, '',
                    date('Y-m-d H:i:s'), null, null,
                    $evaluation->getId(),
                    $count
                );
                $count++;
                $FACTORIES::getJobFactory()->save($job);
                $event = new Event(0, "<a href='/job/detail/id=" . $job->getId() . "'>Job</a> Created", date('Y-m-d H:i:s'),
                    "A new job was created for evaluation '" . $evaluation->getName() . "'.", Define::EVENT_JOB, $job->getId(), $user->getId());
                $FACTORIES::getEventFactory()->save($event);
            }
            $this->view->internalRedirect('evaluation', 'detail', array('id' => $evaluation->getId()));
        }
    }

    /**
     * @param $action
     * @throws Exception
     */
    public function __before($action) {
        global $FACTORIES;

        switch (strtolower($action)) {
            case strtolower('createAnalysis'):
            case strtolower('createData'):
            case strtolower('createEvaluation'):
            case strtolower('wizard'):
                $this->view->setTemplate('newJob');
                break;

            case strtolower('evaluationResults'):
                $this->view->setTemplate('results');
                if (empty($this->get['id'])) {
                    throw new Exception("No id provided!");
                }
                $evaluation = $FACTORIES::getEvaluationFactory()->get($this->get['id']);
                if (!$evaluation) {
                    throw new Exception("Evaluation not found: " . $this->get['id']);
                }
                if ($evaluation->getSystemId() != $this->app->system->id) {
                    throw new Exception("Wrong system: " . $evaluation->getSystemId());
                }
                $this->app->evaluation = $evaluation;
                $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $this->app->evaluation->jobs = $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $qF));
                break;

            default:
                break;
        }
    }

    /**
     * Add a new evaluation job
     * @param String $description
     * @param CDL_Library $cdl
     */
    protected function addEvaluationJob($description, $cdl) {
        $jobData = array();
        $jobData['description'] = $description;
        $jobData['type'] = 2;
        $jobData['cdl'] = $cdl->toXML();
        $jobData['user'] = $this->app->userId;
        $jobData['system'] = $this->app->system->uniqueName;
        $this->newEvaluationJobs[] = $jobData;
    }

    protected function newCDL() {
        return new CDL_Library($this->app->system);
    }

    public $savePredefined_access = Auth_Library::A_LOGGEDIN;

    public function savePredefined() {
        if (!empty($this->post['name'])) {
            $settings = $this->app->settings;
            $predefined = json_decode($settings->get('predefined', $this->app->userId), true);

            if (empty($predefined)) {
                $predefined = array();
            }

            $name = trim($this->post['name']);

            if (!isset($predefined[$name])) {
                $predefined[$name] = $this->post;
                $settings->set('predefined', $this->app->userId, json_encode($predefined));
                $this->view->assign('result', 'success');
            } else {
                $this->view->assign('result', 'Name already in use');
            }
        }
    }


    public $getPredefined_access = Auth_Library::A_LOGGEDIN;

    public function getPredefined() {
        if (!empty($this->get['name'])) {
            $settings = $this->app->settings;

            $name = urldecode($this->get['name']);
            $predefined = json_decode($settings->get('predefined', $this->app->userId), true);

            if (isset($predefined)) {
                if (isset($predefined[$name])) {
                    $this->view->assign('predefined', $predefined[$name]);
                } else {
                    // This name does not exist
                }
            } else {
                // User has no predefined schemas and operations
            }
        }
    }


    public $wizard_access = Auth_Library::A_LOGGEDIN;

    public function wizard() {
        // throw exception
    }

    public $createData_access = Auth_Library::A_LOGGEDIN;

    public function createData() {
        // throw exception
    }

    public $createEvaluation_access = Auth_Library::A_LOGGEDIN;

    public function createEvaluation() {
        // throw exception
    }

    public $createAnalysis_access = Auth_Library::A_LOGGEDIN;

    public function createAnalysis() {
        // throw exception
    }

    public $evaluationResults_access = Auth_Library::A_LOGGEDIN;

    public function evaluationResults() {
        // throw exception
    }

}