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
use DBA\Factory;
use DBA\ProjectUser;
use DBA\QueryFilter;

class Builder_Controller extends Controller {
    public $build_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function build() {
        if (!empty($this->get['id'])) {
            $system = new System($this->get['id']);

            // Check if privileges to view this system
            $auth = Auth_Library::getInstance();
            if ($system->getModel()->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to view this system!");
            }

            $builder = new Builder_Library($system);
            $this->view->assign('system', $system->getModel());
            $this->view->assign('content', $builder->buildContent());

            $elements = Util::getDefaultParameterElements();
            $system->getParameterElements($elements);
            $this->view->assign('elementTypes', $elements);
        } else {
            throw new Exception("No system id provided!");
        }
    }

    public $run_access = Auth_Library::A_LOGGEDIN;

    /**
     *
     * @throws Exception
     */
    public function run() {
        if (!empty($this->get['experimentId'])) {
            $experiment = Factory::getExperimentFactory()->get($this->get['experimentId']);
            if ($experiment == null) {
                throw new Exception("Invalid experiment ID " . $this->get['experimentId']);
            }

            // Check if the user has enough privileges to access this experiment
            $auth = Auth_Library::getInstance();
            $project = Factory::getProjectFactory()->get($experiment->getProjectId());
            $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
            $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
            $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
            if ($check == null && $project->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to view this experiment!");
            }

            // TODO: the whole building part maybe later can be put into the evaluation library library

            // load system
            $system = Factory::getSystemFactory()->get(Factory::getProjectFactory()->get($experiment->getProjectId())->getSystemId());
            $sys = new System($system->getId());

            $user = Auth_Library::getInstance()->getUser();
            $data = json_decode($experiment->getPostData(), true);
            $evaluation = new Evaluation_Library($experiment);

            $allElements = Util::getDefaultParameterElements();
            $sys->getParameterElements($allElements);

            $singleElements = [];
            $multiElements = [];
            foreach ($data['elements'] as $element) {
                $e = ["identifier" => $element, "parameter" => $data[$element . "-parameter"]];
                foreach ($allElements as $el) {
                    if ($el->getType() == $data[$element . '-type']) {
                        $e['obj'] = $el;
                    }
                }
                if (!isset($e['obj'])) {
                    throw new Exception("Invalid element on experiment: " . $data[$element . "-type"]);
                } else if ($e['obj']->generatesMultiJob()) {
                    $multiElements[] = $e;
                } else {
                    $singleElements[] = $e;
                }
            }

            $allConfigurations = [[Define::CONFIGURATION_PARAMETERS => []]];

            if ($data['run-distribution'] == 'order') {
                Builder_Library::apply_runs($data, $allConfigurations);
            }

            foreach ($multiElements as $element) {
                /** @var $elem Element */
                $elem = $element['obj'];
                $elem->process($data, $element['parameter'], $allConfigurations);
            }

            foreach ($singleElements as $element) {
                /** @var $elem Element */
                $elem = $element['obj'];
                $elem->process($data, $element['parameter'], $allConfigurations);
            }

            if ($data['run-distribution'] == 'alter') {
                Builder_Library::apply_runs($data, $allConfigurations);
            } else if ($data['run-distribution'] == 'rand') {
                Builder_Library::apply_runs($data, $allConfigurations);
                shuffle($allConfigurations);
            }

            $qF = new QueryFilter(Evaluation::EXPERIMENT_ID, $experiment->getId(), "=");
            $count = Factory::getEvaluationFactory()->countFilter([Factory::FILTER => $qF]);
            $ev = new Evaluation(0, date("d.m.Y - H:i"), $experiment->getDescription(), $experiment->getSystemId(), $experiment->getId(), $count + 1, 0);
            $ev = Factory::getEvaluationFactory()->save($ev);

            $event = new Event(0, "Evaluation Started: <a href='/evaluation/detail/id=" . $ev->getId() . "'>" . $ev->getName() . "</a>", date('Y-m-d H:i:s'),
                "A new evaluation of experiment '" . $experiment->getName() . "' was started.", Define::EVENT_EVALUATION, $ev->getId(), $user->getId());
            Factory::getEventFactory()->save($event);

            foreach ($allConfigurations as $configuration) {
                $evaluation->addEvaluationJob($experiment->getName(), $configuration);
            }

            $evaluation->generateJobs((empty($data['deployment'])) ? '' : $data['deployment'], $data, $ev);
            $this->view->internalRedirect('evaluation', 'detail', ['id' => $ev->getId()]);
        } else {
            throw new Exception("No experiment id provided!");
        }
    }

