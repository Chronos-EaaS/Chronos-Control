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
use DBA\Factory;
use DBA\Project;
use DBA\QueryFilter;
use DBA\User;
use DBA\Experiment;

class Admin_Controller extends Controller {

    public $main_access = Auth_Library::A_ADMIN;

    /**
     * @throws Exception
     */
    public function main() {
        $settings = Settings_Library::getInstance(0);
        if (!empty($this->post['group'])) {
            $group = $this->post['group'];

            // MAAS
            if ($group === "maas") {
                $error = '';
                if (!empty($this->post['maas_key'])) {
                    list($data['maas_consumer_key'], $data['maas_key'], $data['maas_secret']) = explode(':', $this->post['maas_key']);
                    try {
                        $settings->set("maas", "key", $data['maas_key']);
                        $settings->set("maas", "secret", $data['maas_secret']);
                        $settings->set("maas", "consumer_key", $data['maas_consumer_key']);
                    } catch (Exception $e) {
                        $error = $e->getMessage();
                    }
                } else {
                    $error = "Please fill out all fields";
                }
            } else {
                throw new Exception('Unknown group to edit');
            }

            $this->view->assign('error', $error);
            if ($error == '') {
                $this->view->assign('success', 'Successful');
            }
        }

        if (!empty($this->post['settings'])) {
            $settings = Settings_Library::getInstance(0);
            $current = $settings->get();
            foreach ($current as $s) {
                $key = $s->getSection() . "###" . $s->getItem();
                if (!empty($this->post[$key])) {
                    $newValue = $this->post[$key];
                    $settings->set($s->getSection(), $s->getItem(), $newValue);
                }
            }
        }

        if (!empty($this->post['branch'])) {
            $branch = $this->post['branch'];
            if (!in_array($branch, VCS_Library::getBranches(SERVER_ROOT, Settings_Library::getInstance(0)->get('vcs', 'repoType')->getValue()))) {
                throw new Exception("Unknown branch!");
            }
            Settings_Library::getInstance(0)->set('vcs', 'repoBranch', $branch);
            $this->view->internalRedirect('admin', "update", []);
        }

        // Add users
        $users = Factory::getUserFactory()->filter([]);
        $this->view->assign('users', $users);

        // Load branches
        $branches = VCS_Library::getBranches(SERVER_ROOT, Settings_Library::getInstance(0)->get('vcs', 'repoType')->getValue());
        $this->view->assign('branches', $branches);
        $this->view->assign('repository_branch', Settings_Library::getInstance(0)->get('vcs', 'repoBranch')->getValue());
        $this->view->assign('current_branch', VCS_Library::getCurrentBranch(SERVER_ROOT, Settings_Library::getInstance(0)->get('vcs', 'repoType')->getValue()));

        // Load Settings
        $allSettings = Settings_Library::getInstance(0)->get();
        $groups = [];
        foreach ($allSettings as $setting) {
            if (!isset($groups[$setting->getSection()])) {
                $groups[$setting->getSection()] = [];
            }
            $groups[$setting->getSection()][] = $setting;
        }
        $this->view->assign('settings', $groups);

        // Add systems
        $systems = Systems_Library::getSystems();
        $this->view->assign('systems', $systems);

        // Add MaaS settings
        $maas = $settings->get("maas");
        if ($maas == null) {
            $maas = [
                'key' => '',
                'secret' => '',
                'consumer_key' => ''
            ];
        }
        $this->view->assign('maas', $maas);

        // Mount status
        $mount = new Mount_Library();
        try {
            $mountStatus = $mount->checkIfDataDirectoryIsMounted();
            $this->view->assign('mountStatus', $mountStatus);
        } catch (Exception $exception) {
            // Error while determining mount status
            $this->view->assign('mountStatusError', $exception->getMessage());
        }

    }

    public $newUser_access = Auth_Library::A_ADMIN;

