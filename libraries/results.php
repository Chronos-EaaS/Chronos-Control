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

class Results_Library {
    private $json = [];
    private $system;
    private $resultId;

    const TYPE_ALL = 1;
    const TYPE_JOB = 2;

    /**
     * Builder_Library constructor.
     * @param $system System
     */
    public function __construct($system, $resultId) {
        $this->system = $system;
        $this->resultId = $resultId;
        $this->json[Results_Library::TYPE_ALL] = json_decode($system->getResultsAll(), true)['elements'][$resultId]['all'];
        $this->json[Results_Library::TYPE_JOB] = json_decode($system->getResultsJob(), true)['elements'][$resultId]['job'];
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

    /** Return a finite numeric observation; missing values are never zero samples. */
    public static function numericObservation($value) {
        // Existing metric arithmetic and floatval treated boolean results as
        // numeric flags. Preserve that capability; false is not missing data.
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? $number : null;
    }

    public static function parameterNames($parameter) {
        $names = is_array($parameter) ? $parameter : explode(",", $parameter);
        return array_values(array_unique(array_filter(array_map('trim', $names), static function ($name) {
            return $name !== "";
        })));
    }

    private static function jobResults($job) {
        $results = $job->getResult();
        if (is_string($results)) {
            $results = json_decode($results, true);
        }
        return is_array($results) ? $results : [];
    }

    public static function metricObservations($jobs, $parameter) {
        $values = [];
        foreach ($jobs as $job) {
            $results = self::jobResults($job);
            $number = self::numericObservation($results[$parameter] ?? null);
            if ($number !== null) {
                $values[] = $number;
            }
        }
        return $values;
    }

    public static function mean($values) {
        if (count($values) === 0) {
            return null;
        }
        // Scale before a compensated sum: avoid overflow and retain small
        // observations when large positive/negative values cancel each other.
        $scale = max(array_map('abs', $values));
        if ($scale == 0) {
            return 0.0;
        }
        $sum = 0.0;
        $correction = 0.0;
        foreach ($values as $value) {
            $scaled = $value / $scale;
            $next = $sum + $scaled;
            $correction += abs($sum) >= abs($scaled)
                ? ($sum - $next) + $scaled : ($scaled - $next) + $sum;
            $sum = $next;
        }
        $normalizedMean = ($sum + $correction) / count($values);
        return max(-1.0, min(1.0, $normalizedMean)) * $scale;
    }

    private static function arrayMetric($value) {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    public static function hasArrayMetric($groups, $parameter) {
        $hasEmptyArray = false;
        $hasScalar = false;
        foreach ($groups as $group) {
            foreach ($group as $job) {
                $results = self::jobResults($job);
                $value = $results[$parameter] ?? null;
                $array = self::arrayMetric($value);
                if ($array !== null) {
                    if (count($array) > 0) {
                        return true;
                    }
                    $hasEmptyArray = true;
                } else if (self::numericObservation($value) !== null) {
                    $hasScalar = true;
                }
            }
        }
        // An empty result list must not erase otherwise valid scalar samples.
        return $hasEmptyArray && !$hasScalar;
    }

    /** Average each array position over only its finite, present observations. */
    public static function arrayMetricMeans($jobs, $parameter) {
        $positions = [];
        foreach ($jobs as $job) {
            $results = self::jobResults($job);
            $values = self::arrayMetric($results[$parameter] ?? null);
            if ($values === null) {
                continue;
            }
            foreach ($values as $index => $value) {
                if (!isset($positions[$index])) {
                    $positions[$index] = [];
                }
                $number = self::numericObservation($value);
                if ($number !== null) {
                    $positions[$index][] = $number;
                }
            }
        }
        return array_map([self::class, 'mean'], $positions);
    }

    public static function groupLabel($jobs, $changingParameters, $jobParameters, $excluded = []) {
        if (count($jobs) === 0) {
            return "Jobs[]";
        }
        $parameters = $jobParameters[$jobs[0]->getId()] ?? [];
        $label = [];
        foreach ($changingParameters as $parameter) {
            if ($parameter === 'run' || in_array($parameter, $excluded, true)) {
                continue;
            }
            $label[] = $parameter . ": " . ($parameters[$parameter] ?? '(missing)');
        }
        return "Jobs[" . implode(", ", $label) . "]";
    }

    private static function median($sortedValues) {
        $count = count($sortedValues);
        $middle = intdiv($count, 2);
        if ($count % 2 !== 0) {
            return $sortedValues[$middle];
        }
        $left = $sortedValues[$middle - 1];
        $right = $sortedValues[$middle];
        return $left < 0 && $right > 0
            ? $left / 2 + $right / 2 : $left + ($right - $left) / 2;
    }

    /**
     * Tukey median-of-halves: exclude an odd sample's median from both halves.
     * Use 1.5 IQR fences and actual in-fence observations for the whiskers.
     * min/max and legacy *_outlier keys retain the observed sample extrema;
     * explicit whisker/outlier fields are understood by the existing renderer.
     */
    public static function boxPlotCalculation($values) {
        $values = array_values(array_filter(array_map([self::class, 'numericObservation'], $values), static function ($value) {
            return $value !== null;
        }));
        if (count($values) === 0) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        $lower = array_slice($values, 0, $middle);
        $upper = array_slice($values, $middle + $count % 2);
        $q1 = count($lower) > 0 ? self::median($lower) : $values[0];
        $q3 = count($upper) > 0 ? self::median($upper) : $values[0];
        $iqr = $q3 - $q1;
        $lowerFence = $q1 - 1.5 * $iqr;
        $upperFence = $q3 + 1.5 * $iqr;
        $inliers = [];
        $outliers = [];
        foreach ($values as $value) {
            if ($value < $lowerFence || $value > $upperFence) {
                $outliers[] = $value;
            } else {
                $inliers[] = $value;
            }
        }
        return [
            'lower_outlier' => $values[0],
            'min' => $values[0],
            'q1' => $q1,
            'median' => self::median($values),
            'q3' => $q3,
            'max' => $values[$count - 1],
            'higher_outlier' => $values[$count - 1],
            'whiskerMin' => $inliers[0],
            'whiskerMax' => $inliers[count($inliers) - 1],
            'outliers' => $outliers,
        ];
    }

    public function getPlotTypeAll() {
        return $this->json[Results_Library::TYPE_ALL];
    }

    public function getPlotTypeJob() {
        return $this->json[Results_Library::TYPE_JOB];
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