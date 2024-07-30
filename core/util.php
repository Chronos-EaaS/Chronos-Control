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
use DBA\System;
use DBA\Factory;
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

    public static function pass($input) {
        return $input;
    }

    /**
     * @param $path
     * @return Element[]
     * @throws Exception
     */
    public static function scanForElements($path) {
        if (!file_exists($path) || !is_dir($path)) {
            return [];
        }
        $dir = scandir($path);
        $elements = [];
        foreach ($dir as $entry) {
            if ($entry[0] == '.') {
                continue; // skip hidden folders
            } else if (!is_dir($path . $entry)) {
                continue; // no files
            }
            // TODO: check here if the element is properly configured
            // TODO: check if override happens
            $element = new Element($path . $entry);
            if ($element->isValid()) {
                $elements[] = $element;
            }
        }
        return $elements;
    }

    /**
     * @param $jobs Job[][]|Job[]
     * @return array
     */
    public static function getDifferentParameters($jobs) {
        $preparedJobs = [];
        foreach ($jobs as $j) {
            if (is_array($j)) {
                $job = $j[0];
            } else {
                $job = $j;
            }
            if (!is_array($job->getConfiguration())) { // decode if required
                $job->setConfiguration(json_decode($job->getConfiguration(), TRUE));
            }
            $preparedJobs[] = $job;
        }

        $variations = [];
        $jobsParams = [];

        // get all elements
        $parameters = $preparedJobs[0]->getConfiguration()[Define::CONFIGURATION_PARAMETERS];
        foreach ($parameters as $param => $val) {
            foreach ($preparedJobs as $preparedJob) {
                $eval = $preparedJob->getConfiguration()[Define::CONFIGURATION_PARAMETERS];
                if ($eval[$param] != $val && !in_array($param, $variations)) {
                    $variations[] = $param;
                }
                $jobsParams[$preparedJob->getId()] = $eval;
            }
        }
        return [$variations, $jobsParams];
    }

    /**
     * @param $jobs Job[][]
     * @param $parameter array
     * @return array
     */
    public static function mergeJobs($jobs, $parameter) {
        // prepare all arrays
        $preparedJobs = [];
        foreach ($jobs as $j) {
            foreach ($j as &$job) {
                if (!is_array($job->getConfiguration())) { // only decode if needed
                    $job->setConfiguration(json_decode($job->getConfiguration(), TRUE));
                }
            }
            $preparedJobs[] = $j;
        }

        $jobGroup = [];
        $internLabels = [];
        $groupArrays = [];
        foreach ($preparedJobs as $preparedJob) {
            $groupMatched = false;
            foreach ($groupArrays as $index => $arr) {
                $diff = array_diff_assoc($preparedJob[0]->getConfiguration()[Define::CONFIGURATION_PARAMETERS], $arr);
                $match = true;
                foreach ($diff as $k => $d) {
                    if (!in_array($k, $parameter)) {
                        $match = false;
                    } else {
                        if (!in_array($k, $internLabels)) {
                            $internLabels[] = $k;
                        }
                    }
                }
                if ($match) {
                    $groupMatched = $index;
                    break;
                }
            }

            if ($groupMatched === false) {
                $jobGroup[] = [$preparedJob];
                $groupArrays[] = $preparedJob[0]->getConfiguration()[Define::CONFIGURATION_PARAMETERS];
            } else {
                $jobGroup[$groupMatched][] = $preparedJob;
            }
        }

        $labels = [];
        foreach ($internLabels as $l) {
            if ($l == 'run') {
                continue;
            }
            foreach ($preparedJobs as $preparedJob) {
                if (!in_array($preparedJob[0]->getConfiguration()[Define::CONFIGURATION_PARAMETERS][$l], $labels)) {
                    $labels[] = $preparedJob[0]->getConfiguration()[Define::CONFIGURATION_PARAMETERS][$l];
                }
            }
        }

        if (sizeof($labels) == 0) {
            $labels[] = $parameter;
        }

        return [$jobGroup, $labels];
    }

    /**
     * @param $path
     * @return Plot[]
     * @throws Exception
     */
    public static function scanForPlots($path) {
        if (!is_dir($path)) {
            return [];
        }
        $dir = scandir($path);
        $plots = [];
        foreach ($dir as $entry) {
            if ($entry[0] == '.') {
                continue; // skip hidden folders
            } else if (!is_dir($path . $entry)) {
                continue; // no files
            }
            // TODO: check here if the plot is properly configured
            // TODO: check if override happens
            $plot = new Plot($path . $entry);
            if ($plot->isValid()) {
                $plots[] = $plot;
            }
        }
        return $plots;
    }

    /**
     * @return Plot[]
     * @throws Exception
     */
    public static function getDefaultResultPlots() {
        return self::scanForPlots(SERVER_ROOT . "/libraries/graphs/");
    }

    /**
     * @return Element[]
     * @throws Exception
     */
    public static function getDefaultParameterElements() {
        return self::scanForElements(SERVER_ROOT . "/libraries/elements/");
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

    public static function readFileContents(string $file) {
        // There is still a window where the file can be deleted, and
        // file_get_contents prints a warning to the page.  But when there
        // is a way without that, only this helper needs to be changed.
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return false;
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
        $arr = [];
        foreach ($array as $entry) {
            $arr[] = $entry->getId();
        }
        return $arr;
    }

    public static function checkUsername($username) {
        // Check if username not already used
        $qF = new QueryFilter(User::USERNAME, $username, "=");
        if (Factory::getUserFactory()->filter([Factory::FILTER => $qF], true) != null) {
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
            case Define::JOB_STATUS_SETUP:
                return "setup";
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
        $user = Factory::getUserFactory()->get($userId);
        return $user->getFirstname() . ' ' . $user->getLastname() . ' (' . $user->getUsername() . ')';
    }

    public static function getSystemName($systemId) {
        return Factory::getSystemFactory()->get($systemId)->getName();
    }

    public static function eventFilter($array, $limit = 20) {
        foreach ($array as &$item) {
            if (!is_array($item)) {
                $item = [$item];
            }
            $item = Util::arrayOfIds($item);
        }

        $toload = [
            Define::EVENT_EXPERIMENT => [],
            Define::EVENT_EVALUATION => [],
            Define::EVENT_JOB => [],
            Define::EVENT_PROJECT => [],
            Define::EVENT_NODE => []
        ];
        if (isset($array['project'])) {
            $toload[Define::EVENT_PROJECT] = array_merge($toload[Define::EVENT_PROJECT], $array['project']);
            $qF = new ContainFilter(Experiment::PROJECT_ID, $toload[Define::EVENT_PROJECT]);
            $ex = Factory::getExperimentFactory()->idFilter([Factory::FILTER => $qF]);
            if (!isset($array['experiment'])) {
                $array['experiment'] = $ex;
            } else {
                $array['experiment'] = array_merge($array['experiment'], $ex);
            }
        }
        if (isset($array['experiment'])) {
            $toload[Define::EVENT_EXPERIMENT] = array_merge($toload[Define::EVENT_EXPERIMENT], $array['experiment']);
            $qF = new ContainFilter(Evaluation::EXPERIMENT_ID, $toload[Define::EVENT_EXPERIMENT]);
            $ev = Factory::getEvaluationFactory()->idFilter([Factory::FILTER => $qF]);
            if (!isset($array['evaluation'])) {
                $array['evaluation'] = $ev;
            } else {
                $array['evaluation'] = array_merge($array['evaluation'], $ev);
            }
        }
        if (isset($array['evaluation'])) {
            $toload[Define::EVENT_EVALUATION] = array_merge($toload[Define::EVENT_EVALUATION], $array['evaluation']);
            $qF = new ContainFilter(Job::EVALUATION_ID, $toload[Define::EVENT_EVALUATION]);
            $jo = Factory::getJobFactory()->idFilter([Factory::FILTER => $qF]);
            if (!isset($array['job'])) {
                $array['job'] = $jo;
            } else {
                $array['job'] = array_merge($array['job'], $jo);
            }
        }
        if (isset($array['job'])) {
            $toload[Define::EVENT_JOB] = array_merge($toload[Define::EVENT_JOB], $array['job']);
        }
        if (isset($array['node'])) {
            $toload[Define::EVENT_NODE] = array_merge($toload[Define::EVENT_NODE], $array['node']);
        }

        $types = [
            Define::EVENT_PROJECT,
            Define::EVENT_EXPERIMENT,
            Define::EVENT_EVALUATION,
            Define::EVENT_JOB,
            Define::EVENT_NODE
        ];
        $filteredEvents = [];
        foreach ($types as $type) {
            $oF1 = new OrderFilter(Event::TIME, "DESC");
            $oF2 = new OrderFilter(Event::EVENT_ID, "DESC LIMIT $limit");
            if ( $type ==  Define::EVENT_NODE ) {
                $qF1 = new ContainFilter(Event::NODE_ID, $toload[$type]);
            } else {
                $qF1 = new ContainFilter(Event::RELATED_ID, $toload[$type]);
            }
            $qF2 = new QueryFilter(Event::EVENT_TYPE, $type, "=");
            $events = Factory::getEventFactory()->filter([Factory::ORDER => [$oF1, $oF2], Factory::FILTER => [$qF1, $qF2]]);
            foreach ($events as $event) {
                $filteredEvents[$event->getId()] = $event;
            }
        }
        krsort($filteredEvents);
        return array_slice($filteredEvents, 0, $limit);
    }

    /**
     * @param $job Job
     * @return string|string[]
     */
    public static function jobToCDL(Job $job) {
        $cdl = new CDL_Library($job->getSystemId());

        // Only add setup section if system supports automated setup
        if ( Factory::getSystemFactory()->get($job->getSystemId())->getAutomatedSetup()) {
            $setupSettings = Settings_Library::getInstance($job->getSystemId())->getSection('setup');
            foreach ($setupSettings as $parameter => $value) {
                $setup = $cdl->getSetup();
                $setup->appendChild($cdl->createElement($parameter, $value));
            }
        }

        // Evaluation section
        $configuration = json_decode($job->getConfiguration(), true);
        $settings = Settings_Library::getInstance($job->getSystemId())->getSection('general');
        // Merge configuration and settings, with configuration overwriting settings on duplicate keys
        $merged = array_merge($settings, $configuration[Define::CONFIGURATION_PARAMETERS]);

        foreach ($merged as $parameter => $value) {
            $eval = $cdl->getEvaluation();
            $eval->appendChild($cdl->createElement($parameter, $value));
        }

        return $cdl->toXML();
    }

    /**
     * @param $systemId
     * @throws Exception
     */
    public static function getNextIdForSystemResults($systemId) {
        $systemLib = new System($systemId);
        $results = json_decode($systemLib->getResultsAll(), true);
        $newId = 1;
        foreach ($results['elements'] as $resultId => $val) {
            if (strpos($resultId, "system-") === 0) {
                $num = explode("-", $resultId)[1];
                if ($num >= $newId) {
                    $newId = $num + 1;
                }
            }
        }
        return $newId;
    }

    public static function updateSumsAndCounts($array, &$sums, &$counts) {
        foreach ($array as $index => $value) {
            if (isset($sums[$index])) {
                $sums[$index] += $value;
                $counts[$index] += 1;
            } else {
                $sums[$index] = $value;
                $counts[$index] = 1;
            }
        }
    }

    public static function timeDifferenceString($timestamp) {
        $current_time = new DateTime();
        $timestamp = new DateTime($timestamp);
        $interval = $current_time->diff($timestamp);

        // Check for days
        if ($interval->d > 0) {
            return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        }

        // Check for hours
        if ($interval->h > 0) {
            return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        }

        // Check for minutes
        if ($interval->i > 0) {
            return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        }

        return 'just now';
    }

}
