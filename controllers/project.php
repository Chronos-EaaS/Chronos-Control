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
use DBA\Event;
use DBA\Experiment;
use DBA\Job;
use DBA\JoinFilter;
use DBA\Project;
use DBA\ProjectUser;
use DBA\QueryFilter;
use DBA\User;

class Project_Controller extends Controller {
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

        if ($userId > 0) {
            $qF = new QueryFilter(Project::USER_ID, $userId, "=");
            $projects = $FACTORIES::getProjectFactory()->filter(array($FACTORIES::FILTER => $qF));

            $jF = new JoinFilter($FACTORIES::getProjectUserFactory(), ProjectUser::PROJECT_ID, Project::PROJECT_ID);
            $qF = new QueryFilter(ProjectUser::USER_ID, $userId, "=", $FACTORIES::getProjectUserFactory());
            $projects = array_merge($projects, $FACTORIES::getProjectFactory()->filter(array($FACTORIES::FILTER => $qF, $FACTORIES::JOIN => $jF))[$FACTORIES::getProjectFactory()->getModelName()]);
        } else {
            $projects = $FACTORIES::getProjectFactory()->filter(array());
        }

        $sets = [];
        foreach ($projects as $project) {
            $set = new DataSet($project->getKeyValueDict());
            if ($project->getSystemId() > 0) {
                $system = $FACTORIES::getSystemFactory()->get($project->getSystemId());
                $set->addValue('systemName', $system->getName());
            }
            $sets[] = $set;
        }
        $this->view->assign('projects', $sets);
    }

    public $create_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function create() {
        global $FACTORIES;

        if (!empty($this->post['name'])) {
            $name = htmlentities($this->post['name'], ENT_QUOTES, "UTF-8");
            $description = htmlentities($this->post['description'], ENT_QUOTES, "UTF-8");
            $owner = intval($this->post['owner']);
            $system = intval($this->post['system']);

            //TODO: maybe do some checks here

            $project = new Project(0, $name, $description, $owner, $system, 0, "", 0);
            $project = $FACTORIES::getProjectFactory()->save($project);

            $event = new Event(0, "New Project: <a href='/project/detail/id=" . $project->getId() . "'>$name</a>", date('Y-m-d H:i:s'),
                "A new project named $name was created, using the system " . Util::getSystemName($project->getSystemId()) . ".",
                Define::EVENT_PROJECT, $project->getId(), $project->getUserId());
            $FACTORIES::getEventFactory()->save($event);

            $this->view->redirect('/project/overview');
        } else {
            $systems = $FACTORIES::getSystemFactory()->filter(array());
            $this->view->assign('systems', $systems);
            $users = $FACTORIES::getUserFactory()->filter(array());
            $this->view->assign('users', $users);
        }
    }


    public $detail_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function detail() {
        global $FACTORIES;

        if (!empty($this->get['id'])) {
            $project = $FACTORIES::getProjectFactory()->get($this->get['id']);
            $this->view->assign('project', $project);

            $system = $FACTORIES::getSystemFactory()->get($project->getSystemId());
            $this->view->assign('system', $system);

            $qF = new QueryFilter(Experiment::PROJECT_ID, $project->getId(), "=");
            $experiments = $FACTORIES::getExperimentFactory()->filter(array($FACTORIES::FILTER => $qF));

            $qF = new ContainFilter(Evaluation::EXPERIMENT_ID, Util::arrayOfIds($experiments));
            $evaluations = $FACTORIES::getEvaluationFactory()->filter(array($FACTORIES::FILTER => $qF));
            $running = [];
            foreach ($evaluations as $evaluation) {
                $qF = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $jobs = $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $qF));
                $allDone = true;
                foreach ($jobs as $job) {
                    if ($job->getStatus() != Define::JOB_STATUS_ABORTED && $job->getStatus() != Define::JOB_STATUS_FINISHED) {
                        $allDone = false;
                        break;
                    }
                }
                if (!$allDone) {
                    $running[] = $evaluation;
                }
            }

            $this->view->assign('experiments', $experiments);
            $ex = new DataSet();
            foreach ($experiments as $experiment) {
                $ex->addValue($experiment->getId(), $experiment);
            }
            $this->view->assign('experiments-ds', $ex);
            $this->view->assign('evaluations', $running);

            $auth = Auth_Library::getInstance();
            $this->view->assign('loginUser', $auth->getUserID());

            $this->view->assign('allUsers', $FACTORIES::getUserFactory()->filter(array()));
            $qF = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=", $FACTORIES::getProjectUserFactory());
            $jF = new JoinFilter($FACTORIES::getProjectUserFactory(), User::USER_ID, ProjectUser::USER_ID);
            $this->view->assign('members', $FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF, $FACTORIES::JOIN => $jF)));

            $events = Util::eventFilter(array('project' => $project));
            $this->view->assign('events', $events);
        } else {
            throw new Exception("No project id provided!");
        }
    }
}