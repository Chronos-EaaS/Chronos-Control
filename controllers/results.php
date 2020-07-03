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

use DBA\Factory;
use DBA\Job;
use DBA\QueryFilter;

class Results_Controller extends Controller {
    public $build_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function build() {
        if (!empty($this->get['systemId']) && !empty($this->get['type']) && !empty($this->get['resultId'])) {
            $system = new System($this->get['systemId']);
            $resultId = $this->get['resultId'];
            $builder = new Results_Library($system, $resultId);
            $this->view->assign('system', $system->getModel());
            $this->view->assign('resultId', $resultId);
            $this->view->assign('experimentId', 0);
            $this->view->assign('content', $builder->buildContent(intval($this->get['type'])));
            $this->view->assign('type', intval($this->get['type']));

            $plots = Util::getDefaultResultPlots();
            $system->getResultPlots($plots);
            $this->view->assign('plots', $plots);
        } else if (!empty($this->get['experimentId']) && !empty($this->get['type']) && !empty($this->get['resultId'])) {
            $experiment = Factory::getExperimentFactory()->get($this->get['experimentId']);
            $system = new System($experiment->getSystemId());
            $resultId = $this->get['resultId'];
            $builder = new Results_Library($system, $resultId);
            $this->view->assign('system', $system->getModel());
            $this->view->assign('resultId', $resultId);
            $this->view->assign('experimentId', $experiment->getId());
            $this->view->assign('experiment', $experiment);
            $this->view->assign('content', $builder->buildContent(intval($this->get['type'])));
            $this->view->assign('type', intval($this->get['type']));

            $plots = Util::getDefaultResultPlots();
            $system->getResultPlots($plots);
            $this->view->assign('plots', $plots);
        } else {
            throw new Exception("No system/experiment id / type provided!");
        }
    }

    public $show_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function show() {
        $evaluation = Factory::getEvaluationFactory()->get($this->get['id']);
        if ($evaluation == null) {
            throw new Exception("Invalid project ID " . $this->get['id']);
        }
        $system = new System($evaluation->getSystemId());
        $experiment = Factory::getExperimentFactory()->get($evaluation->getExperimentId());
        if (strlen($experiment->getResultId()) == 0) {
            throw new ProcessException("No results settings selected for this experiment!");
        }
        $builder = new Results_Library($system, $experiment->getResultId());
        $qF1 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
        $qF2 = new QueryFilter(Job::STATUS, Define::JOB_STATUS_FINISHED, "=");
        $jobs = Factory::getJobFactory()->filter([Factory::FILTER => [$qF1, $qF2]]);
        $numFinishedJobs = sizeof($jobs);

        // group jobs with same settings together
        $groupedJobs = [];
        foreach ($jobs as $job) {
            if (!isset($groupedJobs[$job->getConfigurationIdentifier()])) {
                $groupedJobs[$job->getConfigurationIdentifier()] = [];
            }
            $groupedJobs[$job->getConfigurationIdentifier()][] = $job;
        }

        $this->view->assign('evaluation', $evaluation);
        $this->view->assign('system', $system->getModel());
        $this->view->assign('experiment', $experiment);
        $this->view->assign('content', $builder->buildResults($groupedJobs, $this->view, $numFinishedJobs, $evaluation->getId()));
    }
}