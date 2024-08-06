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

use DBA\Experiment;
use DBA\Job;
use DBA\Factory;
use DBA\QueryFilter;
use DBA\Evaluation;

class Results_Library {
    private $json = [];
    private $system;
    private $resultId;

    const TYPE_ALL = 1;
    const TYPE_JOB = 2;
    const TYPE_EVAL = 3;

    /**
     * Builder_Library constructor.
     * @param $system System
     */
    public function __construct($system, $resultId) {
        $this->system = $system;
        $this->resultId = $resultId;
        $this->json[Results_Library::TYPE_ALL] = json_decode($system->getResultsAll(), true)['elements'][$resultId]['all'];
        $this->json[Results_Library::TYPE_JOB] = json_decode($system->getResultsJob(), true)['elements'][$resultId]['job'];

        $evalJson = json_decode($system->getResultsEval(), true);
        if(isset($evalJson['elements'][$resultId]['eval'])) {
            $this->json[Results_Library::TYPE_EVAL] = $evalJson['elements'][$resultId]['eval'];
        }
        else {
            $this->json[Results_Library::TYPE_EVAL] = [];
        }
    }

    /**
     * @param $identifier
     * @return Plot
     * @throws Exception
     */
    public function getElementFromIdentifier($identifier) {
        $plots = Util::getDefaultResultPlots();
        $this->system->getResultPlots($plots);
        foreach ($plots as $plot) {
            if ($plot->getType() == $identifier) {
                return $plot;
            }
        }
        return null;
    }

    /**
     * This function generates the html for the current built system structure for the UI builder.
     * @param $type int
     * @return string
     * @throws Exception
     */
    public function buildContent($type) {
        $content = "";
        foreach ($this->json[$type] as $p) {
            $element = $this->getElementFromIdentifier($p['type']);
            if ($element === null) {
                continue;
            }
            $template = $element->getBuildTemplate();
            $content .= $template->render($p);
        }
        return $content;
    }

