<?php

namespace Chronos\Http\Api\V1;

use Chronos\Application\Agents\SupportedSystems;
use Chronos\Domain\Events\EventType;
use Chronos\Domain\Workflows\Environment;
use Chronos\Domain\Workflows\JobPhases;
use Chronos\Domain\Workflows\JobStatus;
use Chronos\Infrastructure\Agents\JobCdl;
use Chronos\Infrastructure\Configuration\SystemDefinition;
use Chronos\Infrastructure\Configuration\SystemSettings;
use Chronos\Infrastructure\Logging\JobLog;
use Chronos\Infrastructure\Logging\Logger;
use Chronos\Infrastructure\Network\IpAddress;
use Chronos\Infrastructure\Security\Authentication;

use Exception;
use stdClass;


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

use Chronos\Infrastructure\Persistence\Query\ContainFilter;
use Chronos\Infrastructure\Persistence\Model\Event;
use Chronos\Infrastructure\Persistence\RepositoryRegistry;
use Chronos\Infrastructure\Persistence\Model\Job;
use Chronos\Infrastructure\Persistence\Query\QueryFilter;

class JobController extends AgentResponse {

    public $get_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function get() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }
        $id = trim($this->get['id']);
        $job = null;
        if (is_numeric($id)) {
            $job = RepositoryRegistry::getJobRepository()->get($id);
            if (!$job) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                throw new Exception('Job does not exist!');
            }
        } else if ($id == 'next') {
            if (!empty($this->get['supports'])) {
                $environment = Environment::DEFAULT_NAME;
                if (!empty($this->get['environment'])) {
                    $environment = trim($this->get['environment']);
                }
                $filters = [];
                if ($this->get['supports'] == 'demoall') { // demo mode to match all systems
                    // we accept all systems now
                } else {
                    $supports = SupportedSystems::resolveIdentifiers($this->get['supports']);
                    $filters[] = new ContainFilter(Job::SYSTEM_ID, $supports);
                }
                $filters[] = new QueryFilter(Job::ENVIRONMENT, "system-".$environment, "=");
                $filters[] = new QueryFilter(Job::STATUS, JobStatus::SCHEDULED, "=");
                $job = RepositoryRegistry::getJobRepository()->filter([RepositoryRegistry::FILTER => $filters], true);
            } else {
                throw new Exception('No list of supported systems provided!');
            }
            if (!$job) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_NO_JOB_IN_QUEUE);
                $this->setError('No job in queue!');
                exit();
            }
        }

        $evaluation = RepositoryRegistry::getEvaluationRepository()->get($job->getEvaluationId());
        $user = RepositoryRegistry::getUserRepository()->get($job->getUserId());
        $experiment = RepositoryRegistry::getExperimentRepository()->get($evaluation->getExperimentId());
        $system = RepositoryRegistry::getSystemRepository()->get($experiment->getSystemId());

        // Change to int's
        $data = new stdClass();
        $data->id = intval($job->getId());
        $data->user = intval($job->getUserId());
        $data->status = intval($job->getStatus());
        $data->progress = intval($job->getProgress());
        $data->username = $user->getUsername();
        $data->phases = intval($job->getPhases());
        $data->currentPhase = intval($job->getCurrentPhase());
        $data->user = intval($user->getId());
        $data->name = $evaluation->getName();
        $data->description = $job->getDescription();
        $sys = new SystemDefinition($system->getId());
        $data->system = $sys->getIdentifier();
        $data->environment = $job->getEnvironment();
        $data->cdl = JobCdl::forJob($job);

        $data->created = $job->getCreated();
        $data->started = $job->getStarted();
        $data->finished = $job->getFinished();

        $this->add($data);
    }

    public $post_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function post() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }
        $id = trim($this->get['id']);
        $job = RepositoryRegistry::getJobRepository()->get($id);
        if (!$job) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }
        if (empty($this->get['action'])) {
            throw new Exception('No action provided');
        }
        switch (strtolower($this->get['action'])) {
            case(strtolower('getUploadTarget')):
                if (SystemSettings::getInstance(0)->get('ftp', 'useFtpUploadForLocalClients')->getValue() && IpAddress::cidrMatch($_SERVER['REMOTE_ADDR'], SystemSettings::getInstance(0)->get('ftp', 'localNetworkCIDR')->getValue())) {
                    // Client is in the local subnet, use FTP Upload
                    $this->add($this->getFtpUploadTarget($this->get['id']));
                } else {
                    // Client is not in the local subnet, use HTTP upload
                    $this->add($this->getHttpUploadTarget($this->get['id']));
                }
                break;
            case(strtolower('appendLog')):
                if (empty($this->request['log'])) {
                    throw new Exception('No or insufficient data provided.');
                }
                (new JobLog(UPLOADED_DATA_PATH))->append($job->getId(), $this->request['log']);
                break;

            case 'upload':
                $this->upload($job);
                break;
            default:
                throw new Exception('Unsupported action');
        }
    }


    public $patch_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function patch() {
        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }

        $auth = Authentication::getInstance();
        $job = RepositoryRegistry::getJobRepository()->get($this->get['id']);
        if (!$job) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }
        $evaluation = RepositoryRegistry::getEvaluationRepository()->get($job->getEvaluationId());

        if (isset($this->request['status'])) {
            $oldStatus = $job->getStatus();
            $job->setStatus($this->request['status']);
            list($environment, $cem) = Environment::parse($job->getEnvironment());
            $event = new Event(0, "Job status changed", date('Y-m-d H:i:s'),
                "Job of evaluation '" . $evaluation->getName() . "' running in " . ($cem?"CEM ":"") . "environment '" . $environment . "' changed from " . JobStatus::label($oldStatus) . " to " . JobStatus::label($job->getStatus()) . ".",
                EventType::JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null, null);
            RepositoryRegistry::getEventRepository()->save($event);
        }
        if (isset($this->request['progress'])) {
            $job->setProgress($this->request['progress']);
        }
        if (!empty($this->request['currentPhase'])) {
            if (key_exists(intval($this->request['currentPhase']),JobPhases::NAMES)) {
                Logger::getInstance()->debug("Received update for current phase. New Phase: (" . $this->request['currentPhase'] . ") " . JobPhases::NAMES[$this->request['currentPhase']]);
                list($environment, $cem) = Environment::parse($job->getEnvironment());
                $event = new Event(0, "Job changed phase", date('Y-m-d H:i:s'),
                    "Job of evaluation '" . $evaluation->getName() . "' running in " . ($cem?"CEM ":"") . "environment '" . $environment . "' changed to phase (" . $this->request['currentPhase'] . ") " . JobPhases::NAMES[$this->request['currentPhase']] . ".",
                    EventType::JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null, null);
                RepositoryRegistry::getEventRepository()->save($event);
                $job->setCurrentPhase(intval($this->request['currentPhase']));
                $job->setProgress(0);
            } else {
                Logger::getInstance()->warning("Received update for job containing an invalid phase number: " . $this->request['currentPhase']);
            }
        }
        if (!empty($this->request['result'])) {
            $job->setResult($this->request['result']);
            list($environment, $cem) = Environment::parse($job->getEnvironment());
            $event = new Event(0, "Job sent results", date('Y-m-d H:i:s'),
                "Job of evaluation '" . $evaluation->getName() . "' running in " . ($cem?"CEM ":"") . "environment '" . $environment . "' has sent results.",
                EventType::JOB, $job->getId(), ($auth->isLoggedIn()) ? $auth->getUserID() : null, null);
            RepositoryRegistry::getEventRepository()->save($event);
        }
        RepositoryRegistry::getJobRepository()->update($job);
    }



    // -------------------------
    // Private Method
    // -------------------------

    /**
     * @param $job Job
     * @throws Exception
     */
    private function upload($job) {
        global $_FILES;

        $fileUploadName = "result";
        if (!isset($_FILES[$fileUploadName]['error']) || is_array($_FILES[$fileUploadName]['error'])) {
            throw new Exception('Invalid parameters!');
        }

        // check for error values
        switch ($_FILES[$fileUploadName]['error']) {
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

        $filename = realpath(UPLOADED_DATA_PATH) . "/evaluation/" . $job->getId() . ".zip";
        if (!move_uploaded_file($_FILES[$fileUploadName]['tmp_name'], $filename)) {
            throw new Exception('Failed to move uploaded file to destination!');
        }
    }

    /**
     * @param $id
     * @return stdClass
     * @throws Exception
     */
    private function getFtpUploadTarget($id) {
        // FTP Config
        $data = new stdClass();
        $data->method = 'ftp';
        $data->hostname = SystemSettings::getInstance(0)->get('ftp', 'ftpServer')->getValue();
        $data->port = SystemSettings::getInstance(0)->get('ftp', 'ftpPort')->getValue();
        $data->username = SystemSettings::getInstance(0)->get('ftp', 'ftpUsername')->getValue();
        $data->password = SystemSettings::getInstance(0)->get('ftp', 'ftpPassword')->getValue();
        $job = RepositoryRegistry::getJobRepository()->get($id);
        $data->path = '/chronos/evaluation/';
        $data->filename = $job->getId() . '.zip';
        return $data;
    }

    /**
     * @param $id
     * @return stdClass
     * @throws Exception
     */
    private function getHttpUploadTarget($id) {
        // HTTP Config
        $data = new stdClass();
        $data->method = 'http';
        $data->path = '/api/v1/job';
        $data->hostname = SystemSettings::getInstance(0)->get('other', 'uploadedDataHostname')->getValue();
        return $data;
    }

}
