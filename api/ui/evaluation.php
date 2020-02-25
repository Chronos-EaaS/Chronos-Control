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

use DBA\Job;
use DBA\QueryFilter;

class Evaluation_API extends API {

    public $get_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function get() {
        global $FACTORIES;

        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }

        $evaluation = $FACTORIES::getEvaluationFactory()->get($this->get['id']);
        if (!$evaluation) {
            $this->setStatusCode(API::STATUS_NUM_EVALUATION_DOES_NOT_EXIST);
            throw new Exception('Evaluation does not exist!');
        }

        if (!empty($this->get['action'])) {
            switch ($this->get['action']) {
                case 'countFinishedJobs':
                    // retrieve how many jobs are finished
                    $qF1 = new QueryFilter(Job::STATUS, Define::JOB_STATUS_FINISHED, "=");
                    $qF2 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                    $finishedJobs = $FACTORIES::getJobFactory()->countFilter([$FACTORIES::FILTER => [$qF1, $qF2]]);
                    $this->addData('finishedJobs', $finishedJobs);
                    break;
                case 'getPlotData':
                    $plotId = $this->get['plotId'];
                    $system = new System($evaluation->getSystemId());
                    $resultsLibrary = new Results_Library($system);
                    $qF1 = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                    $qF2 = new QueryFilter(Job::STATUS, Define::JOB_STATUS_FINISHED, "=");
                    $jobs = $FACTORIES::getJobFactory()->filter([$FACTORIES::FILTER => [$qF1, $qF2]]);

                    // group jobs with same settings together
                    $groupedJobs = [];
                    foreach ($jobs as $job) {
                        if (!isset($groupedJobs[$job->getConfigurationIdentifier()])) {
                            $groupedJobs[$job->getConfigurationIdentifier()] = [];
                        }
                        $groupedJobs[$job->getConfigurationIdentifier()][] = $job;
                    }

                    $data = [];
                    foreach ($groupedJobs as $job) {
                        foreach ($resultsLibrary->getPlotTypeJob() as $p) {
                            if (str_replace("-", "", $p['id']) == $plotId) {
                                $plot = $resultsLibrary->getElementFromIdentifier($p['type']);
                                $data = json_decode($plot->process([$job], $p), true);
                            }
                        }
                    }
                    foreach ($resultsLibrary->getPlotTypeAll() as $p) {
                        if (str_replace("-", "", $p['id']) == $plotId) {
                            $plot = $resultsLibrary->getElementFromIdentifier($p['type']);
                            $data = json_decode($plot->process($groupedJobs, $p), true);
                        }
                    }
                    $this->addData('plotData', $data);
                    return;
            }
        }
        $data = $evaluation->getKeyValueDict();
        $this->add($data);
    }

}