    public static function colorToRGBA($color, $opacity = false) {
        $default = 'rgb(0,0,0)';

        if (empty($color)) {
            return $default;
        } else if ($color[0] == '#') {
            $color = substr($color, 1);
        }

        if (strlen($color) == 6) {
            $hex = [$color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5]];
        } else if (strlen($color) == 3) {
            $hex = [$color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2]];
        } else {
            return $default;
        }

        $rgb = array_map('hexdec', $hex);

        if ($opacity) {
            if (abs($opacity) > 1) {
                $opacity = 1.0;
            }
            $output = 'rgba(' . implode(",", $rgb) . ',' . $opacity . ')';
        } else {
            $output = 'rgb(' . implode(",", $rgb) . ')';
        }
        return $output;
    }

    /** source: https://stackoverflow.com/questions/19210066/how-do-i-get-box-plot-key-numbers-from-an-array-in-php */
    public static function boxPlotCalculation($values) {
        $result = [
            'lower_outlier' => 0,
            'min' => 0,
            'q1' => 0,
            'median' => 0,
            'q3' => 0,
            'max' => 0,
            'higher_outlier' => 0,
        ];

        $numValues = count($values);
        sort($values, SORT_NUMERIC);

        $result['min'] = $values[0];
        $result['lower_outlier'] = $result['min'];
        $result['max'] = $values[$numValues - 1];
        $result['higher_outlier'] = $result['max'];
        $middleIndex = floor($numValues / 2);
        $result['median'] = $values[$middleIndex];
        $lowerValues = [];
        $higherValues = [];

        if ($numValues % 2 == 0) { // even number of values
            $result['median'] = round(($result['median'] + $values[$middleIndex - 1]) / 2);
            foreach ($values as $index => $value) {
                if ($index < ($middleIndex - 1)) {
                    $lowerValues[] = $value;
                } else if ($index > $middleIndex) {
                    $higherValues[] = $value;
                }
            }
        } else {
            foreach ($values as $index => $value) {
                if ($index < $middleIndex) {
                    $lowerValues[] = $value;
                } else if ($index > $middleIndex) {
                    $higherValues[] = $value;
                }
            }
        }

        $numLowerValues = count($lowerValues);
        $lowerMiddleIndex = floor($numLowerValues / 2);
        $result['q1'] = $lowerValues[$lowerMiddleIndex];
        if ($numLowerValues % 2 == 0) {
            $result['q1'] = round(($result['q1'] + $lowerValues[$lowerMiddleIndex - 1]) / 2);
        }

        $numHigherValues = count($higherValues);
        $higherMiddleIndex = floor($numHigherValues / 2);
        $result['q3'] = $higherValues[$higherMiddleIndex];
        if ($numHigherValues % 2 == 0) {
            $result['q3'] = round(($result['q3'] + $higherValues[$higherMiddleIndex - 1]) / 2);
        }

        // Check if min and max should be capped
        $iqr = $result['q3'] - $result['q1'];
        if ($result['q1'] > $iqr) {
            $result['min'] = $result['q1'] - $iqr;
        }
        if ($result['max'] - $result['q3'] > $iqr) {
            $result['max'] = $result['q3'] + $iqr;
        }

        return $result;
    }

    public function getPlotTypeAll() {
        return $this->json[Results_Library::TYPE_ALL];
    }

    public function getPlotTypeJob() {
        return $this->json[Results_Library::TYPE_JOB];
    }
    public function getPlotTypeEval() {
        return $this->json[Results_Library::TYPE_EVAL];
    }

    /**
     * @param $jobs Job[][]
     * @param $view View
     * @param $numFinishedJobs
     * @param $evaluationId
     * @return string
     * @throws Exception
     */
    public function buildResults($jobs, $view, $numFinishedJobs, $evaluationId) {
        $content = "";
        $dataObjects = ['plots' => []];
        $view->includeInlineJS("
            var finishedJobs = $numFinishedJobs;
            $( document ).ready(function() {
                setInterval(function(){
                    $.ajax({
                        url : '/api/ui/evaluation/id=" . $evaluationId . "/action=countFinishedJobs',
                        type : 'GET',
                        dataType: 'json',
                        success: function (data) {
                            if(data.status.code == 200){
                                if(data.response.finishedJobs != finishedJobs){
                                    finishedJobs = data.response.finishedJobs;
                                    updatePlots();
                                }
                            }
                        }
                    });
                }, 5000);
            });
            
            function updatePlots(){
                $.each(plots, function(index, value) {
                    $.ajax({
                        url : '/api/ui/evaluation/id=" . $evaluationId . "/action=getPlotData/plotId=' + value,
                        type : 'GET',
                        dataType: 'json',
                        success: function (data) {
                            if(data.status.code == 200){
                                window['config' + value].data = data.response.plotData;
                                if(window['chart' + value] === undefined){
                                    window[name]();
                                }
                                else{
                                    window['chart' + value].update();
                                }
                            }
                        }
                    });
                    var name = 'plot' + value;
                });
            }
        ");

        $evalResults = $this->json[Results_Library::TYPE_EVAL];
        if(!empty($evalResults)) {
            # First get the evaluation
            $evaluation = Factory::getEvaluationFactory()->get($evaluationId);
            # Not needed? would allow to assign $experiment $qF1 = new QueryFilter(Experiment::EXPERIMENT_ID, $evaluation->getExperimentId(), "=");
            $qF2 = new QueryFilter(Evaluation::IS_ARCHIVED, 0, "=");
            $qF3 = new QueryFilter(Evaluation::EXPERIMENT_ID, $evaluation->getExperimentId(), "=");
            # Not needed? $experiment = Factory::getExperimentFactory()->filter([Factory::FILTER => [$qF1]]);
            $evaluations = Factory::getEvaluationFactory()->filter([Factory::FILTER => [$qF2, $qF3]]);

            foreach ($this->json[Results_Library::TYPE_EVAL] as $p) {
                $wrapperTemplate = new Template("builder/plotbox");
                $plot = $this->getElementFromIdentifier($p['type']);  # $plot ist 'bar-plot'

                foreach ($plot->getRequired() as $required) {
                    $view->includeAsset($required);
                }
                foreach ($evaluations as $evaluation) {
                    $qFJobs = new QueryFilter(Job::EVALUATION_ID, $evaluation->getId(), "=");
                    $evaluationJobs = Factory::getJobFactory()->filter([Factory::FILTER => [$qFJobs]]);
                    $groupedJobs = [];
                    foreach ($evaluationJobs as $job) {
                        if (!isset($groupedJobs[$job->getConfigurationIdentifier()])) {
                            $groupedJobs[$job->getConfigurationIdentifier()] = [];
                        }
                        $groupedJobs[$job->getConfigurationIdentifier()][] = $job;
                    }
                    # Data to be plotted. Changed to be one per evaluation. process() and render() dont support this yet
                    $p['plotData'] = $plot->process($groupedJobs, $p);
                    print_r($p['plotData']);
                    # Vorerst auf min, max, avg verzichten und nur pro evaluation den average nehmen?
                    #$p['plotData'] = average($plot->process($groupedJobs, $p));
                    #$p['plotData'][$evaluation->getName()] = $plot->process($groupedJobs, $p);
                }
                $template = $plot->getRenderTemplate();
                $p['plotId'] = str_replace("-", "", $p['id']);
                $dataObjects['plots'][] = $p['plotId'];
                $plotContent = "<div class='col-sm-12'>" . $template->render($p) . "</div>";
                $view->includeInlineJS("plot" . $p['plotId'] . "();");
                $content .= $wrapperTemplate->render(['plotData' => $plotContent, 'title' => $p['name']]);
            }
        }

        foreach ($this->json[Results_Library::TYPE_ALL] as $p) {
            $wrapperTemplate = new Template("builder/plotbox");
            $plot = $this->getElementFromIdentifier($p['type']);
            $template = $plot->getRenderTemplate();
            $p['plotData'] = $plot->process($jobs, $p);
            $p['plotId'] = str_replace("-", "", $p['id']);
            $dataObjects['plots'][] = $p['plotId'];
            $plotContent = "<div class='col-sm-12'>" . $template->render($p) . "</div>";
            foreach ($plot->getRequired() as $required) {
                $view->includeAsset($required);
            }
            $view->includeInlineJS("plot" . $p['plotId'] . "();");
            $content .= $wrapperTemplate->render(['plotData' => $plotContent, 'title' => $p['name']]);
        }

        foreach ($jobs as $job) {
            $wrapperTemplate = new Template("builder/plotbox");
            $wrapperContent = "";
            if (!is_array($job[0]->getConfiguration())) { // only decode if needed
                $job[0]->setConfiguration(json_decode($job[0]->getConfiguration(), TRUE));
            }
            $title = $job[0]->getConfiguration()[Define::CONFIGURATION_TITLE];
            foreach ($this->json[Results_Library::TYPE_JOB] as $p) {
                $plot = $this->getElementFromIdentifier($p['type']);
                $template = $plot->getRenderTemplate();
                $p['plotData'] = $plot->process([$job], $p);
                $p['plotId'] = str_replace("-", "", $p['id']) . $job[0]->getInternalId();
                $dataObjects['plots'][] = $p['plotId'];
                $wrapperContent .= "<div class='col-sm-6'><h5>" . $p['name'] . "</h5>" . $template->render($p) . "</div>";
                foreach ($plot->getRequired() as $required) {
                    $view->includeAsset($required);
                }
                $view->includeInlineJS("plot" . $p['plotId'] . "();");
            }
            if (sizeof($this->json[Results_Library::TYPE_JOB]) > 0) {
                $content .= $wrapperTemplate->render(['plotData' => $wrapperContent, 'title' => $title]);
            }
        }
        $dataTemplate = new Template("builder/data");
        $dataObjects['plots'] = json_encode($dataObjects['plots']);
        return $content . $dataTemplate->render($dataObjects);
    }
}