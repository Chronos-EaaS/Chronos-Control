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
use DBA\QueryFilter;

class Builder_Controller extends Controller {
    public $build_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function build() {
        if (!empty($this->get['id'])) {
            $system = new System($this->get['id']);
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
        global $FACTORIES;

        if (!empty($this->get['experimentId'])) {
            $experiment = $FACTORIES::getExperimentFactory()->get($this->get['experimentId']);
            if ($experiment == null) {
                throw new Exception("Invalid experiment ID " . $this->get['experimentId']);
            }

            // TODO: the whole building part maybe later can be put into the evaluation library library

            // load system
            $system = $FACTORIES::getSystemFactory()->get($FACTORIES::getProjectFactory()->get($experiment->getProjectId())->getSystemId());
            $sys = new System($system->getId());

            $user = Auth_Library::getInstance()->getUser();
            $data = json_decode($experiment->getPostData(), true);
            $evaluation = new Evaluation_Library($experiment);

            $allElements = Util::getDefaultParameterElements();
            $sys->getParameterElements($allElements);

            $singleElements = [];
            $multiElements = [];
            foreach ($data['elements'] as $element) {
                $e = array("identifier" => $element, "parameter" => $data[$element . "-parameter"]);
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

            $allCDL = array(new CDL_Library($system->getId()));
            foreach ($multiElements as $element) {
                /** @var $elem Element */
                $elem = $element['obj'];
                $elem->process($data, $element['parameter'], $allCDL);
            }
  
            foreach ($singleElements as $element) {
                /** @var $elem Element */
                $elem = $element['obj'];
                $elem->process($data, $element['parameter'], $allCDL);
            }

            $qF = new QueryFilter(Evaluation::EXPERIMENT_ID, $experiment->getId(), "=");
            $count = $FACTORIES::getEvaluationFactory()->countFilter(array($FACTORIES::FILTER => $qF));
            $ev = new Evaluation(0, $experiment->getName(), $experiment->getDescription(), $experiment->getSystemId(), $experiment->getId(), $count + 1, 0);
            $ev = $FACTORIES::getEvaluationFactory()->save($ev);

            $event = new Event(0, "Evaluation Started: <a href='/evaluation/detail/id=" . $ev->getId() . "'>" . $ev->getName() . "</a>", date('Y-m-d H:i:s'),
                "A new evaluation of experiment '" . $experiment->getName() . "' was started.", Define::EVENT_EVALUATION, $ev->getId(), $user->getId());
            $FACTORIES::getEventFactory()->save($event);

            foreach ($allCDL as $cdl) {
                $evaluation->addEvaluationJob($experiment->getName(), $cdl);
            }

            $evaluation->generateJobs((empty($data['environment'])) ? '' : $data['environment'], $data, $ev);
            $this->view->internalRedirect('evaluation', 'detail', array('id' => $ev->getId()));

        } else {
            throw new Exception("No experiment id provided!");
        }
    }

    public $create_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function create() {
        global $FACTORIES;

        if (!empty($this->get['projectId'])) {
            $project = $FACTORIES::getProjectFactory()->get($this->get['projectId']);
            if ($project == null) {
                throw new Exception("Invalid project ID " . $this->get['projectId']);
            }
            $system = new System($project->getSystemId());
            $builder = new Builder_Library($system);
            $settings = Settings_Library::getInstance($project->getSystemId());
            $this->view->assign('project', $project);
            $this->view->assign('system', $system->getModel());
            $this->view->assign('content', $builder->buildExperiment());
            $this->view->assign('deployments', $settings->get('environments'));
        } else if (!empty($this->post['projectId'])) {
            $project = $FACTORIES::getProjectFactory()->get($this->post['projectId']);
            if ($project == null) {
                throw new Exception("Invalid project ID " . $this->post['projectId']);
            }
            $experimentJson = json_encode($this->post);

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
            $count = $FACTORIES::getExperimentFactory()->countFilter(array($FACTORIES::FILTER => $qF));
            $experiment = new Experiment(0, $name, $userId, $description, 2, $project->getSystemId(), $phases, 0, date('Y-m-d H:i:s'), trim($this->post['projectId']), $experimentJson, $count + 1);
            $experiment = $FACTORIES::getExperimentFactory()->save($experiment);

            $project = $FACTORIES::getProjectFactory()->get($experiment->getProjectId());

            $user = $FACTORIES::getUserFactory()->get(Auth_Library::getInstance()->getUserID());
            $event = new Event(0, "New Experiment: <a href='/experiment/detail/id=" . $experiment->getId() . "'>$name</a>", date('Y-m-d H:i:s'), "A new experiment named '$name' was created for project '" . $project->getName() . "' by " . $user->getFirstname() . " " . $user->getLastname() . ".", Define::EVENT_EXPERIMENT, $experiment->getId(), $user->getId());
            $FACTORIES::getEventFactory()->save($event);

            $this->view->internalRedirect('experiment', 'detail', array('id' => $experiment->getId()));

        } else {
            throw new Exception("No system id provided!");
        }
    }
}