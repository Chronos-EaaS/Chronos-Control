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

use DBA\EvaluationRunningView;
use DBA\EvaluationView;
use DBA\Event;
use DBA\ExperimentView;
use DBA\Factory;
use DBA\JobView;
use DBA\OrderFilter;
use DBA\ProjectUser;
use DBA\QueryFilter;

class Home_Controller extends Controller {


    public $main_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function main() {
        $oF1 = new OrderFilter(Event::TIME, "DESC");
        $oF2 = new OrderFilter(Event::EVENT_ID, "DESC LIMIT 20");
        $this->view->assign('events', Factory::getEventFactory()->filter([Factory::ORDER => [$oF1, $oF2]]));

        $qF = new QueryFilter(ProjectUser::USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $this->view->assign('numProjects', Factory::getProjectUserFactory()->countFilter([Factory::FILTER => $qF]));

        $qF = new QueryFilter(ExperimentView::PROJECT_USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $this->view->assign('numExperiments', Factory::getExperimentViewFactory()->countFilter([Factory::FILTER => $qF]));

        $qF = new QueryFilter(EvaluationView::PROJECT_USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $evaluations = Factory::getEvaluationViewFactory()->countFilter([Factory::FILTER => $qF]);

        $qF = new QueryFilter(EvaluationRunningView::PROJECT_USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $runningEvaluations = Factory::getEvaluationRunningViewFactory()->countFilter([Factory::FILTER => $qF]);

        $this->view->assign('numFinishedEvaluations', $evaluations - $runningEvaluations);
        $this->view->assign('numRunningEvaluations', $runningEvaluations);

        $qF1 = new QueryFilter(JobView::PROJECT_USER_ID, Auth_Library::getInstance()->getUserID(), "=");
        $this->view->assign('numJobs', Factory::getJobViewFactory()->countFilter([Factory::FILTER => $qF1]));

        $qF2 = new QueryFilter(JobView::STATUS, Define::JOB_STATUS_FAILED, "=");
        $this->view->assign('numFailedJobs', Factory::getJobViewFactory()->countFilter([Factory::FILTER => [$qF1, $qF2]]));

        $qF2 = new QueryFilter(JobView::STATUS, Define::JOB_STATUS_FAILED, "<>");
        $qF3 = new QueryFilter(JobView::STATUS, Define::JOB_STATUS_FINISHED, "<>");
        $qF4 = new QueryFilter(JobView::STATUS, Define::JOB_STATUS_ABORTED, "<>");
        $this->view->assign('numActiveJobs', Factory::getJobViewFactory()->countFilter([Factory::FILTER => [$qF1, $qF2, $qF3, $qF4]]));
    }

    public $error403_access = Auth_Library::A_PUBLIC;

    public function error403() {

    }

    public $error404_access = Auth_Library::A_PUBLIC;

    public function error404() {

    }

}
