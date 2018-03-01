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

class __NAME___System extends System {
    /**
     * @throws Exception
     */
    public function wizard() {
        global $FACTORIES;

        $this->view->setTemplate(null);
        if (!empty($this->postData) && sizeof($this->postData) > 0) {
            $this->runGeneration($this->postData);
        }
        $this->view->assign('projectId', $this->projectId);
        $builder = new Builder_Library($FACTORIES::getSystemFactory()->get($FACTORIES::getProjectFactory()->get($this->projectId)->getSystemId()));
        $this->view->assign('content', $builder->buildExperiment());
    }

    public function runGeneration($json) {
        // TODO: more general
        for ($threads = $json['executor_executors_min']; $threads <= $json['executor_executors_max']; $threads = $threads + $json['executor_executors_steps']) {
            $cdl = $this->newCDL();
            $eval = $cdl->getEvaluation();
            $eval->appendChild($cdl->createElement("threads", $threads));
            $this->addEvaluationJob("$threads Threads", $cdl);
        }
    }

    public function evaluationResults() {
        $minRuntime = null;
        $minJob = null;
        $runtimes = array();
        $queryTypes = array();
        foreach ($this->app->evaluation->jobs as $job) {
            $result = json_decode($job->getResult());
            if (!empty($result->queryTypes_maxId)) {
                $maxId = $result->queryTypes_maxId;
                for ($i = 1; $i <= $maxId; $i++) {
                    $meanName = "queryTypes_" . $i . "_mean";
                    $exampleName = "queryTypes_" . $i . "_example";
                    $allName = "queryTypes_" . $i . "_all";
                    $queryTypes[$i][$job->getInternalId()]["mean"] = $result->$meanName;
                    $queryTypes[$i][$job->getInternalId()]["example"] = $result->$exampleName;
                    $queryTypes[$i][$job->getInternalId()]["all"] = $result->$allName;
                }
            }
            $runtimes[$job->getInternalId()] = $result->{'internal.durations.execute'};
            if ($minRuntime == null || $result->{'internal.durations.execute'} < $minRuntime) {
                $minRuntime = $result->{'internal.durations.execute'};
                $minJob = $job->getInternalId();
            }
        }
        $this->view->assign('fastestJob', $minJob);
        $this->view->assign('fastestJobRuntime', $minRuntime);
        $this->view->assign('runtimes', $runtimes);
        $this->view->assign('queryTypes', $queryTypes);
    }
}