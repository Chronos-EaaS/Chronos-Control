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

use DBA\Factory;
use DBA\Job;
use DBA\System;
use DBA\QueryFilter;
use DBA\ContainFilter;

class CEM_API extends API {

    public $get_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function get() {
        $session = $this->cemSession($this->get['uniqueId'], $this->get['version'], $this->get['environment']);

        $job = null;
        // Request specific job by its id
        if (!empty($this->get['jobId'])) {
            $jobId = trim($this->get['jobId']);
            if (is_numeric($jobId)) {
                $job = Factory::getJobFactory()->get($jobId);
                if (!$job) {
                    $this->setStatusCode(API::STATUS_NUM_JOB_DOES_NOT_EXIST);
                    throw new Exception('Job does not exist!');
                }
            } else {
                throw new Exception('Invalid job id!');
            }
        } else { // request next job to be executed
            $filters = [];
            $filters[] = new QueryFilter(Job::ENVIRONMENT, $session->environment, "=");
            $filters[] = new QueryFilter(Job::STATUS, Define::JOB_STATUS_SCHEDULED, "=");

            // Get all systems supporting automated setup
            $sysFilters = [];
            $sysFilters[] = new QueryFilter(System::AUTOMATED_SETUP, 1, "=");
            $sys = Factory::getSystemFactory()->filter([Factory::FILTER => $sysFilters]);
            $supports = [];
            foreach ($sys as $s) {
                $supports[] = $s->getId();
            }
            $filters[] = new ContainFilter(Job::SYSTEM_ID, $supports);

            $job = Factory::getJobFactory()->filterWithTimeout([Factory::FILTER => $filters], 60,true);
            if (!$job) {
                $this->setStatusCode(API::STATUS_NUM_NO_JOB_IN_QUEUE);
                $this->setError('No job in queue!');
                exit();
            }
        }


        // Change to int's
        $data = new stdClass();

        $jobData = new stdClass();
        $jobData->id = intval($job->getId());
        $jobData->status = intval($job->getStatus());
        $data->job = $jobData;

        $system = new stdClass();
        $sys = Factory::getSystemFactory()->get($job->getSystemId());
        $system->id = $sys->getId();
        $data->system = $system;

        $setup = new stdClass();
        $settings = Settings_Library::getInstance($system->id)->getSection("setup");
        foreach ($settings as $key => $value) {
            $setup->$key = $value;
        }
        $data->setup = $setup;

        $data->cdl = Util::jobToCDL($job);

        $this->add($data);
    }

    public $post_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function post() {

    }



    // Every request needs to contain:
    // - The unique ID of the client
    // - The version of the client
    // - The environment the client is executed in
    private function cemSession(&$uniqueId, &$version, &$environment) {
        $uniqueId = trim($uniqueId);
        if (empty($uniqueId)) {
            throw new Exception('No unique id provided');
        }
        // TODO: Check if there is a state change for this client (e.g., pause)

        $version = trim($version);
        if (empty($version)) {
            throw new Exception('No version provided');
        }
        // TODO: Check if version is sufficient, if not, return status code

        $environment = trim($environment);
        if (empty($environment)) {
            throw new Exception('No environment provided');
        }
        // TODO: Check if this is a known environment, if not, return status code

        $session = new stdClass();
        $session->uniqueId = $uniqueId;
        $session->version = $version;
        $session->environment = $environment;

        return $session;
    }

}
