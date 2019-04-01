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

use DBA\QueryFilter;
use DBA\Session;
use DBA\User;

if (version_compare(phpversion(), '7', '<')) {
    require_once("include/RandomCompat/lib/random.php");
}


class Auth_Library {

    const A_PUBLIC = 1;
    const A_LOGGEDIN = 2;
    const A_ADMIN = 3;
    const A_SUPERADMIN = 4;

    /**
     * Holds instance of this class for singleton pattern.
     */
    static private $instance = null;


    /**
     * Singleton
     */
    static public function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }


    /**
     * The constructor function
     * We do just the session start
     * It is necessary to start the session before actually storing any value
     * to the super global $_SESSION variable
     */
    public function __construct() {
        // server should keep session data for AT LEAST
        ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
        // each client should remember their session id for EXACTLY
        session_set_cookie_params(SESSION_TIMEOUT);
        // start session
        session_start();
    }


    /**
     * Checks whether the user is authenticated
     * @throws Exception
     */
    public function isLoggedIn() {
        global $FACTORIES;

        if (empty($_SESSION['login'])) {
            if (!empty($_COOKIE['rememberMe'])) {
                // First: trigger delete of expired sessions
                $qF = new QueryFilter(Session::EXPIRES, date('Y-m-d H:i:s'), "<");
                $FACTORIES::getSessionFactory()->massDeletion(array($FACTORIES::FILTER => $qF));

                list($selector, $authenticator) = explode(':', $_COOKIE['rememberMe']);
                $qF = new QueryFilter(Session::SELECTOR, $selector, "=");
                $session = $FACTORIES::getSessionFactory()->filter(array($FACTORIES::FILTER => $qF), true);

                if ($session === null) {
                    return false;
                }

                if (password_verify(base64_decode($authenticator), $session->getToken())) {
                    $user = $FACTORIES::getUserFactory()->get($session->getUserId());
                    // Check if user is alive and (if required) activated
                    if ($user != null && $this->checkIfUserAlive($user)) {
                        $_SESSION['login'] = $user->getUsername();
                        // Renew session
                        $this->renewRememberMe($selector, base64_decode($authenticator));
                        return true;
                    } // there is no else case (checkIfUserAlive throws an exception if the user is not active).
                } else {
                    return false;
                }
            } else {
                return false;
            }
            return false;
        } else {
            return true;
        }
    }


    /**
     * Returns the User-Data of the loggedin-User. In switch user mode it returns the data
     * of the switched user. If not authenticated, it throws a exception.
     * @throws Exception
     */
    public function getUser() {
        global $FACTORIES;

        if ($this->isLoggedIn() === true) {
            $username = $_SESSION['login'];
            $qF = new QueryFilter(User::USERNAME, $username, "=");
            $user = $FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF), true);
            if ($user != null) {
                return $user;
            } else {
                throw new Exception('No user with username \"' . $username . '\" found!');
            }
        } else {
            throw new Exception('Requested data of logged in user, but no user logged in!');
        }
    }


    /**
     * Returns true if in switched user mode and false if not.
     */
    public function isSwitchedUser() {
        if (!empty($_SESSION['realLogin'])) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * Returns the User-Data of the really loggedin-User whether switched or not.
     * If not switched, it returns the same as getUser(). If not authenticated, it throws a exception.
     * @throws Exception
     */
    public function getRealUser() {
        global $FACTORIES;

        if ($this->isLoggedIn() === true) {
            if ($this->isSwitchedUser() === true) {
                $username = $_SESSION['realLogin'];
                $qF = new QueryFilter(User::USERNAME, $username, "=");
                $user = $FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF), true);
                if ($user != null) {
                    return $user;
                } else {
                    throw new Exception('No user with username \"' . $username . '\" found!');
                }
            } else {
                return $this->getUser();
            }
        } else {
            throw new Exception('Requested data of logged in user, but no user logged in!');
        }
    }


    /**
     * Returns the id of the logged-in user. In switch user mode it returns the data of the switched user.
     * If not authenticated, it throws a exception.
     * @throws Exception
     */
    public function getUserID() {
        return $this->getUser()->getId();
    }


    /**
     * Change user means, that there is no difference to a real login of this user.
     *
     * @param String $username the username to switch to
     * @param bool $change
     *
     * @throws Exception
     */
    public function switchUser($username, $change = false) {
        global $FACTORIES;

        if ($this->isLoggedIn() && $this->isAdmin()) {
            if ($this->isSwitchedUser()) {
                throw new Exception("You can't do a user switch recursively.");
            }

            $qF = new QueryFilter(User::USERNAME, $username, "=");
            $user = $FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF), true);

            if ($user == null) {
                throw new Exception("The user does not exist: $username");
            }

            if ($user->getRole() == 2) {
                throw new Exception("You may not switch to a super admin.");
            }

            if ($change) {
                session_destroy();
                session_start();
                $_SESSION['login'] = $username;
            } else {
                $_SESSION['realLogin'] = $_SESSION['login'];
                $_SESSION['login'] = $username;
            }
        } else {
            throw new Exception("You have to be admin to do an userswitch!");
        }
    }


    /**
     * Returns true if the logged in user is an admin.
     * @throws Exception
     */
    public function isAdmin() {
        if ($this->isLoggedIn() && $this->getUser()->getRole()) {
            return true;
        }
        return false;
    }


    /**
     * Returns true if the logged in user is an superadmin.
     * @throws Exception
     */
    public function isSuperAdmin() {
        if ($this->isLoggedIn() && $this->getUser()->getRole() == 2) {
            return true;
        }
        return false;
    }


    /**
     * Do login
     *
     * @param string $username
     * @param string $password
     * @param bool $remember
     *
     * @return bool
     * @throws Exception
     */
    public function login($username, $password, $remember) {

        // insufficient data provided
        if (empty($username) || empty($password)) {
            return false;
        }

        //check the database for username
        if ($this->checkDB($username, $password)) {
            // ready to login
            $_SESSION['login'] = $username;

            // if remember me is set
            if (isset($remember)) {
                $this->setRememberMe($username);
            }
            return true;
        } else {
            return false;
        }
    }


    /**
     * Logout the currently logged-in user.
     * If switched user, it switch back to real user.
     */
    public function logout() {
        if ($this->isSwitchedUser()) {
            $_SESSION['login'] = $_SESSION['realLogin'];
            unset($_SESSION['realLogin']);
        } else {
            session_destroy();

            // delete remember me session
            if (isset($_COOKIE['rememberMe'])) {
                list($selector) = explode(':', $_COOKIE['rememberMe']);
                $this->deleteSession($selector);
            }
        }
    }


    /**
     * Sets a rememberMe cookie and adds the information to the session table
     * @param string $username The username
     * @throws Exception
     */
    private function setRememberMe($username) {
        global $FACTORIES;

        $qF = new QueryFilter(User::USERNAME, $username, "=");
        $user = $FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF), true);

        $selector = base64_encode(random_bytes(9));
        $authenticator = random_bytes(33);

        setcookie(
            "rememberMe",
            $selector . ':' . base64_encode($authenticator),
            time() + REMEMBER_ME_COOKIE_LIFETIME,
            '/',
            $_SERVER['SERVER_NAME']
        );

        $authenticator = password_hash($authenticator, PASSWORD_BCRYPT);
        $session = new Session(
            0,
            $selector,
            $authenticator,
            $user->getId(),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime("+" . REMEMBER_ME_COOKIE_LIFETIME . " seconds"))
        );
        $FACTORIES::getSessionFactory()->save($session);
    }


    private function renewRememberMe($selector, $authenticator) {
        global $FACTORIES;

        setcookie(
            "rememberMe",
            $selector . ':' . base64_encode($authenticator),
            time() + REMEMBER_ME_COOKIE_LIFETIME,
            '/',
            $_SERVER['SERVER_NAME']
        );

        $qF = new QueryFilter(Session::SELECTOR, $selector, "=");
        $uS = new UpdateSet(Session::EXPIRES, date('Y-m-d H:i:s', strtotime("+" . REMEMBER_ME_COOKIE_LIFETIME . " seconds")));
        $FACTORIES::getSessionFactory()->massUpdate(array($FACTORIES::FILTER => $qF, $FACTORIES::UPDATE => $uS));
    }


    private function deleteSession($selector) {
        global $FACTORIES;

        setcookie(
            "rememberMe",
            "",
            time() - 3600
        );

        $qF = new QueryFilter(Session::SELECTOR, $selector, "=");
        $FACTORIES::getSessionFactory()->massDeletion(array($FACTORIES::FILTER => $qF));
    }


    /**
     * Check the database for login user
     * Get the password for the user
     * compare hash
     * Important: Return a generic error message in all cases.
     *
     * @param string $username Raw username
     * @param string $password expected to be hashed
     *
     * @return bool TRUE on success and throws an exception otherwise
     * @throws Exception
     */
    private function checkDB($username, $password) {
        global $FACTORIES;

        $qF = new QueryFilter(User::USERNAME, $username, "=");
        $user = $FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF), true);

        if (empty($user)) {
            throw new Exception('Wrong username or password');
        }

        // Check if password is correct
        if (!empty($user->getPassword()) && password_verify($password, $user->getPassword())) {
            // Check if user is alive and (if required) activated
            if ($this->checkIfUserAlive($user)) {
                $user->setLastLogin(date('Y-m-d H:i:s'));
                $FACTORIES::getUserFactory()->update($user);
                return true;
            } // there is no else case (checkIfUserAlive throws an exception if the user is not active).
        } else {
            throw new Exception('Wrong username or password');
        }
        return false;
    }


    /**
     * Checks if the user is alive and (if required) activated
     * @param User $user The user
     * @return bool TRUE if user is alive; throws an exception if not
     * @throws Exception
     */
    private function checkIfUserAlive($user) {
        if ($user->getAlive() && ($user->getActivated() || !ONLY_ACTIVATED_USERS)) {
            return true;
        } else {
            if (!$user->getAlive()) {
                throw new Exception('This user has been deactivated.');
            } else {
                throw new Exception('Your email has not been confirmed.');
            }
        }
    }


    /**
     * Get a Gravatar URL for a specified email address.
     *
     * @param int|string $size Size in pixels, defaults to 80px [ 1 - 2048 ]
     *
     * @return String          URL
     * @throws Exception
     */
    function getGravatar($size = 80) {
        if ($this->isLoggedIn()) {
            $gravatar = new Gravatar_Library();
            $email = $this->getUser()->getEmail();
            return $gravatar->getGravatar($email, $size, 'mm', 'g', false);
        } else {
            throw new Exception('Requested gravatar for logged in user, but no user logged in!');
        }
    }

}