    /**
     * @throws Exception
     */
    public function newUser() {
        if (!empty($this->post['username'])) {
            try {
                if (empty($this->post['gender'])) {
                    throw new Exception('Field Gender is mandatory!');
                }
                if (empty($this->post['username'])) {
                    throw new Exception('Field username is mandatory!');
                }
                if (empty($this->post['lastname'])) {
                    throw new Exception('Field lastname is mandatory!');
                }
                if (empty($this->post['firstname'])) {
                    throw new Exception('Field firstname is mandatory!');
                }
                if (empty($this->post['password'])) {
                    throw new Exception('Field password is mandatory!');
                }
                if (empty($this->post['password-repeat'])) {
                    throw new Exception('Field password-repeat is mandatory!');
                }
                if (empty($this->post['email'])) {
                    throw new Exception('Field email is mandatory!');
                }

                $gender = intval($this->post['gender']);
                $lastname = trim($this->post['lastname']);
                $firstname = trim($this->post['firstname']);
                $username = trim($this->post['username']);
                $password = $this->post['password'];
                $email = trim($this->post['email']);

                // Check if password and password-repeat are identical
                if ($password !== $this->post['password-repeat']) {
                    throw new Exception('Passwords are not identical!');
                }

                if (!Util::checkGender($gender)) throw new Exception('Invalid value for the attribute Gender');
                if (!Util::checkName($lastname)) throw new Exception('Invalid value for the attribute Name');
                if (!Util::checkName($firstname)) throw new Exception('Invalid value for the attribute first name');
                if (!Util::checkUsername($username)) throw new Exception('Invalid value for the attribute username or username already in use');
                if (!Util::checkPassword($password)) throw new Exception('Invalid value for the attribute Password');
                if (!Util::checkEMail($email)) throw new Exception('Invalid value for the attribute E-Mail');

                $password = password_hash($password, PASSWORD_BCRYPT);

                // New User are alive, but they have to be activated by mail (currently turned off)
                $user = new User(0, $username, $password, $email, $lastname, $firstname, $gender, 0, 1, 1, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), null);
                $user = Factory::getUserFactory()->save($user);

                $auth = Auth_Library::getInstance();
                $event = new Event(0,
                    "New User: $firstname $lastname ($username)", date('Y-m-d H:i:s'),
                    "A new user named $firstname $lastname ($username) was created by " . $auth->getUser()->getFirstname() . " " . $auth->getUser()->getLastname() . " (" . $auth->getUser()->getUsername() . ").",
                    Define::EVENT_USER, $user->getId(), $auth->getUserID());
                Factory::getEventFactory()->save($event);

                //$this->view->assign('created', true);
                $this->view->redirect('/admin/main');
            } catch (Exception $e) {
                $this->view->assign('error', $e->getMessage());
            }
        }

    }

    public $demo_access = Auth_Library::A_SUPERADMIN;

    /**
     * @throws Exception
     */
    public function demo() {
        if (isset($this->get['reset']) && $this->get['reset']) {
            Demo_Library::reset();
            Auth_Library::getInstance()->logout();
            $this->view->redirect("/");
        } else if (isset($this->get['local']) && $this->get['local']) {
            $systems = Factory::getSystemFactory()->filter([]);
            foreach ($systems as $system) {
                if (strlen($system->getVcsUrl()) > 0) {
                    continue;
                }
                exec("rm -rf " . SERVER_ROOT . "/webroot/systems/" . strtolower($system->getName()));
                Systems_Library::initSystem($system);
            }
        }
    }


    public $switchUser_access = Auth_Library::A_ADMIN;

    /**
     * @throws Exception
     */
    public function switchUser() {
        if (!empty($this->get['username'])) {
            $qF = new QueryFilter(User::USERNAME, $this->get['username'], "=");
            $user = Factory::getUserFactory()->filter([Factory::FILTER => $qF], true);

            if ($user != null) {
                $this->view->assign('username', $user->getUsername());
            } else {
                throw new Exception("User does not exist.");
            }

        } else if (!empty($this->post['username'])) {
            if (!empty($this->post['switch']) && $this->post['switch'] == "yes") {
                $qF = new QueryFilter(User::USERNAME, $this->post['username'], "=");
                $user = Factory::getUserFactory()->filter([Factory::FILTER => $qF], true);

                if ($user != null) {
                    if (!empty($this->post['changeUser'])) {
                        $changeUser = true;
                    } else {
                        $changeUser = false;
                    }
                    $auth = Auth_Library::getInstance();
                    $auth->switchUser($user->getUsername(), $changeUser);
                    $this->view->redirect('/home/main');
                } else {
                    throw new Exception("User does not exist.");
                }
            } else {
                $this->view->redirect('/admin/main');
            }
        } else {
            throw new Exception("No username provided.");
        }
    }


    public $update_access = Auth_Library::A_SUPERADMIN;

    /**
     * @throws Exception
     */
    public function update() {
        $vcs = new VCS_Library();
        $result = $vcs->updateChronos();
        $this->view->assign('result', $result);
    }


    public $mountDataDirectory_access = Auth_Library::A_SUPERADMIN;

    /**
     * @throws Exception
     */
    public function mountDataDirectory() {
        $mount = new Mount_Library();
        $result = $mount->mountDataDirectory();
        $this->view->assign('result', $result);
    }


    public $systems_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function systems() {
        $auth = Auth_Library::getInstance();
        if ($auth->isAdmin()) {
            $owner = new QueryFilter(\DBA\System::USER_ID, 0, "<>");
        } else {
            $owner = new QueryFilter(\DBA\System::USER_ID, $auth->getUserID(), "=");
        }


        if (isset($this->get['archived']) && $this->get['archived'] == true) {
            $this->view->assign('systems', Factory::getSystemFactory()->filter([Factory::FILTER => $owner]));
            $this->view->assign('showArchivedSystems', true);
        } else {
            $qF = new QueryFilter(\DBA\System::IS_ARCHIVED, 0, "=");
            $this->view->assign('systems', Factory::getSystemFactory()->filter([Factory::FILTER => [$qF, $owner]]));
            $this->view->assign('showArchivedSystems', false);
        }
    }


    public $system_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function system() {
        $this->view->includeAsset("gitgraph");

        if (!empty($this->get['id'])) {
            $system = new System($this->get['id']);
            $system = $system->getModel();

            $auth = Auth_Library::getInstance();
            if ($system->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to view this system!");
            }

            if (!empty($this->post['id'])) {
                if ($this->post['group'] == 'general') {
                    $data = $this->post;
                    $system->setName(trim($this->post['name']));
                    $system->setDescription(trim($this->post['description']));
                    // Only admins are allowed to change the owner of a system
                    if ($auth->isAdmin()) {
                        $system->setUserId(intval($data['owner']));
                    }
                    // Only admins are allowed to change VCS settings and only if the feature is enabled
                    if (ENABLE_REMOTE_REPOSITORY && $auth->isAdmin()) {
                        $system->setVcsBranch(Systems_Library::escapeCMD(trim(@$data['branch'])));
                        $system->setVcsType(trim(@$data['vcsType']));
                        $system->setVcsUser(Systems_Library::escapeCMD(trim(@$data['vcsUser'])));
                        $system->setVcsPassword(Systems_Library::escapeCMD(trim(@$data['vcsPassword'])));
                    }
                    Factory::getSystemFactory()->update($system);
                } else if ($this->post['group'] == 'defaultValues') {
                    $settings = Settings_Library::getInstance($system->getId());
                    $settings->set('defaultValues', 'phase_warmUp', $this->post['default_phase_warmUp']);
                    $settings->set('defaultValues', 'environment', $this->post['default_environment']);
                } else if ($this->post['group'] == 'settings') {
                    $settings = Settings_Library::getInstance($system->getId());
                    $current = $settings->get('general');
                    foreach ($current as $s) {
                        if (!empty($this->post[$s->getItem()])) {
                            $newValue = $this->post[$s->getItem()];
                            $settings->set('general', $s->getItem(), $newValue);
                        }
                    }
                } else if ($this->post['group'] == 'newSetting') {
                    $settings = Settings_Library::getInstance($system->getId());
                    if (!empty($this->post['settingKey']) && !empty($this->post['settingValue'])) {
                        $key = $this->post['settingKey'];
                        $value = $this->post['settingValue'];
                        if ($settings->get('general', $key) == null) {
                            $settings->set('general', $key, $value);
                        } else {
                            throw new Exception("Key already used!");
                        }
                    }
                } else if ($this->post['group'] == 'newEnvironment') {
                    $settings = Settings_Library::getInstance($system->getId());
                    if (!empty($this->post['newEnvironmentName'])) {
                        $environmentName = $this->post['newEnvironmentName'];
                        if ($settings->get('environments', $environmentName) == null) {
                            $settings->set('environments', $environmentName, "unused value");
                        } else {
                            throw new Exception("Key already used!");
                        }
                    }
                }
            } else if (!empty($this->get['delete'])) {
                $settings = Settings_Library::getInstance($system->getId());
                if (!empty($this->get['delete'])) {
                    $key = urldecode($this->get['delete']);
                    $settings->delete('general', $key);
                }
            } else if (!empty($this->get['archive']) && $this->get['archive'] == true) {
                // check if projects are still using this system
                $qF1 = new QueryFilter(Project::SYSTEM_ID, $system->getId(), "=");
                $qF2 = new QueryFilter(Project::IS_ARCHIVED, 0, "=");
                $check = Factory::getProjectFactory()->filter([Factory::FILTER => [$qF1, $qF2]]);
                if (sizeof($check) > 0) {
                    throw new ProcessException("This system is still used by at least one not archived project!");
                }
                $system->setIsArchived(1);
                Factory::getSystemFactory()->update($system);
            } else if (!empty($this->get['deleteEnvironment'])) {
                $settings = Settings_Library::getInstance($system->getId());
                if (!empty($this->get['deleteEnvironment'])) {
                    $key = urldecode($this->get['deleteEnvironment']);
                    $settings->delete('environments', $key);
                }
            } else if (!empty($this->post['newResult'])) {
                $next = Util::getNextIdForSystemResults($system->getId());
                $systemLib = new System($system->getId());
                $systemLib->createNewResults("system-" . $next);
            } else if (!empty($this->post['copyResult'])) {
                $resultId = $this->post['resultId'];
                if ($resultId == "") {
                    throw new ProcessException("No result ID defined!");
                }
                $systemLib = new System($system->getId());
                $resultAll = $systemLib->getResultsAll($resultId);
                $resultJob = $systemLib->getResultsJob($resultId);
                if ($resultAll === false || $resultJob === false) {
                    throw new ProcessException("Results ID not found!");
                }
                $next = Util::getNextIdForSystemResults($system->getId());
                $systemLib->createNewResults("system-" . $next);
                $systemLib->setResultsAll($resultAll, "system-" . $next);
                $systemLib->setResultsJob($resultJob, "system-" . $next);
            } else if (!empty($this->post['renameResult'])) {
                $resultId = $this->post['resultId'];
                $name = htmlentities($this->post["newName"], ENT_QUOTES, "UTF-8");
                if ($resultId == "") {
                    throw new ProcessException("No result ID defined!");
                }
                $systemLib = new System($system->getId());
                $systemLib->renameResults($resultId, $name);
            } else if (!empty($this->post['deleteResult'])) {
                $resultId = $this->post['resultId'];
                if ($resultId == "") {
                    throw new ProcessException("No result ID defined!");
                }
                $qF = new QueryFilter(Experiment::RESULT_ID, $resultId, "=");
                $check = Factory::getExperimentFactory()->filter([Factory::FILTER => $qF]);
                if (sizeof($check) > 0) {
                    throw new ProcessException("You cannot delete this result, as it is used in experiments (" . implode(", ", Util::arrayOfIds($check)) . ")!");
                }
                $systemLib = new System($system->getId());
                $systemLib->deleteResults($resultId);
            } else if (!empty($this->post['newWarning'])) {
                $key = $this->post['newWarningPattern'];
                if ($key != "") {
                    $system = Factory::getSystemFactory()->get($this->post['id']);
                    $logalyzer = new Logalyzer_Library();
                    $logalyzer->setSystem($system);
                    if(substr($key, 0, 1) === '/')
                        // Is regex. Php version 8 introduces starts_with()
                        $logalyzer->addKey('warning', 'regex', $key);
                    else {
                        $logalyzer->addKey('warning', 'string', $key);
                    }
                }
            } else if (!empty($this->post['newError'])) {
                $key = $this->post['newErrorPattern'];
                if ($key != "") {
                    $system = Factory::getSystemFactory()->get($this->post['id']);
                    $logalyzer = new Logalyzer_Library();
                    $logalyzer->setSystem($system);
                    if(substr($key, 0, 1) === '/')
                        $logalyzer->addKey('error', 'regex', $key);
                    else {
                        $logalyzer->addKey('error', 'string', $key);
                    }
                }
            } else if (!empty($this->get['deleteWarningPattern'])) {
                $key = $this->post['deleteWarningPattern'];
                if ($key != "") {
                    $system = Factory::getSystemFactory()->get($this->post['id']);
                    $logalyzer = new Logalyzer_Library();
                    $logalyzer->setSystem($system);
                    if(substr($key, 0, 1) === '/')
                        $logalyzer->removeKey('warning', 'regex', $key);
                    else {
                        $logalyzer->removeKey('warning', 'string', $key);
                    }
                }
            } else if (!empty($this->get['deleteErrorPattern'])) {
                $key = $this->post['deleteErrorPattern'];
                if ($key != "") {
                    $system = Factory::getSystemFactory()->get($this->post['id']);
                    $logalyzer = new Logalyzer_Library();
                    $logalyzer->setSystem($system);
                    if(substr($key, 0, 1) === '/')
                        $logalyzer->removeKey('error', 'regex', $key);
                    else {
                        $logalyzer->removeKey('error', 'string', $key);
                    }
                }
            } else if (!empty($this->get['logo']) && $this->get['logo'] == 'upload') {
                // check for error values
                switch ($_FILES['logoUpload']['error']) {
                    case UPLOAD_ERR_OK:
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        throw new Exception('No file sent!');
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        throw new Exception('Exceeded filesize limit!');
                    default:
                        throw new Exception('Unknown error!');
                }

                $allowed = ['gif', 'png', 'jpg', 'jpeg'];
                $filename = $_FILES['logoUpload']['name'];
                $ext = pathinfo(strtolower($filename), PATHINFO_EXTENSION);
                if (!in_array($ext, $allowed)) {
                    throw new Exception("Invalid file type! Only " . implode(", ", $allowed) . " extensions are allowed.");
                }
                $savePath = $folder = SERVER_ROOT . "/webroot/systems/" . $system->getId() . "/logo.png"; // it's not very nice to alwas use png, but otherwise we need to save the logo name extra
                move_uploaded_file($_FILES['logoUpload']['tmp_name'], $savePath);

                VCS_Library::commit(SERVER_ROOT . "/webroot/systems/" . $system->getId(), "Updated system logo");
            }
            $this->view->assign('system', $system);
            $systemLib = new System($system->getId());
            $results = json_decode($systemLib->getResultsAll(), true);
            if ($results === null) {
                $this->view->assign('results', []);
            } else {
                $this->view->assign('results', $results['elements']);
            }
            $this->view->assign('identifier', $systemLib->getIdentifier());
            $users = Factory::getUserFactory()->filter([]);
            $this->view->assign('users', $users);
            $settings = Settings_Library::getInstance($system->getId());
            $this->view->assign('defaultValues', $settings->getSection('defaultValues'));
            $this->view->assign('settings', $settings->get('general'));
            $this->view->assign('environments', $settings->get('environments'));
            $this->view->assign('revision', Systems_Library::getRevision($system->getId()));
            $this->view->assign('branches', Systems_Library::getBranches($system->getId()));
            $this->view->assign('history', Systems_Library::getHistory($system->getId()));
            $this->view->assign('auth', Auth_Library::getInstance());

            $logalyzer = new Logalyzer_Library();
            $logalyzer->setSystem($system);
            $errors = $logalyzer->getPatterns("error");
            $warnings = $logalyzer->getPatterns("warning");
            echo $errors;
            $this->view->assign('errorKeys', ['error']);
            $this->view->assign('warningKeys', ['warning']);
        } else {
            throw new Exception("No id provided!");
        }
    }


    public $systemExport_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function systemExport() {
        if (!empty($this->get['id'])) {
            $system = new System($this->get['id']);
            $system = $system->getModel();

            $auth = Auth_Library::getInstance();
            if ($system->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to export this system!");
            }

            $this->view->assign("system", $system);
            $this->view->setBinaryOutputMode(true);
        } else {
            throw new Exception("No id provided!");
        }
    }


    public $systemImport_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function systemImport() {
        if (!empty($this->get['id'])) {
            $s = new System($this->get['id']);
            $system = $s->getModel();

            $auth = Auth_Library::getInstance();
            if ($system->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to export this system!");
            }

            if (!isset($_FILES['inputFile']['error']) || is_array($_FILES['inputFile']['error'])) {
                throw new Exception('Invalid parameters!');
            }

            // check for error values
            switch ($_FILES['inputFile']['error']) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_NO_FILE:
                    throw new Exception('No file sent!');
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new Exception('Exceeded filesize limit!');
                default:
                    throw new Exception('Unknown error!');
            }

            $filename = $_FILES['inputFile']['name'];
            if ($filename == "parameters.json") {
                $s->setParameters(file_get_contents($_FILES['inputFile']['tmp_name']));
            } else if ($filename == "resultConfigurations.json") {
                $s->setResultsAll(file_get_contents($_FILES['inputFile']['tmp_name']));
            } else {
                throw new Exception("This only supports importing parameters.json, results.json and resultsJob.json!");
            }

            $this->view->assign("system", $system);
        } else {
            throw new Exception("No id provided!");
        }
    }


    public $createSystem_access = Auth_Library::A_ADMIN;

    /**
     * @throws Exception
     */
    public function createSystem() {
        $auth = Auth_Library::getInstance();
        if (!empty($this->post['name'])) {
            $name = trim($this->post['name']);
            $description = trim($this->post['description']);
            $owner = intval(trim($this->post['owner']));
            if (ENABLE_REMOTE_REPOSITORY && $auth->isAdmin()) {
                $repository = Systems_Library::escapeCMD($this->post['repository']);
                $branch = Systems_Library::escapeCMD($this->post['branch']);
                $vcsType = trim($this->post['vcsType']);
                $vcsUser = Systems_Library::escapeCMD(trim($this->post['vcsUser']));
                $vcsPassword = Systems_Library::escapeCMD(trim($this->post['vcsPassword']));
            } else {
                $repository = "";
                $branch = "master";
                $vcsType = "git";
                $vcsUser = "";
                $vcsPassword = "";
            }
            $system = new \DBA\System(0, $name, $description, $owner, $repository, $branch, $vcsType, $vcsUser, $vcsPassword, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), 0);
            $system = Factory::getSystemFactory()->save($system);

            if (strlen($system->getVcsUrl()) > 0) {
                Systems_Library::cloneRepository($system->getId());
            } else {
                Systems_Library::initSystem($system);
            }
            $this->view->internalRedirect('admin', 'system', ['id' => $system->getId()]);
        } else {
            $users = Factory::getUserFactory()->filter([]);
            $this->view->assign('users', $users);
        }
    }


    public $systemUpdate_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function systemUpdate() {
        if (!empty($this->get['id'])) {
            $system = Factory::getSystemFactory()->get($this->get['id']);

            if ($system == null) {
                throw new Exception('Unknown system id: ' . $this->get['id']);
            }

            // Check if the user has enough privileges to update this system
            $auth = Auth_Library::getInstance();
            if ($system->getUserId() != $auth->getUserID() && !$auth->isAdmin()) {
                throw new Exception("Not enough privileges to update this system!");
            }

            $result = Systems_Library::update($system->getId());
            $this->view->assign('result', $result);
            $this->view->assign('systemID', $system->getId());
            $this->view->assign('system', $system);
        } else {
            throw new Exception('No system id provided!');
        }
    }
}