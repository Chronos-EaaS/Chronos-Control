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
use DBA\Job;
use DBA\ProjectUser;
use DBA\QueryFilter;

class Job_Controller extends Controller {

    public $jobs_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function jobs() {
        global $FACTORIES;

        $auth = Auth_Library::getInstance();
        if (!empty($this->get['status']) && $this->get['status'] == "all") {
            $status = 'all';
            $this->view->assign('showOnlyActive', false);
        } else {
            $status = 'active';
            $this->view->assign('showOnlyActive', true);
        }

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

        $filters = [];
        if ($userId > 0) {
            $filters[] = new QueryFilter(Job::USER_ID, $userId, "=");
        }
        if ($status == 'active') {
            $filters[] = new ContainFilter(Job::STATUS, array(Define::JOB_STATUS_SCHEDULED, Define::JOB_STATUS_RUNNING, Define::JOB_STATUS_FAILED));
        }

        $this->view->assign('jobs', $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $filters)));

        $evaluations = $FACTORIES::getEvaluationFactory()->filter(array());
        $evSet = new DataSet();
        foreach ($evaluations as $evaluation) {
            $evSet->addValue($evaluation->getId(), $evaluation);
        }
        $this->view->assign('evaluations', $evSet);

        $users = $FACTORIES::getUserFactory()->filter(array());
        $usSet = new DataSet();
        foreach ($users as $user) {
            $usSet->addValue($user->getId(), $user);
        }
        $this->view->assign('users', $usSet);

        $systems = $FACTORIES::getSystemFactory()->filter(array());
        $sySet = new DataSet();
        foreach ($systems as $system) {
            $sySet->addValue($system->getId(), $system);
        }
        $this->view->assign('systems', $sySet);
    }


    public $detail_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function detail() {
        global $FACTORIES;

        if (!empty($this->get['id'])) {
            $job = $FACTORIES::getJobFactory()->get($this->get['id']);

            if ($job) {
                // Check if the user has enough privileges to access this evaluation
                $auth = Auth_Library::getInstance();
                $evaluation = $FACTORIES::getEvaluationFactory()->get($job->getEvaluationId());
                $experiment = $FACTORIES::getExperimentFactory()->get($evaluation->getExperimentId());
                $project = $FACTORIES::getProjectFactory()->get($experiment->getProjectId());
                $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
                $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                $check = $FACTORIES::getProjectUserFactory()->filter(array($FACTORIES::FILTER => array($qF1, $qF2)), true);
                if ($check == null && $project->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                    throw new Exception("Not enough privileges to view this job!");
                }

                $this->view->assign('job', $job);
                $this->view->assign('phases', Util::getObjectFromPhasesBitMask($job->getPhases()));
                $this->view->assign('user', $FACTORIES::getUserFactory()->get($job->getUserId()));
                $evaluation = $FACTORIES::getEvaluationFactory()->get($job->getEvaluationId());
                $this->view->assign('evaluation', $evaluation);
                $this->view->assign('experiment', $FACTORIES::getExperimentFactory()->get($evaluation->getExperimentId()));

                $events = Util::eventFilter(array('job' => $job));
                $this->view->assign('events', $events);
            } else {
                throw new Exception("No job with id: " . $this->get['id']);
            }
        } else {
            throw new Exception("No job id provided!");
        }
    }
}