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

class EvaluationUI_API extends API {

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
                    $qF = new QueryFilter(Job::STATUS, Define::JOB_STATUS_FINISHED, "=");
                    $finishedJobs = $FACTORIES::getJobFactory()->countFilter([$FACTORIES::FILTER => $qF]);
                    $this->addData('finishedJobs', $finishedJobs);
            }
        }
        $data = $evaluation->getKeyValueDict();
        $this->add($data);
    }

}
