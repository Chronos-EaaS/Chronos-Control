<?php

namespace Chronos\Http\Api\V1;

use Chronos\Application\Agents\SupportedSystems;
use Chronos\Application\Shared\Input;
use Chronos\Application\Workflows\{ExecutionAttempts, JobSpecifications};
use Chronos\Infrastructure\Agents\AttemptFiles;
use Chronos\Infrastructure\Persistence\{DatabaseJson, Store};
use Chronos\Infrastructure\Persistence\Query\LegacyJobFilter;
use Chronos\Infrastructure\Realtime\ChangeJournal;
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
        $claim = null;
        $specification = null;
        $db = new Store(RepositoryRegistry::getJobRepository()->getDB());
        $attempts = new ExecutionAttempts($db);
        $repository = RepositoryRegistry::getJobRepository();
        if ($id === 'next' && $this->attemptProtocol()) {
            $claim = $attempts->claimTransaction($this->attemptToken(), fn(): ?array => $attempts->claim(
                'system-' . (trim($this->get['environment'] ?? '') ?: Environment::DEFAULT_NAME),
                $this->get['adapter'] ?? '', $this->get['supports'] ?? null, 'agent',
                $this->get['worker'] ?? '', $this->attemptToken()
            ));
            if ($claim !== null) $job = $repository->get($claim['job']['jobId']);
        } elseif ($id === 'next') {
            if (empty($this->get['supports'])) throw new Exception('No list of supported systems provided!');
            $environment = trim($this->get['environment'] ?? '') ?: Environment::DEFAULT_NAME;
            $identifiers = $this->get['supports'] === 'demoall' ? [] : array_values(array_unique(explode(',', $this->get['supports'])));
            $historical = $identifiers === [] ? [] : SupportedSystems::resolveIdentifiers($identifiers);
            $filters = [new LegacyJobFilter($identifiers, $historical), new QueryFilter(Job::ENVIRONMENT, 'system-' . $environment, '='),
                new QueryFilter(Job::STATUS, JobStatus::SCHEDULED, '=')];
            $job = $repository->filter([RepositoryRegistry::FILTER => $filters], true);
        } else {
            $jobId = Input::id($id, 'id');
            $job = $repository->get($jobId);
            if (!$job) {
                $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
                throw new Exception('Job does not exist!');
            }
            $specification = JobCdl::specification($jobId);
            if (($specification['protocol'] ?? null) === ExecutionAttempts::PROTOCOL) {
                $claim = $db->transaction(function () use ($attempts, $jobId, $specification): array {
                    $row = $attempts->lockJob($jobId);
                    $attempt = $attempts->authorize($row, $this->attemptToken(), terminal: true);
                    return ['job' => $row, 'attemptId' => (int) $attempt['attemptId'], 'specification' => $specification];
                });
            }
        }
        if ($job === null) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_NO_JOB_IN_QUEUE);
            throw new Exception('No job in queue!');
        }
        $specification ??= $claim['specification'] ?? JobCdl::specification((int) $job->getId());

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
        $data->phases = $specification['phases'] ?? intval($job->getPhases());
        $data->currentPhase = intval($job->getCurrentPhase());
        $data->user = intval($user->getId());
        $data->name = $evaluation->getName();
        $data->description = $job->getDescription();
        $data->system = $specification['system']['identifier'] ?? (new SystemDefinition($system->getId()))->getIdentifier();
        $data->environment = $job->getEnvironment();
        $data->cdl = JobCdl::forJob($job, $specification);

        $data->created = $job->getCreated();
        $data->started = $job->getStarted();
        $data->finished = $job->getFinished();

        if ($claim !== null) {
            $data->status = (int) $claim['job']['status'];
            $data->progress = (int) $claim['job']['progress'];
            $data->protocol = ExecutionAttempts::PROTOCOL;
            $data->repetitionId = (int) $job->getId();
            $data->attemptId = $claim['attemptId'];
            $data->specification = (new JobSpecifications($db))->document((int) $job->getId());
        }
        $this->add($data);
    }

    public $post_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function post() {
        $db = new Store(RepositoryRegistry::getJobRepository()->getDB());
        $db->transaction(fn() => $this->postJob($db));
    }

    private function postJob(Store $db): void {
        [$job, $attempt] = $this->lockExecution($db);
        $jobId = (int) $job['jobId'];
        switch (strtolower($this->get['action'] ?? '')) {
            case 'getuploadtarget':
                $ftp = SystemSettings::getInstance(0)->get('ftp', 'useFtpUploadForLocalClients')?->getValue();
                if ($attempt === null && $ftp && IpAddress::cidrMatch($_SERVER['REMOTE_ADDR'], SystemSettings::getInstance(0)->get('ftp', 'localNetworkCIDR')->getValue())) {
                    $this->add($this->getFtpUploadTarget($jobId));
                } else {
                    $target = $this->getHttpUploadTarget();
                    if ($attempt !== null) {
                        $target->path .= '/id=' . $jobId . '/action=upload/protocol=' . ExecutionAttempts::PROTOCOL;
                    }
                    $this->add($target);
                }
                break;
            case 'appendlog':
                $log = $this->request['log'] ?? null;
                if (!is_string($log) || $log === '' || ($attempt !== null && strlen($log) > 1048576)) {
                    throw new Exception('No or insufficient log data provided.');
                }
                (new JobLog(UPLOADED_DATA_PATH))->append($jobId, $log, $attempt === null ? null : (int) $attempt['attemptId']);
                break;
            case 'upload':
                $this->upload($db, $jobId, $attempt);
                break;
            default:
                throw new Exception('Unsupported action');
        }
    }

    private function lockExecution(Store $db): array {
        $id = Input::id($this->get['id'] ?? null, 'id');
        $attempts = new ExecutionAttempts($db);
        $job = $db->row('SELECT * FROM Job WHERE jobId=? FOR UPDATE', [$id]);
        if ($job === null) {
            $this->setStatusCode(AgentResponse::STATUS_NUM_JOB_DOES_NOT_EXIST);
            throw new Exception('Job does not exist!');
        }
        $versioned = (new JobSpecifications($db))->usesAttempts($id);
        if (!$versioned && $this->attemptProtocol()) throw new Exception('This job does not use the attempt protocol.');
        return [$job, $versioned ? $attempts->authorize($job, $this->attemptToken()) : null];
    }

    public $patch_access = Authentication::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function patch() {
        $db = new Store(RepositoryRegistry::getJobRepository()->getDB());
        $db->transaction(function () use ($db): void {
            [$row, $attempt] = $this->lockExecution($db);
            if ($attempt !== null) {
                (new ExecutionAttempts($db))->patch($row, $attempt, $this->request);
                return;
            }
            $this->patchLegacy((int) $row['jobId']);
        });
    }

    private function patchLegacy(int $jobId): void {
        $auth = Authentication::getInstance();
        $job = RepositoryRegistry::getJobRepository()->get($jobId);
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
     * @throws Exception
     */
    private function upload(Store $db, int $jobId, ?array $attempt): void {
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

        $tmp = $_FILES[$fileUploadName]['tmp_name'];
        if ($attempt === null) {
            $filename = rtrim(UPLOADED_DATA_PATH, '/') . '/evaluation/' . $jobId . '.zip';
            if (!move_uploaded_file($tmp, $filename)) throw new Exception('Failed to store the uploaded file.');
        } else {
            $attemptId = (int) $attempt['attemptId'];
            $size = filesize($tmp);
            $digest = hash_file('sha256', $tmp);
            if ($size === false || $digest === false) throw new Exception('Failed to read the uploaded archive.');
            $identity = ['sha256' => $digest, 'sizeBytes' => $size];
            $filename = AttemptFiles::archive(UPLOADED_DATA_PATH, $attemptId, $digest);
            $archive = DatabaseJson::encode($identity, 'archive');
            if ($attempt['archive'] !== null) {
                if ($attempt['archive'] !== $archive) throw new Exception('This attempt already has a different archive.');
                AttemptFiles::readableArchive(UPLOADED_DATA_PATH, $attemptId, $identity);
                return;
            }
            $directory = dirname($filename);
            if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0770) && !is_dir($directory))
                || is_link($filename) || !move_uploaded_file($tmp, $filename)) {
                throw new Exception('Failed to store the attempt archive.');
            }
            $db->update('JobAttempt', 'attemptId', $attemptId, ['archive' => $archive]);
        }
        ChangeJournal::fileChanged(SERVER_ROOT, 'JobResults', (string) $jobId);
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
        $data->path = '/chronos/evaluation/';
        $data->filename = $id . '.zip';
        return $data;
    }

    /**
     * @param $id
     * @return stdClass
     * @throws Exception
     */
    private function getHttpUploadTarget(): stdClass {
        // HTTP Config
        $data = new stdClass();
        $data->method = 'http';
        $data->path = '/api/v1/job';
        $data->hostname = SystemSettings::getInstance(0)->get('other', 'uploadedDataHostname')?->getValue() ?? '';
        return $data;
    }

}
