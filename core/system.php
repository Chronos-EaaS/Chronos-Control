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

class System {
    private $path;
    private $model;
    private $config;

    const CONFIG = "config.json";
    const PARAMETERS = "parameters.json";
    const RESULTS_ALL = "results.json";
    const RESULTS_JOB = "resultsJob.json";
    const RESULTS = "resultConfigurations.json";

    /**
     * System constructor.
     * @param $systemId
     * @throws Exception
     */
    public function __construct($systemId) {
        $this->model = Factory::getSystemFactory()->get($systemId);
        if ($this->model == null) {
            throw new Exception("Failed to load system with ID $systemId!");
        }
        $this->path = SERVER_ROOT . "/webroot/systems/" . $this->model->getId() . "/";
        if (!file_exists($this->path . "config.json")) {
            throw new Exception("Failed to load config for system with ID " . $this->model->getId());
        }
        $this->config = json_decode(file_get_contents($this->path . System::CONFIG), true);
    }

    public function getName() {
        return $this->config['name'];
    }

    public function getIdentifier() {
        return $this->config['identifier'];
    }

    public function getModel() {
        return $this->model;
    }

    public function supportsFullResults() {
        return $this->getResultsAll() != "{}" || $this->getResultsJob() != "{}";
    }

    /**
     * @param $elements Element[]
     * @throws Exception
     */
    public function getParameterElements(&$elements) {
        $systemElements = Util::scanForElements($this->path . "parameters");
        foreach ($systemElements as $element) {
            $elements[] = $element;
        }
    }

    /**
     * @param $plots Plot[]
     * @throws Exception
     */
    public function getResultPlots($plots) {
        $systemPlots = Util::scanForPlots($this->path . "results");
        foreach ($systemPlots as $plot) {
            $plots[] = $plot;
        }
    }

    public function getAssets() {
        return false;
    }

    public function getFunctions() {
        return false;
    }

    public function getViews() {
        return false;
    }

    public function getParameters() {
        if (!file_exists($this->path . System::PARAMETERS)) {
            return "{}";
        }
        return file_get_contents($this->path . System::PARAMETERS);
    }

    public function getResultsAll($resultId = 0) {
        if (!file_exists($this->path . System::RESULTS)) {
            $this->convertResults();
        }

        $data = file_get_contents($this->path . System::RESULTS);
        if ($resultId != 0) {
            $json = json_decode($data, true);
            $data = json_encode($json["elements"][$resultId]['all']);
        }

        return $data;
    }

    private function convertResults() {
        if (!file_exists($this->path . System::RESULTS_JOB)) {
            $dataJob = "{}";
        } else {
            $dataJob = file_get_contents($this->path . System::RESULTS_JOB);
        }
        if (!file_exists($this->path . System::RESULTS_ALL)) {
            $dataAll = "{}";
        } else {
            $dataAll = file_get_contents($this->path . System::RESULTS_ALL);
        }
        $jsonJob = json_decode($dataJob, true);
        $jsonAll = json_decode($dataAll, true);

        $json = ["version" => "1.0", "elements" => ["system-1" => ["job" => $jsonJob, "all" => $jsonAll, "name" => "Default"]]];
        $this->setResultsAll(json_encode($json));
        unlink($this->path . System::RESULTS_ALL);
        unlink($this->path . System::RESULTS_JOB);
        sleep(1);
    }

    public function getResultsJob($resultId = 0) {
        if (!file_exists($this->path . System::RESULTS)) {
            $this->convertResults();
        }

        $data = file_get_contents($this->path . System::RESULTS);
        if ($resultId != 0) {
            $json = json_decode($data, true);
            $data = json_encode($json["elements"][$resultId]['job']);
        }

        return $data;
    }

    public function setParameters($json) {
        file_put_contents($this->path . System::PARAMETERS, $json);
        VCS_Library::commit($this->path, "Updated system parameters");
    }

    public function setResultsAll($json, $resultId = 0) {
        if ($resultId === 0) {
            file_put_contents($this->path . System::RESULTS, $json);
            VCS_Library::commit($this->path, "Updated result parameters");
        } else {
            $data = json_decode($this->getResultsAll(), true);
            $data['elements'][$resultId]['all'] = json_decode($json, true);
            file_put_contents($this->path . System::RESULTS, json_encode($data));
            VCS_Library::commit($this->path, "Updated result(all) parameters of resultId $resultId");
        }
    }

    public function setResultsJob($json, $resultId = 0) {
        if ($resultId === 0) {
            file_put_contents($this->path . System::RESULTS, $json);
            VCS_Library::commit($this->path, "Updated result parameters");
        } else {
            $data = json_decode($this->getResultsAll(), true);
            $data['elements'][$resultId]['job'] = json_decode($json, true);
            file_put_contents($this->path . System::RESULTS, json_encode($data));
            VCS_Library::commit($this->path, "Updated result(job) parameters of resultId $resultId");
        }
    }

    public function createNewResults($resultId) {
        $data = json_decode($this->getResultsAll(), true);
        $data['elements'][$resultId] = ["job" => [], "all" => [], "name" => substr($resultId, strlen($resultId) - 7, 7)];
        file_put_contents($this->path . System::RESULTS, json_encode($data));
    }
}