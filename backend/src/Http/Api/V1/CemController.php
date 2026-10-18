<?php

namespace Chronos\Http\Api\V1;

use Chronos\Domain\Events\EventType;
use Chronos\Domain\Workflows\JobStatus;
use Chronos\Infrastructure\Agents\JobCdl;
use Chronos\Infrastructure\Configuration\SystemSettings;
use Chronos\Infrastructure\Logging\JobLog;
use Chronos\Infrastructure\Logging\Logger;
use Chronos\Infrastructure\Security\Authentication;

use Exception;
use stdClass;


/*
The MIT License (MIT)

Copyright (c) 2018 The Chronos Project

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

use Chronos\Infrastructure\Persistence\Model\Event;
use Chronos\Infrastructure\Persistence\RepositoryRegistry;
use Chronos\Infrastructure\Persistence\Model\Job;
use Chronos\Infrastructure\Persistence\Model\Node;
use Chronos\Infrastructure\Persistence\Model\System;
use Chronos\Infrastructure\Persistence\Query\QueryFilter;
use Chronos\Infrastructure\Persistence\Query\ContainFilter;

class CemController extends AgentResponse {

    public $get_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function get() {
        $node = $this->cemNode($this->get['uniqueId'], $this->get['version'], $this->get['environment']);

        $job = null;
        // Request specific job by its id
        if (!empty($this->get['jobId'])) {
            $jobId = trim($this->get['jobId']);
            if (is_numeric($jobId)) {
                $job = RepositoryRegistry::getJobRepository()->get($jobId);
                if (!$job) {
                    $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                    throw new Exception('Job does not exist!');
                }
            } else {
                throw new Exception('Invalid job id!');
            }
        } else { // request next job to be executed
            if ($node->getCurrentJob() !== null) {
                // This should not happen, according to our records, this node is executing another job.
                Logger::getInstance()->debug("According to our records, this node is executing another job. However, it requested a new job to be executed. Node ID: " .
                    $node->getId() . " Hostname: " . $node->getHostname() . " Currently executing according to our records: " . $node->getCurrentJob() );
                $event = new Event(0, "Inconsistent records", date('Y-m-d H:i:s'),
                    "According to the records, this node is executing another job. However, it requested a new job to be executed. Currently executing according to our records: " . $node->getCurrentJob(),
                    EventType::NODE, $node->getCurrentJob(), null, $node->getId());
                RepositoryRegistry::getEventRepository()->save($event);
            }

            $filters = [];
            $filters[] = new QueryFilter(Job::ENVIRONMENT, "cem-".$node->getEnvironment(), "=");
            $filters[] = new QueryFilter(Job::STATUS, JobStatus::SCHEDULED, "=");

            // Get all systems supporting automated setup
            $sysFilters = [];
            $sysFilters[] = new QueryFilter(System::CEM, 1, "=");
            $sys = RepositoryRegistry::getSystemRepository()->filter([RepositoryRegistry::FILTER => $sysFilters]);
            $supports = [];
            foreach ($sys as $s) {
                $supports[] = $s->getId();
            }
            $filters[] = new ContainFilter(Job::SYSTEM_ID, $supports);

            $job = RepositoryRegistry::getJobRepository()->filterWithTimeout([RepositoryRegistry::FILTER => $filters], 60,true);
            if (!$job) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_NO_JOB_IN_QUEUE);
                $this->setError('No job in queue!');
                exit();
            }
        }

        $data = new stdClass();

        $jobData = new stdClass();
        $jobData->id = intval($job->getId());
        $jobData->status = intval($job->getStatus());
        $data->job = $jobData;

        $system = new stdClass();
        $sys = RepositoryRegistry::getSystemRepository()->get($job->getSystemId());
        $system->id = $sys->getId();
        $data->system = $system;

        $setup = new stdClass();
        $settings = SystemSettings::getInstance($system->id)->getSection("setup");
        foreach ($settings as $key => $value) {
            $setup->$key = $value;
        }
        $data->setup = $setup;

        $data->cdl = JobCdl::forJob($job);

        $this->add($data);
    }

    public $post_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function post() {
        $node = $this->cemNode($this->get['uniqueId'], $this->get['version'], $this->get['environment']);

        if (empty($this->get['action'])) {
            throw new Exception('No action provided');
        }
        switch (strtolower($this->get['action'])) {
            case(strtolower('jobStarted')):
                // Set status of job to setup
                $jobId = trim($this->request['jobId']);
                $job = RepositoryRegistry::getJobRepository()->get($jobId);
                if (!$job) {
                    $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                    throw new Exception('Job does not exist!');
                }
                $job->setStatus(JobStatus::SETUP);
                RepositoryRegistry::getJobRepository()->update($job);
                $node->setCurrentJob($job->getId());
                RepositoryRegistry::getNodeRepository()->update($node);
                $event = new Event(0, "Job started", date('Y-m-d H:i:s'),
                    "Start working on job " . $job->getId() . ".",
                    EventType::NODE, $job->getId(), null, $node->getId());
                RepositoryRegistry::getEventRepository()->save($event);
                break;

            case(strtolower('jobTerminated')):
                $jobId = trim($this->request['jobId']);
                $job = RepositoryRegistry::getJobRepository()->get($jobId);
                if (!$job) {
                    $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                    throw new Exception('Job does not exist!');
                }
                if (!empty($this->request['reason'])) {
                    // If the client specified a reason for the termination, always set job state to failed and add event containing the message from the client
                    $reason = $this->request['reason'];
                    $job->setStatus(JobStatus::FAILED);
                    RepositoryRegistry::getJobRepository()->update($job);
                    // Create event
                    $event = new Event(0, "Job terminated", date('Y-m-d H:i:s'),
                        "The job with the ID " . $job->getId() . " has terminated. The following reason has been provided by the CEM bootstrapper: " . $reason,
                        EventType::NODE, $job->getId(), null, $node->getId());
                    RepositoryRegistry::getEventRepository()->save($event);
                } else if ($job->getStatus() == JobStatus::RUNNING || $job->getStatus() == JobStatus::SCHEDULED
                    || $job->getStatus() == JobStatus::SETUP ) {
                    $job->setStatus(JobStatus::FAILED);
                    RepositoryRegistry::getJobRepository()->update($job);
                    // Create event
                    $event = new Event(0, "Job terminated", date('Y-m-d H:i:s'),
                        "The job with the ID " . $job->getId() . " has terminated. Job was not reported as finished, thus setting job state to failed.",
                        EventType::NODE, $job->getId(), null, $node->getId());
                    RepositoryRegistry::getEventRepository()->save($event);
                } else {
                    $event = new Event(0, "Job finished", date('Y-m-d H:i:s'),
                        "The job with the ID " . $job->getId() . " has been completed.",
                        EventType::NODE, $job->getId(), null, $node->getId());
                    RepositoryRegistry::getEventRepository()->save($event);
                }
                $node->setCurrentJob(null);
                RepositoryRegistry::getNodeRepository()->update($node);
                break;

            case(strtolower('nodeStatus')):
                $currentJob = trim($this->request['jobId']);
                $cpu = floatval(trim($this->request['cpu'])); // Load average last minute, int, as percentage 0-100
                $memoryUsed = intval(trim($this->request['memoryUsed'])); // Current memory utilization in bytes, long
                $memoryTotal = intval(trim($this->request['memoryTotal'])); // Total available memory in bytes, long
                $hostname = trim($this->request['hostname']); // Hostname, String
                $ip = $_SERVER['REMOTE_ADDR'];
                $os = trim($this->request['os']); // OS name and patch state, String
                $healthStatus = trim($this->request['healthStatus']); // An arbitrary string indicating issues with the node (e.g., running low on memory). Empty if everything is fine.

                if ($currentJob == null || $currentJob == '') {
                    $currentJob = null;
                } else {
                    $currentJob = intval($currentJob);
                }
                if ($node->getCurrentJob() != $currentJob) {
                    // This should not happen...
                    Logger::getInstance()->notice("Reported Job does not match our records. Node: " .
                        $node->getId() . " Hostname: " . $hostname . " Reported Job: " . $currentJob . " Job in DB: " . $node->getCurrentJob() );
                    $event = new Event(0, "Inconsistent records", date('Y-m-d H:i:s'),
                        "Reported job does not match the records. Job reported by the node: " . $currentJob . " Job in our records: " . $node->getCurrentJob(),
                        EventType::NODE, $currentJob, null, $node->getId());
                    RepositoryRegistry::getEventRepository()->save($event);
                    $node->setCurrentJob($currentJob);
                }

                if (!empty($healthStatus) && empty($node->getHealthStatus())) {
                    // Health status changed
                    $event = new Event(0, "Bad health status", date('Y-m-d H:i:s'),
                        "The node with the id " . $node->getId() . " reported a bad health status. The status reported by the node is: " . $healthStatus,
                        EventType::NODE, $currentJob, null, $node->getId());
                    RepositoryRegistry::getEventRepository()->save($event);
                }

                // Update information in DB
                $node->setCpu($cpu);
                $node->setMemoryUsed($memoryUsed);
                $node->setMemoryTotal($memoryTotal);
                $node->setHostname($hostname);
                $node->setIp($ip);
                $node->setOs($os);
                $node->setHealthStatus($healthStatus);
                $node->setLastUpdate(date('Y-m-d H:i:s'));
                RepositoryRegistry::getNodeRepository()->update($node);
                break;

            case(strtolower('jobLog')):
                $jobId = trim($this->request['jobId']);
                $job = RepositoryRegistry::getJobRepository()->get($jobId);
                if (!$job) {
                    $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                    throw new Exception('Job does not exist!');
                }
                if (empty($this->request['message'])) {
                    throw new Exception('No log message provided.');
                }
                // Make log messages blue
                $message = "\e[34m" . "CEM > " . $this->request['message'] . "\e[0m\n";
                (new JobLog(UPLOADED_DATA_PATH))->append($job->getId(), $message );
                break;

            default:
                throw new Exception('Unsupported action');
        }
    }



    // Every request needs to contain:
    // - The unique ID of the client
    // - The version of the client
    // - The environment the client is executed in
    private function cemNode(&$uniqueId, &$version, &$environment) {
        $uniqueId = trim($uniqueId);
        if (empty($uniqueId)) {
            throw new Exception('No unique id provided');
        }

        $version = trim($version);
        if (empty($version)) {
            throw new Exception('No version provided');
        }
        $settings = SystemSettings::getInstance(0);
        $minVersion = $settings->get("cem", "minVersion");
        if ( isset($minVersion) && intval($minVersion->getValue()) > intval($version) ) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_CEM_OUTDATED_VERSION);
            throw new Exception('Outdated version, please update. Minimum required version is: ' . intval($minVersion->getValue()));
        }

        $environment = trim($environment);
        if (empty($environment)) {
            throw new Exception('No environment provided');
        }
        $environmentsStr = $settings->get("cem", "environments");
        if (isset($environmentsStr) && empty($environmentsStr->getValue())) {
            throw new Exception('No CEM environments defined.');
        }
        $environments = json_decode($environmentsStr->getValue());
        if (!in_array($environment, $environments)) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_CEM_UNKNOWN_ENVIRONMENT);
            throw new Exception('Unknown CEM environment: ' . $environment);
        }

        $node = RepositoryRegistry::getNodeRepository()->get($uniqueId);
        if (!$node) {
            $node = new Node($uniqueId,$environment, $version, null, null,
                null, null, null, null, null,
                null, date('Y-m-d H:i:s'));
            RepositoryRegistry::getNodeRepository()->save($node);
        }

        if ($node->getEnvironment() !== $environment) {
            $node->setEnvironment($environment);
            RepositoryRegistry::getNodeRepository()->update($node);
        }

        if ($node->getVersion() !== $version) {
            $node->setVersion($version);
            RepositoryRegistry::getNodeRepository()->update($node);
        }

        return $node;
    }

}
