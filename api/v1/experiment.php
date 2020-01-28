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

class Experiment_API extends API {

    public $runexperiment_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function runexperiment() {
        global $FACTORIES;

        $project = $FACTORIES::getProjectFactory()->get($this->get['createEvaluation']);

        $systems = Systems_Library::getSystems('dbms');
        foreach ($systems as $system) {
            if ($system->id == $project->getSystemId()) {
                $system->wizard();
                break;
            }
        }
    }

    public $patch_access = Auth_Library::A_PUBLIC;

    /**
     * @throws Exception
     */
    public function patch() {
        global $FACTORIES;

        if (empty($this->get['id'])) {
            throw new Exception('No id provided');
        }

        $experiment = $FACTORIES::getExperimentFactory()->get($this->get['id']);
        if (!$experiment) {
            $this->setStatusCode(API::STATUS_NUM_EXPERIMENT_DOES_NOT_EXIST);
            throw new Exception('Experiment does not exist!');
        }

        if (!empty($this->request['name'])) {
            if (strlen($this->request['name']) > 50) {
                throw new Exception("Name cannot be longer than 50 characters!");
            }
            $experiment->setName($this->request['name']);
        }
        if (isset($this->request['description'])) {
            $experiment->setDescription($this->request['description']);
        }
        if (!empty($this->request['deployment'])) {
            $json = json_decode($experiment->getPostData(), true);
            $json['deployment'] = $this->request['deployment'];
            $experiment->setPostData(json_encode($json));
        }
        $FACTORIES::getExperimentFactory()->update($experiment);
    }

}
