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
use DBA\ExperimentView;
use DBA\Factory;
use DBA\Job;
use DBA\ProjectUser;
use DBA\QueryFilter;

class Evaluation_Controller extends Controller {

    public $overview_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function overview() {
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
            $qF = new QueryFilter(ExperimentView::PROJECT_USER_ID, $userId, "=");
            $experiments = Factory::getExperimentViewFactory()->filter([Factory::FILTER => $qF]);
            $query[Factory::FILTER] = new ContainFilter(Evaluation::EXPERIMENT_ID, Util::arrayOfIds($experiments));
        }

        $evaluations = Factory::getEvaluationFactory()->filter($query);
        $running = [];
        foreach ($evaluations as $evaluation) {
            $qF1 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
            $qF2 = new ContainFilter(Job::STATUS, [Define::JOB_STATUS_FAILED, Define::JOB_STATUS_RUNNING, Define::JOB_STATUS_SCHEDULED]);
            $count = Factory::getJobFactory()->countFilter([Factory::FILTER => [$qF1, $qF2]]);
            if ($count > 0) {
                $running[] = $evaluation;
            }
        }

        // pre-loading data
        $systems = Factory::getSystemFactory()->filter([]);
        $sySet = new DataSet();
        foreach ($systems as $system) {
            $sySet->addValue($system->getId(), $system);
        }
        $this->view->assign('systems', $sySet);

        $projects = Factory::getProjectFactory()->filter([]);
        $prSet = new DataSet();
        foreach ($projects as $project) {
            $prSet->addValue($project->getId(), $project);
        }
        $this->view->assign('projects', $prSet);

        $experiments = Factory::getExperimentFactory()->filter([]);
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
        if (!empty($this->get['id'])) {
            $evaluation = Factory::getEvaluationFactory()->get($this->get['id']);
            if ($evaluation) {
                if (!empty($this->post['reschedule']) && $this->post['reschedule'] == 'all') {
                    $qF1 = new ContainFilter(Job::STATUS, [Define::JOB_STATUS_FAILED, Define::JOB_STATUS_ABORTED]);
                    $qF2 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                    $uS = new UpdateSet(Job::STATUS, Define::JOB_STATUS_SCHEDULED);
                    Factory::getJobFactory()->massUpdate([Factory::FILTER => [$qF1, $qF2], Factory::UPDATE => $uS]);
                } else if (!empty($this->post['abort']) && $this->post['abort'] == 'all') {
                    $qF1 = new ContainFilter(Job::STATUS, [Define::JOB_STATUS_SCHEDULED, Define::JOB_STATUS_RUNNING, Define::JOB_STATUS_FAILED]);
                    $qF2 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                    $uS = new UpdateSet(Job::STATUS, Define::JOB_STATUS_ABORTED);
                    Factory::getJobFactory()->massUpdate([Factory::FILTER => [$qF1, $qF2], Factory::UPDATE => $uS]);
                } else if (!empty($this->post['star'])) {
                    if ($evaluation->getIsStarred()) {
                        throw new ProcessException("Evaluation is already starred!");
                    }
                    $evaluation->setIsStarred(1);
                    Factory::getEvaluationFactory()->update($evaluation);
                } else if (!empty($this->post['unstar'])) {
                    if ($evaluation->getIsStarred() == 0) {
                        throw new ProcessException("Evaluation is not starred yet!");
                    }
                    $evaluation->setIsStarred(0);
                    Factory::getEvaluationFactory()->update($evaluation);
                }

                $experiment = Factory::getExperimentFactory()->get($evaluation->getExperimentId());

                // Check if the user has enough privileges to access this evaluation
                $auth = Auth_Library::getInstance();
                $project = Factory::getProjectFactory()->get($experiment->getProjectId());
                $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
                $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
                if ($check == null && !$auth->isAdmin()) {
                    throw new Exception("Not enough privileges to view this evaluation!");
                }

                $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $jobs = Factory::getJobFactory()->filter([Factory::FILTER => $qF]);
                $this->view->assign('evaluation', $evaluation);
                $this->view->assign('experiment', $experiment);
                $this->view->assign('system', Factory::getSystemFactory()->get($experiment->getSystemId()));
                $this->view->assign('subjobs', $jobs);
                $sys = new System($evaluation->getSystemId());
                $this->view->assign('supportsShowResults', $sys->supportsFullResults());
                // check if all jobs have finished
                $isFinished = true;
                $resultsAvailable = false;
                foreach ($jobs as $subJob) {
                    if ($subJob->getStatus() != Define::JOB_STATUS_FINISHED) {
                        $isFinished = false;
                    } else {
                        $resultsAvailable = true;
                        $logUtil = new LogUtils($subJob);
                        $logUtil->examineLogAndSetAlert();
                    }
                }
                if (sizeof($jobs) == 0) {
                    $isFinished = false;
                }
                $this->view->assign('isFinished', $isFinished);
                $this->view->assign('resultsAvailable', $resultsAvailable);
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
        if (!empty($this->get['id'])) {
            $evaluation = Factory::getEvaluationFactory()->get($this->get['id']);
            if ($evaluation) {
                // Check if the user has enough privileges to download this evaluation
                $auth = Auth_Library::getInstance();
                $experiment = Factory::getExperimentFactory()->get($evaluation->getExperimentId());
                $project = Factory::getProjectFactory()->get($experiment->getProjectId());
                $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
                $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
                if ($check == null && !$auth->isAdmin()) {
                    throw new Exception("Not enough privileges to download this evaluation!");
                }

                $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $jobs = Factory::getJobFactory()->filter([Factory::FILTER => $qF]);
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
