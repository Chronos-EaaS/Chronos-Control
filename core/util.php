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

use DBA\AbstractModel;
use DBA\ContainFilter;
use DBA\Evaluation;
use DBA\Event;
use DBA\Experiment;
use DBA\Job;
use DBA\OrderFilter;
use DBA\QueryFilter;
use DBA\User;

class Util {
    public static function checkGender($gender) {
        // 1 = Men
        // 2 = Women
        if ($gender === 1 || $gender === 2) {
            return true;
        } else {
            return false;
        }
    }

    public static function checkName($name) {
        if (is_numeric($name)) {
            return false;
        }
        return true;
    }

    public static function recursiveCopy($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    Util::recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    public static function getObjectFromPhasesBitMask($bitMask, $obj = null) {
        if ($obj == null) {
            $obj = new stdClass();
        }
        $obj->phases_prepare = !boolval($bitMask & Define::JOB_EXCLUDE_PHASE_PREPARE);
        $obj->phases_warmUp = !boolval($bitMask & Define::JOB_EXCLUDE_PHASE_WARM_UP);
        $obj->phases_execute = !boolval($bitMask & Define::JOB_EXCLUDE_PHASE_EXECUTE);
        $obj->phases_analyze = !boolval($bitMask & Define::JOB_EXCLUDE_PHASE_ANALYZE);
        $obj->phases_clean = !boolval($bitMask & Define::JOB_EXCLUDE_PHASE_CLEAN);
        return $obj;
    }

    /**
     * @param $array AbstractModel[]
     * @return array
     */
    public static function arrayOfIds($array) {
        $arr = array();
        foreach ($array as $entry) {
            $arr[] = $entry->getId();
        }
        return $arr;
    }

    public static function checkUsername($username) {
        global $FACTORIES;

        // Check if username not already used
        $qF = new QueryFilter(User::USERNAME, $username, "=");
        if ($FACTORIES::getUserFactory()->filter(array($FACTORIES::FILTER => $qF), true) != null) {
            return false;
        }

        // Usernames must not consist only of numbers
        if (is_numeric($username)) {
            return false;
        }
        return true;
    }

    public static function calcPhasesBitMask($preparePhase, $warmUpPhase, $executePhase, $analyzePhase, $cleanPhase) {
        $bitMask = 0b0;
        if (!$preparePhase) {
            $bitMask = $bitMask | Define::JOB_EXCLUDE_PHASE_PREPARE;
        }
        if (!$warmUpPhase) {
            $bitMask = $bitMask | Define::JOB_EXCLUDE_PHASE_WARM_UP;
        }
        if (!$executePhase) {
            $bitMask = $bitMask | Define::JOB_EXCLUDE_PHASE_EXECUTE;
        }
        if (!$analyzePhase) {
            $bitMask = $bitMask | Define::JOB_EXCLUDE_PHASE_ANALYZE;
        }
        if (!$cleanPhase) {
            $bitMask = $bitMask | Define::JOB_EXCLUDE_PHASE_CLEAN;
        }
        return $bitMask;
    }

    public static function getStatusText($status) {
        switch ($status) {
            case Define::JOB_STATUS_SCHEDULED:
                return "scheduled";
            case Define::JOB_STATUS_RUNNING:
                return "running";
            case Define::JOB_STATUS_FINISHED:
                return "finished";
            case Define::JOB_STATUS_ABORTED:
                return "aborted";
            case Define::JOB_STATUS_FAILED:
                return "failed";
            default:
                return "unknown";
        }
    }

    public static function checkPassword($password) {
        // check length, letters, caps and numbers
        if (preg_match("#.*^(?=.{" . PASSWORDS_MIN_LENGTH . "," . PASSWORDS_MAX_LENGTH . "}).*$#", $password)) {
            if (REQUIRE_PASSWORD_COMPLEXITY) {
                if (preg_match("#.*^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).*$#", $password)) {
                    //Password is good, complexity requirements fulfilled
                    return true;
                } else {
                    // Password is bad, complexity requirements not fulfilled
                    return false;
                }
            } else {
                //Password is good, no complexity required
                return true;
            }
        } else {
            // Password is bad, to short
            return false;
        }
    }

    public static function checkEMail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function getFullnameOfUser($userId) {
        global $FACTORIES;

        $user = $FACTORIES::getUserFactory()->get($userId);
        return $user->getFirstname() . ' ' . $user->getLastname() . ' (' . $user->getUsername() . ')';
    }

    public static function getSystemName($systemId) {
        global $FACTORIES;

        return $FACTORIES::getSystemFactory()->get($systemId)->getName();
    }

    public static function eventFilter($array, $limit = 20) {
        global $FACTORIES;

        $events = [];
        foreach ($array as &$item) {
            if (!is_array($item)) {
                $item = array($item);
            }
        }

        $experiments = array();
        $evaluations = array();
        $jobs = array();
        $projects = array();
        if (isset($array['project'])) {
            $projects = array_merge($projects, $array['project']);
            $qF = new ContainFilter(Experiment::PROJECT_ID, Util::arrayOfIds($array['project']));
            $ex = $FACTORIES::getExperimentFactory()->filter(array($FACTORIES::FILTER => $qF));
            if (!isset($array['experiment'])) {
                $array['experiment'] = $ex;
            } else {
                $array['experiment'] = array_merge($array['experiment'], $ex);
            }
        }
        if (isset($array['experiment'])) {
            $experiments = array_merge($experiments, $array['experiment']);
            $qF = new ContainFilter(Evaluation::EXPERIMENT_ID, Util::arrayOfIds($experiments));
            $ev = $FACTORIES::getEvaluationFactory()->filter(array($FACTORIES::FILTER => $qF));
            if (!isset($array['evaluation'])) {
                $array['evaluation'] = $ev;
            } else {
                $array['evaluation'] = array_merge($array['evaluation'], $ev);
            }
        }
        if (isset($array['evaluation'])) {
            $evaluations = array_merge($evaluations, $array['evaluation']);
            $qF = new ContainFilter(Job::EVALUATION_ID, Util::arrayOfIds($evaluations));
            $jo = $FACTORIES::getJobFactory()->filter(array($FACTORIES::FILTER => $qF));
            if (!isset($array['job'])) {
                $array['job'] = $jo;
            } else {
                $array['job'] = array_merge($array['job'], $jo);
            }
        }
        if (isset($array['job'])) {
            $jobs = array_merge($jobs, $array['job']);
        }

        $oF1 = new OrderFilter(Event::TIME, "DESC");
        $oF2 = new OrderFilter(Event::EVENT_ID, "DESC");
        $all = $FACTORIES::getEventFactory()->filter(array($FACTORIES::ORDER => array($oF1, $oF2)));
        foreach ($all as $event) {
            if ($event->getEventType() == Define::EVENT_PROJECT && in_array($event->getRelatedId(), Util::arrayOfIds($projects))) {
                $events[] = $event;
            } else if ($event->getEventType() == Define::EVENT_EXPERIMENT && in_array($event->getRelatedId(), Util::arrayOfIds($experiments))) {
                $events[] = $event;
            } else if ($event->getEventType() == Define::EVENT_EVALUATION && in_array($event->getRelatedId(), Util::arrayOfIds($evaluations))) {
                $events[] = $event;
            } else if ($event->getEventType() == Define::EVENT_JOB && in_array($event->getRelatedId(), Util::arrayOfIds($jobs))) {
                $events[] = $event;
            }
            if (sizeof($events) >= $limit) {
                break;
            }
        }
        return $events;
    }
}