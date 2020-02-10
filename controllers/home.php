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

use DBA\Event;
use DBA\Job;
use DBA\JoinFilter;
use DBA\OrderFilter;
use DBA\Project;
use DBA\ProjectUser;
use DBA\QueryFilter;

class Home_Controller extends Controller {


    public $main_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function main() {
        global $FACTORIES;

        $oF1 = new OrderFilter(Event::TIME, "DESC");
        $oF2 = new OrderFilter(Event::EVENT_ID, "DESC LIMIT 20");
        $this->view->assign('events', $FACTORIES::getEventFactory()->filter(array($FACTORIES::ORDER => array($oF1, $oF2))));

        $qF = new QueryFilter(Project::USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $projects = $FACTORIES::getProjectFactory()->countFilter([$FACTORIES::FILTER => $qF]);
        $qF = new QueryFilter(ProjectUser::USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $projects += $FACTORIES::getProjectUserFactory()->countFilter([$FACTORIES::FILTER => $qF]);
        $this->view->assign('numProjects', $projects);

        $this->view->assign('numExperiments', $FACTORIES::getExperimentFactory()->countFilter(array()));

        $jobs = $FACTORIES::getJobFactory()->filter(array());
        $jobMap = [];
        foreach ($jobs as $job) {
            if (isset($jobMap[$job->getEvaluationId()])) {
                $jobMap[$job->getEvaluationId()][] = $job;
            } else {
                $jobMap[$job->getEvaluationId()] = array($job);
            }
        }

        $evaluations = $FACTORIES::getEvaluationFactory()->filter(array());
        $count = array(0, 0);
        foreach ($evaluations as $evaluation) {
            $finished = true;
            if (!isset($jobMap[$evaluation->getId()])) {
                continue;
            }
            /** @var $job Job */
            foreach ($jobMap[$evaluation->getId()] as $job) {
                if ($job->getStatus() != Define::JOB_STATUS_ABORTED && $job->getStatus() != Define::JOB_STATUS_FINISHED) {
                    $finished = false;
                    break;
                }
            }
            if ($finished) {
                $count[1]++;
            } else {
                $count[0]++;
            }
        }
        $this->view->assign('numRunningEvaluations', $count[0]);
        $this->view->assign('numFinishedEvaluations', $count[1]);
    }

    public $error403_access = Auth_Library::A_PUBLIC;

    public function error403() {

    }

    public $error404_access = Auth_Library::A_PUBLIC;

    public function error404() {

    }

}
