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

class User_Controller extends Controller {

    public $login_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function login() {
        $auth = Auth_Library::getInstance();
        if (!empty($this->post['username']) && !empty($this->post['password'])) {
            $username = $this->post['username'];
            $password = $this->post['password'];
            $remember = null;
            if (isset($this->post['remember'])) {
                $remember = $this->post['remember'];
            }
            try {
                $auth->login($username, $password, $remember);
            } catch (Exception $e) {
                $this->view->assign('error', $e->getMessage());
            }
        } else {
            // If the form was submitted, login is set. If login is not set, the user only accessed the page. Don't show him a error message.
            if (!empty($this->post['login'])) {
                $this->view->assign('error', 'Please enter a username and the corresponding password!');
            }
        }

        if ($auth->isLoggedIn()) {
            $this->view->redirect('/home/main');
        }
    }


    public $logout_access = Auth_Library::A_LOGGEDIN;

    public function logout() {
        $auth = Auth_Library::getInstance();
        $auth->logout();
        $this->view->redirect('/home/main');
    }


    public $edit_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function edit() {
        global $FACTORIES;

        $auth = Auth_Library::getInstance();
        if (!empty($this->post['group'])) {
            if ($auth->isSwitchedUser() && $this->get['id'] == $auth->getRealUser()->getId()) {
                throw new Exception('You shall not edit your own profile in switch user mode!');
            }
            if ($this->get['id'] == $auth->getUserID() || $auth->isAdmin()) {
                $error = '';
                $group = $this->post['group'];

                $user = $FACTORIES::getUserFactory()->get($this->post['id']);
                if (!$user) {
                    //Requested User does not exist
                    throw new Exception('User does not exist!');
                }

                // Identity
                if ($group === "identity") {
                    $gender = intval(trim($this->post['gender']));
                    $lastname = trim($this->post['lastname']);
                    $firstname = trim($this->post['firstname']);
                    $email = trim($this->post['email']);

                    if (!Util::checkGender($gender)) throw new Exception('Invalid value for the attribute Gender');
                    if (!Util::checkName($lastname)) throw new Exception('Invalid value for the attribute Name');
                    if (!Util::checkName($firstname)) throw new Exception('Invalid value for the attribute first name');
                    if (!Util::checkEMail($email)) throw new Exception('Invalid value for the attribute E-Mail');

                    if (!empty($userData['username'])) {
                        $username = trim($userData['username']);
                        if (!Util::checkUsername($username)) throw new Exception('Invalid value for the attribute Username');
                    }

                    if ($auth->isAdmin() && $user->getId() != $auth->getUserID()) {
                        // Only admins can change username (but not by them self)
                        if (!empty($this->post['username']) && $username != $user->getUsername()) {
                            $user->setUsername($username);
                        }
                    }
                    if (!empty($this->post['lastname']) && !empty($this->post['firstname']) && !empty($this->post['gender']) && !empty($this->post['email'])) {
                        $user->setLastname($lastname);
                        $user->setFirstname($firstname);
                        $user->setGender($gender);
                        $user->setEmail($email);
                    } else {
                        $error = "Please fill out all fields";
                    }

                    if (empty($error)) {
                        $user->setLastEdit(date('Y-m-d H:i:s'));
                        $FACTORIES::getUserFactory()->update($user);
                    }
                } // Admin Options
                else if ($group === "admin") {
                    if ($auth->isAdmin() && $user->getId() != $auth->getUserID()) {
                        if (!empty($this->post['alive'])) {
                            $user->setAlive(1);
                        } else {
                            $user->setAlive(0);
                        }
                        if (!empty($this->post['activated'])) {
                            $user->setActivated(1);
                        } else {
                            $user->setActivated(0);
                        }
                        if ($auth->isSuperAdmin()) {
                            if (!empty($this->post['admin'])) {
                                $user->setRole($this->post['admin']);
                            } else {
                                $user->setRole(0);
                            }
                        }
                        if (empty($error)) {
                            $user->setLastEdit(date('Y-m-d H:i:s'));
                            $FACTORIES::getUserFactory()->update($user);
                        }
                    } else {
                        throw new Exception('Only admins can change admin settings. ' . $user->getId());
                    }
                } // Password
                else if ($group === "password") {
                    if (!empty($this->post['old-password']) || $auth->isAdmin() && $user->getId() != $auth->getUserID()) {
                        if (!empty($this->post['new-password']) && !empty($this->post['new-password-repeat'])) {
                            // admins can override password
                            if (($auth->isAdmin() && $user->getId() != $auth->getUserID()) || password_verify($this->post['old-password'], $user->getPassword())) {
                                if ($this->post['new-password'] === $this->post['new-password-repeat']) {
                                    if (!Util::checkPassword($this->post['new-password'])) throw new Exception('Invalid value for the attribute password');
                                    $user->setPassword(password_hash($this->post['new-password'], PASSWORD_BCRYPT));
                                    $user->setLastEdit(date('Y-m-d H:i:s'));
                                    $FACTORIES::getUserFactory()->update($user);
                                } else {
                                    $error = 'password and password repeat are not equal';
                                }
                            } else {
                                $error = 'old password is wrong';
                            }
                        } else {
                            $error = 'Missing password.';
                        }
                    } else {
                        throw new Exception('Only admins can override password.');
                    }
                }

            } else {
                // Non Admin try to edit another profile then his own
                throw new Exception('You are not allowed to edit a user except your self.');
            }
            if (!empty($error)) {
                throw new Exception($error);
            }
        }

        if (empty($this->get['id']) || $this->get['id'] == $auth->getUserID()) {
            //Edit your own Profile
            $this->view->assign('user', $auth->getUser());
        } else if ($auth->isAdmin()) {
            //Admin can edit each profile
            $user = $FACTORIES::getUserFactory()->get($this->get['id']);
            if ($user) {
                $this->view->assign('user', $user);
            } else {
                //Requested User does not exist
                throw new Exception('User does not exist');
            }
        } else {
            //Non Admin try to edit another profile then his own
            throw new Exception('You are not allowed to edit a user except your self.');
        }
    }
}
