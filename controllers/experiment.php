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
use DBA\EvaluationRunningView;
use DBA\Factory;
use DBA\Node;
use DBA\QueryFilter;
use DBA\ProjectUser;

class Experiment_Controller extends Controller {

    public $detail_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function detail() {
        if (!empty($this->get['id'])) {
            $experiment = Factory::getExperimentFactory()->get($this->get['id']);
            if ($experiment) {
                // Check if the user has enough privileges to access this experiment
                $auth = Auth_Library::getInstance();
                $project = Factory::getProjectFactory()->get($experiment->getProjectId());
                $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
                $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
                if ($check == null && !$auth->isAdmin()) {
                    throw new Exception("Not enough privileges to view this experiment!");
                }
                $systemLib = new System($experiment->getSystemId());

                if (!empty($this->post['createResult'])) {
                    $resultId = "experiment-" . $experiment->getId() . "-" . uniqid();
                    $systemLib->createNewResults($resultId);
                } else if (!empty($this->post['copyResult'])) {
                    $resultId = $this->post['resultId'];
                    if ($resultId == "") {
                        throw new ProcessException("No result ID defined!");
                    }
                    $resultAll = $systemLib->getResultsAll($resultId);
                    $resultJob = $systemLib->getResultsJob($resultId);
                    if ($resultAll === false || $resultJob === false) {
                        throw new ProcessException("Results ID not found!");
                    }
                    $resultId = "experiment-" . $experiment->getId() . "-" . uniqid();
                    $systemLib->createNewResults($resultId);
                    $systemLib->setResultsAll($resultAll, $resultId);
                    $systemLib->setResultsJob($resultJob, $resultId);
                } else if (!empty($this->post['renameResult'])) {
                    $resultId = $this->post['resultId'];
                    $name = htmlentities($this->post["newName"], ENT_QUOTES, "UTF-8");
                    if ($resultId == "") {
                        throw new ProcessException("No result ID defined!");
                    } else if (strpos($resultId, "system-") === 0) {
                        throw new ProcessException("You are not allowed to rename this result!");
                    }
                    $systemLib->renameResults($resultId, $name);
                } else if (!empty($this->post['deleteResult'])) {
                    $resultId = $this->post['resultId'];
                    if ($resultId == "") {
                        throw new ProcessException("No result ID defined!");
                    } else if (strpos($resultId, "system") === 0) {
                        throw new ProcessException("No permission to delete system-wide result ID here!");
                    } else if ($experiment->getResultId() == $resultId) {
                        throw new ProcessException("You cannot delete this result as it is selected currently!");
                    }
                    $systemLib->deleteResults($resultId);
                } else if (!empty($this->get['select'])) {
                    $data = $systemLib->getResultsAll($this->get['select']);
                    if (strlen($data) > 0) {
                        $experiment->setResultId($this->get['select']);
                        Factory::getExperimentFactory()->update($experiment);
                    }
                } else if (!empty($this->post['archiveEvaluation'])) {
                    $evaluation = Factory::getEvaluationFactory()->get($this->post['evaluationId']);
                    if ($evaluation == null) {
                        throw new ProcessException("Invalid Evaluation!");
                    } else if ($evaluation->getExperimentId() != $experiment->getId()) {
                        throw new ProcessException("Evaluation does not belong to this experiment!");
                    } else if ($evaluation->getIsArchived() == 1) {
                        throw new ProcessException("Evaluation is already archived!");
                    }
                    // check evaluation running view
                    $qF = new QueryFilter(EvaluationRunningView::EVALUATION_ID, $evaluation->getId(), "=");
                    $check = Factory::getEvaluationRunningViewFactory()->filter([Factory::FILTER => $qF], true);
                    if ($check != null) {
                        throw new ProcessException("This evaluation is still running!");
                    }
                    $evaluation->setIsArchived(1);
                    Factory::getEvaluationFactory()->update($evaluation);
                } else if (!empty($this->post['unarchiveEvaluation'])) {
                    $evaluation = Factory::getEvaluationFactory()->get($this->post['evaluationId']);
                    if ($evaluation == null) {
                        throw new ProcessException("Invalid Evaluation!");
                    } else if ($evaluation->getExperimentId() != $experiment->getId()) {
                        throw new ProcessException("Evaluation does not belong to this experiment!");
                    } else if ($evaluation->getIsArchived() == 0) {
                        throw new ProcessException("Evaluation is not archived!");
                    }
                    $evaluation->setIsArchived(0);
                    Factory::getEvaluationFactory()->update($evaluation);
                }

                $qF1 = new QueryFilter(Evaluation::EXPERIMENT_ID, $experiment->getId(), "=");
                $qF2 = new QueryFilter(Evaluation::IS_ARCHIVED, 0, "=");
                if (isset($this->get['show']) && $this->get['show'] == 'archived') {
                    $this->view->assign('show', 'archived');
                    $qF2 = new QueryFilter(Evaluation::IS_ARCHIVED, 1, "=");
                }
                $evaluations = Factory::getEvaluationFactory()->filter([Factory::FILTER => [$qF1, $qF2]]);

                $this->view->assign('experiment', $experiment);
                $this->view->assign('evaluations', $evaluations);

                $events = Util::eventFilter(['experiment' => $experiment]);
                $this->view->assign('events', $events);

                $systemLib = new System($experiment->getSystemId());
                $results = json_decode($systemLib->getResultsAll(), true);
                $resultsList = [];
                foreach ($results['elements'] as $id => $value) {
                    if (strpos($id, "system-") === 0 || strpos($id, "experiment-" . $experiment->getId() . "-") === 0) {
                        $resultsList[$id] = $value;
                    }
                }
                $this->view->assign('results', $resultsList);

                $this->view->assign('system', Factory::getSystemFactory()->get($experiment->getSystemId()));

                // Build environments info
                // cem-navy => stdClass(key="cem-navy", name="navy", type="cem", displayStr="navy (15 nodes)", default)
                $environments = [];
                $settings = Settings_Library::getInstance(0);
                $cemEnvironmentsStr = $settings->get("cem", "environments");
                if (isset($cemEnvironmentsStr)) {
                    $cemEnvironments = json_decode($cemEnvironmentsStr->getValue());
                } else {
                    $cemEnvironments = [];
                }
                foreach ($cemEnvironments as $key) {
                    $environments["cem-".$key] = new stdClass();
                    $environments["cem-".$key]->key = "cem-".$key;
                    $environments["cem-".$key]->name = $key;
                    $environments["cem-".$key]->type = "cem";
                    $filter[] = new QueryFilter(Node::ENVIRONMENT, $key, "=");
                    $nodeMissingTimeoutSeconds = Settings_Library::getInstance(0)->get("cem", "nodeMissingTimeout");
                    $filter[] = new QueryFilter(Node::LAST_UPDATE, date('Y-m-d H:i:s', strtotime('-' . intval($nodeMissingTimeoutSeconds->getValue()) . ' seconds')), ">");
                    $numNodes = Factory::getNodeFactory()->countFilter([Factory::FILTER => $filter]);
                    $environments["cem-".$key]->displayStr = $key." (CEM: ".$numNodes." nodes)";
                    if (isset(json_decode($experiment->getPostData(), true)['environment']) && json_decode($experiment->getPostData(), true)['environment'] == "cem-".$key) {
                        $environments["cem-".$key]->default = true;
                    } else {
                        $environments["cem-".$key]->default = false;
                    }
                }
                foreach (Settings_Library::getInstance($experiment->getSystemId())->getSection("environments") as $key => $value) {
                    $environments["system-".$key] = new stdClass();
                    $environments["system-".$key]->key = "system-".$key;
                    $environments["system-".$key]->name = $key;
                    $environments["system-".$key]->type = "system";
                    $environments["system-".$key]->displayStr = $key;
                    if (isset(json_decode($experiment->getPostData(), true)['environment']) && json_decode($experiment->getPostData(), true)['environment'] == "system-".$key) {
                        $environments["system-".$key]->default = true;
                    } else {
                        $environments["system-".$key]->default = false;
                    }
                }
                $this->view->assign('environments', $environments);
            } else {
                throw new Exception("No experiment with id: " . $this->get['id']);
            }
        } else {
            throw new Exception("No experiment id provided!");
        }
    }
}