    public $create_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function create() {
        if (!empty($this->get['projectId'])) {
            $project = Factory::getProjectFactory()->get($this->get['projectId']);
            if ($project == null) {
                throw new Exception("Invalid project ID " . $this->get['projectId']);
            }

            // Check if the user has enough privileges to access the project
            $auth = Auth_Library::getInstance();
            $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
            $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
            $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
            if ($check == null && $project->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to create an experiment for this project!");
            }

            $system = new System($project->getSystemId());
            $settings = Settings_Library::getInstance($project->getSystemId());
            $copyData = [
                "runs" => 1,
                "run-distribution" => "alter",
                "deployment" => (!empty($settings->get('defaultValues', 'environment'))) ? $settings->get('defaultValues', 'environment') : "",
                "elements" => [],
                "description" => "",
                "phase_warmUp" => (!empty($settings->get('defaultValues', 'phase_warmUp'))) ? $settings->get('defaultValues', 'phase_warmUp')->getValue() : "",
            ];
            if (!empty($this->get['copyExperimentId'])) {
                $experiment = Factory::getExperimentFactory()->get($this->get['copyExperimentId']);
                if ($experiment === null) {
                    throw new Exception("Invalid experiment ID!");
                }
                $copyData = json_decode($experiment->getPostData(), true);
            }

            $builder = new Builder_Library($system);
            $settings = Settings_Library::getInstance($project->getSystemId());
            $this->view->assign('project', $project);
            $this->view->assign('system', $system->getModel());
            $arr = $builder->buildExperiment($copyData);
            $this->view->assign('content', $arr['content']);
            $this->view->includeInlineJS($arr['js']);
            $this->view->assign('deployments', $settings->get('environments'));
            $this->view->assign('copyData', $copyData);
        } else if (!empty($this->post['projectId'])) {
            $project = Factory::getProjectFactory()->get($this->post['projectId']);
            if ($project == null) {
                throw new Exception("Invalid project ID " . $this->post['projectId']);
            }
            $experimentJson = json_encode($this->post);

            // Check if the user has enough privileges to access the project
            $auth = Auth_Library::getInstance();
            $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
            $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
            $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
            if ($check == null && $project->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to create an experiment for this project!");
            }

            $name = htmlentities($this->post['name']);
            $description = htmlentities($this->post['description']);

            $ph = [];
            $ph['phase_prepare'] = isset($this->post['phase_prepare']) ? boolval($this->post['phase_prepare']) : true;
            $ph['phase_warmUp'] = isset($this->post['phase_warmUp']) ? boolval($this->post['phase_warmUp']) : true;
            $ph['phase_execute'] = isset($this->post['phase_execute']) ? boolval($this->post['phase_execute']) : true;
            $ph['phase_analyze'] = isset($this->post['phase_analyze']) ? boolval($this->post['phase_analyze']) : true;
            $ph['phase_clean'] = isset($this->post['phase_clean']) ? boolval($this->post['phase_clean']) : true;
            $phases = Util::calcPhasesBitMask($ph['phase_prepare'], $ph['phase_warmUp'], $ph['phase_execute'], $ph['phase_analyze'], $ph['phase_clean']);

            $userId = Auth_Library::getInstance()->getUserID();

            $qF = new QueryFilter(Experiment::PROJECT_ID, $project->getId(), "=");
            $count = Factory::getExperimentFactory()->countFilter([Factory::FILTER => $qF]);
            $experiment = new Experiment(0, $name, $userId, $description, $project->getSystemId(), $phases, 0, date('Y-m-d H:i:s'), trim($this->post['projectId']), $experimentJson, $count + 1, 0, "");
            $experiment = Factory::getExperimentFactory()->save($experiment);

            $user = Factory::getUserFactory()->get(Auth_Library::getInstance()->getUserID());
            $event = new Event(0, "New Experiment: <a href='/experiment/detail/id=" . $experiment->getId() . "'>$name</a>", date('Y-m-d H:i:s'), "A new experiment named '$name' was created for project '" . $project->getName() . "' by " . $user->getFirstname() . " " . $user->getLastname() . ".", Define::EVENT_EXPERIMENT, $experiment->getId(), $user->getId());
            Factory::getEventFactory()->save($event);

            $this->view->internalRedirect('experiment', 'detail', ['id' => $experiment->getId()]);

        } else {
            throw new Exception("No system id provided!");
        }
    }
}