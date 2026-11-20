<?php

namespace Chronos\Http\Api\V1;

use Chronos\Application\Shared\Input;
use Chronos\Application\Workflows\{ExecutionAttempts, JobSpecifications};
use Chronos\Application\Artifacts\CemArtifactSetup;
use Chronos\Domain\Events\EventType;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Persistence\Query\LegacyJobFilter;
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
        if ($this->attemptProtocol()) {
            $db = new Store(RepositoryRegistry::getJobRepository()->getDB());
            $data = $db->transaction(fn(): array => $this->getAttempt($db));
            $this->add($data);
            return;
        }
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
                $db = new Store(RepositoryRegistry::getJobRepository()->getDB());
                $attempt = (new JobSpecifications($db))->latestAttempt((int) $node->getCurrentJob());
                if ($attempt !== null && in_array((int) $attempt['status'], [JobStatus::SETUP, JobStatus::RUNNING], true)) {
                    throw new Exception('The node is assigned to an attempt-managed job.');
                }
                // This should not happen, according to our records, this node is executing another job.
                Logger::getInstance()->debug("According to our records, this node is executing another job. However, it requested a new job to be executed. Node ID: " .
                    $node->getId() . " Hostname: " . $node->getHostname() . " Currently executing according to our records: " . $node->getCurrentJob() );
                $event = new Event(0, "Inconsistent records", date('Y-m-d H:i:s'),
                    "According to the records, this node is executing another job. However, it requested a new job to be executed. Currently executing according to our records: " . $node->getCurrentJob(),
                    EventType::NODE, $node->getCurrentJob(), null, $node->getId());
                RepositoryRegistry::getEventRepository()->save($event);
            }

            $filters = [new LegacyJobFilter()];
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

        $specification = JobCdl::specification((int) $job->getId());
        if (($specification['protocol'] ?? null) === ExecutionAttempts::PROTOCOL) {
            throw new Exception('Use the attempt protocol to access this job.');
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

        $settings = $specification['setup'] ?? SystemSettings::getInstance($system->id)->getSection("setup");
        $manifest = CemArtifactSetup::configuredManifest($settings);
        CemArtifactSetup::requireCapability($manifest, $this->get[CemArtifactSetup::CAPABILITY] ?? null);
        $data->setup = (object) CemArtifactSetup::scriptSettings($settings);
        if ($manifest !== null) {
            $data->artifactManifest = $manifest->document();
        }

        $data->cdl = JobCdl::forJob($job, $specification);

        $this->add($data);
    }

    private function getAttempt(Store $db): array
    {
        $node = $this->cemNode($this->get['uniqueId'], $this->get['version'], $this->get['environment'], true, true);
        $attempts = new ExecutionAttempts($db);
        $worker = (string) $node->getId();
        $token = $this->attemptToken();
        $environment = 'cem-' . $node->getEnvironment();
        if (isset($this->get['jobId'])) {
            $id = Input::id($this->get['jobId'], 'jobId');
            $job = $attempts->lockJob($id);
            if ($job['environment'] !== $environment) throw new Exception('The attempt belongs to another environment.');
            $attempt = $attempts->authorize($job, $token, 'cem', $worker, true);
            $claim = ['job' => $job, 'attemptId' => (int) $attempt['attemptId'], 'specification' => JobCdl::specification($id)];
        } else {
            $assigned = $node->getCurrentJob();
            if ($assigned !== null && !(new JobSpecifications($db))->usesAttempts((int) $assigned)) {
                $assignment = $db->row('SELECT status FROM Job WHERE jobId=?', [(int) $assigned]);
                if (in_array((int) ($assignment['status'] ?? -1), [JobStatus::SETUP, JobStatus::RUNNING], true)) {
                    throw new Exception('The CEM node is still running a legacy job.');
                }
            }
            $claim = $attempts->claim($environment, $this->get['adapter'] ?? '', $this->get['supports'] ?? null, 'cem', $worker, $token);
            if ($claim === null) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_NO_JOB_IN_QUEUE);
                throw new Exception('No job in queue!');
            }
            $node->setCurrentJob((int) $claim['job']['jobId']);
            RepositoryRegistry::getNodeRepository()->update($node);
        }
        $specification = $claim['specification'];
        $data = ['job' => ['id' => (int) $claim['job']['jobId'], 'status' => (int) $claim['job']['status']],
            'system' => ['id' => $specification['system']['id']], 'setup' => (object) CemArtifactSetup::scriptSettings($specification['setup']),
            'cdl' => $specification['cdl'], 'protocol' => ExecutionAttempts::PROTOCOL, 'repetitionId' => (int) $claim['job']['jobId'],
            'attemptId' => $claim['attemptId'], 'specification' => (new JobSpecifications($db))->document((int) $claim['job']['jobId'])];
        if (isset($this->get[CemArtifactSetup::CAPABILITY])) {
            $manifest = \Chronos\Domain\Artifacts\ArtifactManifest::parse(json_encode($specification['artifacts'], JSON_THROW_ON_ERROR));
            CemArtifactSetup::requireCapability($manifest, $this->get[CemArtifactSetup::CAPABILITY]);
            $data['artifactManifest'] = $manifest->document();
        }
        return $data;
    }

    public $post_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function post() {
        $database = RepositoryRegistry::getJobRepository()->getDB();
        $database->beginTransaction();
        try {
            $this->postState();
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    private function postState() {
        if (empty($this->get['action'])) throw new Exception('No action provided');
        $action = strtolower($this->get['action']);
        $frozen = $this->attemptProtocol() && !empty($this->request['jobId']);
        $node = $this->cemNode($this->get['uniqueId'], $this->get['version'], $this->get['environment'], true, $frozen);
        $db = new Store(RepositoryRegistry::getJobRepository()->getDB());
        $specifications = new JobSpecifications($db);
        if (in_array($action, ['jobstarted', 'jobterminated', 'joblog'], true)) {
            $id = Input::id($this->request['jobId'] ?? null, 'jobId');
            $row = $db->row('SELECT * FROM Job WHERE jobId=? FOR UPDATE', [$id]);
            if ($row === null) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                throw new Exception('Job does not exist!');
            }
            if ($specifications->usesAttempts($id)) {
                $this->postAttempt($db, $node, $row, $action);
                return;
            }
            if ($this->attemptProtocol()) throw new Exception('This job does not use the attempt protocol.');
            if ($node->getCurrentJob() !== null && $specifications->usesAttempts((int) $node->getCurrentJob())) {
                throw new Exception('The node is assigned to an attempt-managed job.');
            }
        }
        switch ($action) {
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
                // Telemetry must never assign, unassign, or report ownership of
                // a versioned job. Claim and termination own that transition.
                $assigned = $node->getCurrentJob();
                if (($assigned !== null && $specifications->usesAttempts((int) $assigned))
                    || ($currentJob !== null && $specifications->usesAttempts($currentJob))) {
                    if ($currentJob !== null) {
                        $attempts = new ExecutionAttempts($db);
                        $row = $attempts->lockJob($currentJob);
                        $attempts->authorize($row, $this->attemptToken(), 'cem', (string) $node->getId());
                        if ((int) $assigned !== $currentJob || $row['environment'] !== 'cem-' . $node->getEnvironment()) {
                            throw new Exception('This attempt is no longer assigned to the node.');
                        }
                    }
                    $currentJob = $assigned;
                }
                if ($assigned != $currentJob) {
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



    private function postAttempt(Store $db, Node $node, array $job, string $action): void
    {
        $jobId = (int) $job['jobId'];
        if ($job['environment'] !== 'cem-' . $node->getEnvironment() || (int) $node->getCurrentJob() !== $jobId) {
            throw new Exception('This attempt is no longer assigned to the node.');
        }
        $attempts = new ExecutionAttempts($db);
        $attempt = $attempts->authorize($job, $this->attemptToken(), 'cem', (string) $node->getId(), $action === 'jobterminated');
        if ($action === 'jobstarted') {
            if ((int) $job['status'] !== JobStatus::SETUP || $attempt['started'] !== null) {
                throw new Exception('The CEM attempt has already started.');
            }
            $time = date('Y-m-d H:i:s');
            $db->update('JobAttempt', 'attemptId', (int) $attempt['attemptId'], ['started' => $time]);
            $db->update('Job', 'jobId', $jobId, ['started' => $time]);
            return;
        }
        if ($action === 'joblog') {
            $message = $this->request['message'] ?? null;
            if (!is_string($message) || $message === '' || strlen($message) > 1048576) throw new Exception('No or invalid log message provided.');
            (new JobLog(UPLOADED_DATA_PATH))->append($jobId, "\e[34mCEM > " . $message . "\e[0m\n", (int) $attempt['attemptId']);
            return;
        }
        if (in_array((int) $job['status'], [JobStatus::SETUP, JobStatus::RUNNING], true)) {
            $attempts->patch($job, $attempt, ['status' => JobStatus::FAILED,
                'reason' => $this->request['reason'] ?? 'CEM terminated before the agent completed the attempt.']);
        }
        $node->setCurrentJob(null);
        RepositoryRegistry::getNodeRepository()->update($node);
    }

    // Every request needs to contain:
    // - The unique ID of the client
    // - The version of the client
    // - The environment the client is executed in
    private function cemNode(&$uniqueId, &$version, &$environment, bool $lock = false, bool $frozen = false) {
        $uniqueId = trim($uniqueId ?? '');
        if (empty($uniqueId)) {
            throw new Exception('No unique id provided');
        }

        $version = trim($version ?? '');
        if (empty($version)) {
            throw new Exception('No version provided');
        }
        $settings = SystemSettings::getInstance(0);
        $minVersion = $settings->get("cem", "minVersion");
        if ( isset($minVersion) && intval($minVersion->getValue()) > intval($version) ) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_CEM_OUTDATED_VERSION);
            throw new Exception('Outdated version, please update. Minimum required version is: ' . intval($minVersion->getValue()));
        }

        $environment = trim($environment ?? '');
        if (empty($environment)) {
            throw new Exception('No environment provided');
        }
        if (!$frozen) {
            $configured = $settings->get('cem', 'environments');
            $environments = json_decode($configured?->getValue() ?? '[]', true);
            if (!is_array($environments) || !in_array($environment, $environments, true)) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_CEM_UNKNOWN_ENVIRONMENT);
                throw new Exception('Unknown CEM environment: ' . $environment);
            }
        }

        $repository = RepositoryRegistry::getNodeRepository();
        $db = new Store($repository->getDB());
        if ($lock) {
            $db->execute('INSERT INTO Node (nodeId,environment,version,lastUpdate) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nodeId=nodeId',
                [$uniqueId, $environment, $version, date('Y-m-d H:i:s')]);
            $row = $db->row('SELECT * FROM Node WHERE nodeId=? FOR UPDATE', [$uniqueId]);
            $node = new Node($row['nodeId'], $row['environment'], $row['version'], $row['currentJob'], $row['cpu'],
                $row['memoryUsed'], $row['memoryTotal'], $row['hostname'], $row['ip'], $row['os'], $row['healthStatus'], $row['lastUpdate']);
        } else {
            $node = $repository->get($uniqueId);
            if ($node === null) {
                $node = new Node($uniqueId, $environment, $version, null, null, null, null, null, null, null, null, date('Y-m-d H:i:s'));
                $repository->save($node);
            }
        }
        if ($node->getEnvironment() !== $environment || $node->getVersion() !== $version) {
            $db->execute('UPDATE Node SET environment=?,version=? WHERE nodeId=?', [$environment, $version, $uniqueId]);
            $node->setEnvironment($environment);
            $node->setVersion($version);
        }
        return $node;
    }
}
