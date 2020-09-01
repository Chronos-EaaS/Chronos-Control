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
use DBA\Factory;
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
        $auth = Auth_Library::getInstance();
        if (!empty($this->get['user']) && $this->get['user'] == 'all') {
            $userId = 0;
            $this->view->assign('showAllUser', true);
        } else {
            $userId = $auth->getUserID();
            $this->view->assign('showAllUser', false);
        }

        if (!empty($this->get['archived']) && $this->get['archived'] == 'true') {
            $archived = new QueryFilter(Project::IS_ARCHIVED, 1, "=");
            $this->view->assign('showArchivedProjects', true);
        } else {
            $archived = new QueryFilter(Project::IS_ARCHIVED, 0, "=");
            $this->view->assign('showArchivedProjects', false);
        }

        $filters = [$archived];
        if ($userId > 0) {
            $filters[] = new QueryFilter(Project::USER_ID, $userId, "=", Factory::getProjectUserFactory());
        }

        if ($auth->isAdmin() && $userId == 0) {
            $projects = Factory::getProjectFactory()->filter([Factory::FILTER => $filters]);
        } else {
            $jF = new JoinFilter(Factory::getProjectUserFactory(), ProjectUser::PROJECT_ID, Project::PROJECT_ID);
            $projects = Factory::getProjectFactory()->filter([Factory::FILTER => $filters, Factory::JOIN => $jF])[Factory::getProjectFactory()->getModelName()];
        }

        $sets = [];
        foreach ($projects as $project) {
            $set = new DataSet($project->getKeyValueDict());
            if ($project->getSystemId() > 0) {
                $system = Factory::getSystemFactory()->get($project->getSystemId());
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
        if (!empty($this->post['name'])) {
            $name = htmlentities($this->post['name'], ENT_QUOTES, "UTF-8");
            $description = htmlentities($this->post['description'], ENT_QUOTES, "UTF-8");
            $system = intval($this->post['system']);

            // Admins can create projects on behalf of other users
            $auth = Auth_Library::getInstance();
            if ($auth->isAdmin()) {
                $owner = intval($this->post['owner']);
            } else {
                $owner = intval($auth->getUserID());
            }

            // Check if user has enough privileges to create a project using this system
            $sys = new System($system);
            if ($sys->getModel()->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to use this system!");
            }

            $project = new Project(0, $name, $description, $owner, $system, 0, "", 0);
            $project = Factory::getProjectFactory()->save($project);

            $projectUser = new ProjectUser(0, $owner, $project->getId());
            Factory::getProjectUserFactory()->save($projectUser);

            $event = new Event(0, "New Project: <a href='/project/detail/id=" . $project->getId() . "'>$name</a>", date('Y-m-d H:i:s'),
                "A new project named $name was created, using the system " . Util::getSystemName($project->getSystemId()) . ".",
                Define::EVENT_PROJECT, $project->getId(), $project->getUserId());
            Factory::getEventFactory()->save($event);

            $this->view->redirect('/project/overview');
        } else {
            // Only admins can see all systems
            $auth = Auth_Library::getInstance();
            if ($auth->isAdmin()) {
                $owner = new QueryFilter(\DBA\System::USER_ID, 0, "<>");
            } else {
                $owner = new QueryFilter(\DBA\System::USER_ID, $auth->getUserID(), "=");
            }
            $qF = new QueryFilter(\DBA\System::IS_ARCHIVED, 0, "=");

            $systems = Factory::getSystemFactory()->filter([Factory::FILTER => [$qF, $owner]]);
            $this->view->assign('systems', $systems);


            $users = Factory::getUserFactory()->filter([]);
            $this->view->assign('users', $users);
        }
    }


    public $detail_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function detail() {
        $auth = Auth_Library::getInstance();

        if (!empty($this->get['id'])) {
            $project = Factory::getProjectFactory()->get($this->get['id']);
            $this->view->assign('project', $project);

            // Check if the user has enough privileges to access this project
            $qF1 = new QueryFilter(ProjectUser::USER_ID, $auth->getUserID(), "=");
            $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
            $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
            if ($check == null && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to view this project!");
            }

            // remove member
            if (isset($this->get['remove']) && ($project->getUserId() == $auth->getUserID() || $auth->isAdmin())) {
                $user = Factory::getUserFactory()->get($this->get['remove']);
                if ($user != null) {
                    $qF1 = new QueryFilter(ProjectUser::USER_ID, $user->getId(), "=");
                    $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                    Factory::getProjectUserFactory()->massDeletion([Factory::FILTER => [$qF1, $qF2]]);
                }
            } // add member
            else if (isset($this->post['member']) && ($project->getUserId() == $auth->getUserID() || $auth->isAdmin())) {
                $user = Factory::getUserFactory()->get($this->post['member']);
                if ($user != null) {
                    $qF1 = new QueryFilter(ProjectUser::USER_ID, $user->getId(), "=");
                    $qF2 = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=");
                    $check = Factory::getProjectUserFactory()->filter([Factory::FILTER => [$qF1, $qF2]], true);
                    if ($check == null) {
                        $projectUser = new ProjectUser(0, $user->getId(), $project->getId());
                        Factory::getProjectUserFactory()->save($projectUser);
                    }
                }
            } // archive project
            else if (!empty($this->get['archive']) && $this->get['archive'] == true && ($project->getUserId() == $auth->getUserID() || $auth->isAdmin())) {
                $project->setIsArchived(1);
                Factory::getProjectFactory()->update($project);
            } // un-archive project
            else if (!empty($this->get['unarchive']) && $this->get['unarchive'] == true && ($project->getUserId() == $auth->getUserID() || $auth->isAdmin())) {
                $project->setIsArchived(0);
                Factory::getProjectFactory()->update($project);
            } else if (!empty($this->post['archiveExperiment']) && ($project->getUserId() == $auth->getUserID() || $auth->isAdmin())) {
                $experiment = Factory::getExperimentFactory()->get($this->post['experimentId']);
                if ($experiment == null) {
                    throw new ProcessException("Invalid Experiment!");
                } else if ($experiment->getProjectId() != $project->getId()) {
                    throw new ProcessException("Experiment does not belong to this project!");
                } else if ($experiment->getIsArchived() == 1) {
                    throw new ProcessException("Experiment is already archived!");
                }
                $experiment->setIsArchived(1);
                Factory::getExperimentFactory()->update($experiment);
            }

            $system = Factory::getSystemFactory()->get($project->getSystemId());
            $this->view->assign('system', $system);

            $qF1 = new QueryFilter(Experiment::PROJECT_ID, $project->getId(), "=");
            $qF2 = new QueryFilter(Experiment::IS_ARCHIVED, 0, "=");
            if (isset($this->get['show']) && $this->get['show'] == 'archived') {
                $qF2 = new QueryFilter(Experiment::IS_ARCHIVED, 1, "=");
            }
            $experiments = Factory::getExperimentFactory()->filter([Factory::FILTER => [$qF1, $qF2]]);

            $qF = new ContainFilter(Evaluation::EXPERIMENT_ID, Util::arrayOfIds($experiments));
            $evaluations = Factory::getEvaluationFactory()->filter([Factory::FILTER => $qF]);
            $running = [];
            foreach ($evaluations as $evaluation) {
                $qF1 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                $qF2 = new ContainFilter(Job::STATUS, [Define::JOB_STATUS_FAILED, Define::JOB_STATUS_RUNNING, Define::JOB_STATUS_SCHEDULED]);
                $count = Factory::getJobFactory()->countFilter([Factory::FILTER => [$qF1, $qF2]]);
                if ($count > 0) {
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
            $this->view->assign('loginUser', $auth->getUserID());

            $jF = new JoinFilter(Factory::getProjectUserFactory(), User::USER_ID, ProjectUser::USER_ID);
            $qF = new QueryFilter(ProjectUser::PROJECT_ID, $project->getId(), "=", Factory::getProjectUserFactory());
            $members = Factory::getUserFactory()->filter([Factory::FILTER => $qF, Factory::JOIN => $jF])[Factory::getUserFactory()->getModelName()];
            $this->view->assign('members', $members);
            $allUsers = Factory::getUserFactory()->filter([]);
            foreach ($allUsers as $key => $user) {
                if (in_array($user, Util::arrayOfIds($allUsers)) || $user->getId() == $project->getUserId()) {
                    unset($allUsers[$key]);
                }
            }

            $this->view->assign('allUsers', $allUsers);

            $events = Util::eventFilter(['project' => $project]);
            $this->view->assign('events', $events);
        } else {
            throw new Exception("No project id provided!");
        }
    }
}