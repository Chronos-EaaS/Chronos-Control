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
use DBA\QueryFilter;

class Experiment_Controller extends Controller {

    public $detail_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function detail() {
        global $FACTORIES;

        if (!empty($this->get['id'])) {
            $experiment = $FACTORIES::getExperimentFactory()->get($this->get['id']);
            if ($experiment) {
                // Check if the user has enough privileges to access this experiment
                $auth = Auth_Library::getInstance();
                $project = $FACTORIES::getProjectFactory()->get($experiment->getProjectId());
                $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
                $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                $check = $FACTORIES::getProjectUserFactory()->filter(array($FACTORIES::FILTER => array($qF1, $qF2)), true);
                if ($check == null && $project->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                    throw new Exception("Not enough privilege to view this experiment!");
                }

                $qF = new QueryFilter(Evaluation::EXPERIMENT_ID, $experiment->getId(), "=");
                $evaluations = $FACTORIES::getEvaluationFactory()->filter(array($FACTORIES::FILTER => $qF));

                $this->view->assign('experiment', $experiment);
                $this->view->assign('evaluations', $evaluations);

                $events = Util::eventFilter(array('experiment' => $experiment));
                $this->view->assign('events', $events);

                $this->view->assign('system', $FACTORIES::getSystemFactory()->get($experiment->getSystemId()));

                $settings = Settings_Library::getInstance($experiment->getSystemId());
                $this->view->assign('deployments', $settings->get('environments'));
            } else {
                throw new Exception("No experiment with id: " . $this->get['id']);
            }
        } else {
            throw new Exception("No experiment id provided!");
        }
    }
}