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
use DBA\Evaluation;
use DBA\Experiment;
use DBA\Job;
use DBA\QueryFilter;

class Evaluation_Controller extends Controller {

    public $overview_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function overview() {
        global $FACTORIES;

        $auth = Auth_Library::getInstance();
        if (!empty($this->get['user'])) {
            if ($this->get['user'] == 'all') {
                $userId = 0;
                $this->view->assign('showAllUser', true);
            } else {
                $userId = intval($this->get['user']);
                $this->view->assign('showAllUser', false);
            }
        } else {
            $userId = $auth->getUserID();
            $this->view->assign('showAllUser', false);
        }
        $query = [];
        if ($userId > 0) {
            $qF = new QueryFilter(Experiment::USER_ID, $userId, "=");
            $experiments = $FACTORIES::getExperimentFactory()->filter(array($FACTORIES::FILTER => $qF));
            $query[$FACTORIES::FILTER] = new ContainFilter(Evaluation::EXPERIMENT_ID, Util::arrayOfIds($experiments));
        }

        $evaluations = $FACTORIES::getEvaluationFactory()->filter($query);
        $running = [];
        foreach ($evaluations as $evaluation) {
            $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
            $jobs = $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $qF));
            foreach ($jobs as $job) {
                if ($job->getStatus() != Define::JOB_STATUS_FINISHED && $job->getStatus() != Define::JOB_STATUS_ABORTED) {
                    $running[] = $evaluation;
                    break;
                }
            }
        }

        // pre-loading data
        $systems = $FACTORIES::getSystemFactory()->filter(array());
        $sySet = new DataSet();
        foreach ($systems as $system) {
            $sySet->addValue($system->getId(), $system);
        }
        $this->view->assign('systems', $sySet);

        $projects = $FACTORIES::getProjectFactory()->filter(array());
        $prSet = new DataSet();
        foreach ($projects as $project) {
            $prSet->addValue($project->getId(), $project);
        }
        $this->view->assign('projects', $prSet);

        $experiments = $FACTORIES::getExperimentFactory()->filter(array());
        $exSet = new DataSet();
        foreach ($experiments as $experiment) {
            $exSet->addValue($experiment->getId(), $experiment);
        }
        $this->view->assign('experiments', $exSet);

        $this->view->assign('evaluations-running', $running);
    }


    public $detail_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function detail() {
        global $FACTORIES;

        if (!empty($this->get['id'])) {
            $evaluation = $FACTORIES::getEvaluationFactory()->get($this->get['id']);
            if ($evaluation) {
                $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $jobs = $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $qF));
                $this->view->assign('evaluation', $evaluation);
                $experiment = $FACTORIES::getExperimentFactory()->get($evaluation->getExperimentId());
                $this->view->assign('experiment', $experiment);
                $this->view->assign('system', $FACTORIES::getSystemFactory()->get($experiment->getSystemId()));
                $this->view->assign('subjobs', $jobs);
                $sys = new System($evaluation->getSystemId());
                $this->view->assign('supportsShowResults', $sys->supportsFullResults());
                // check if all jobs have finished
                $isFinished = true;
                foreach ($jobs as $subJob) {
                    if ($subJob->getStatus() != Define::JOB_STATUS_FINISHED) {
                        $isFinished = false;
                    }
                }
                if (sizeof($jobs) == 0) {
                    $isFinished = false;
                }
                $this->view->assign('isFinished', $isFinished);
            } else {
                throw new Exception("No evaluation with id: " . $this->get['id']);
            }
        } else {
            throw new Exception("No evaluation id provided!");
        }
    }


    public $download_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function download() {
        global $FACTORIES;

        if (!empty($this->get['id'])) {
            $evaluation = $FACTORIES::getEvaluationFactory()->get($this->get['id']);
            if ($evaluation) {
                $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $jobs = $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $qF));
                // check if all jobs have finished
                $isFinished = true;
                foreach ($jobs as $job) {
                    if ($job->getStatus() != Define::JOB_STATUS_FINISHED) {
                        $isFinished = false;
                    }
                }
                if ($isFinished) {
                    $this->view->setBinaryOutputMode(true);
                    $this->view->assign('jobs', $jobs);
                    $this->view->assign('evaluation', $evaluation);
                } else {
                    throw new Exception("Evaluation with id: " . $this->get['id'] . " is not finished!");
                }
            } else {
                throw new Exception("No evaluation with id: " . $this->get['id']);
            }
        } else {
            throw new Exception("No evaluation id provided!");
        }
    }

}
