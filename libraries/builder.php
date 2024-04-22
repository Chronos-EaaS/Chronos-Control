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

class Builder_Library {
    private $json;
    private $system;

    /**
     * Builder_Library constructor.
     * @param $system System
     */
    public function __construct($system) {
        $this->system = $system;
        $this->json = json_decode($system->getParameters(), true);
    }

    /**
     * @param $data
     * @param $allConfigurations
     * @throws Exception
     */
    public static function apply_runs($data, &$allConfigurations) {
        $newConfigurations = [];
        if (intval($data["runs"]) <= 0) {
            throw new Exception("Invalid run number!");
        }
        for ($i = 0; $i < intval($data["runs"]); $i++) {
            foreach ($allConfigurations as $configuration) {
                $copy = $configuration;
                $copy[Define::CONFIGURATION_PARAMETERS]["run"] = $i;
                $newConfigurations[] = $copy;
            }
        }
        $allConfigurations = $newConfigurations;
    }

    /**
     * @param $identifier
     * @return Element
     * @throws Exception
     */
    public function getElementFromIdentifier($identifier) {
        $elements = Util::getDefaultParameterElements();
        $this->system->getParameterElements($elements);
        foreach ($elements as $element) {
            if ($element->getType() == $identifier) {
                return $element;
            }
        }
        return null;
    }

    /**
     * This function generates the html for the current built system structure for the UI builder.
     * @return string
     * @throws Exception
     */
    public function buildContent() {
        $content = "";
        foreach ($this->json as $group) {
            $c = "";
            foreach ($group['elements'] as $e) {
                $element = $this->getElementFromIdentifier($e['type']);
                if ($element === null) {
                    continue;
                }
                if (strlen($c) > 0) {
                    $c .= "<hr>";
                }
                $template = $element->getBuildTemplate();
                $c .= $template->render($this->escapeArrayValues($e));
            }

            $template = new Template("builder/group");
            $obj = ['title' => $group['title'], 'id' => $group['id'], 'depends' => $group['depends'], 'dependsValue' => $group['dependsValue'], 'content' => $c];
            $content .= $template->render($obj);
        }
        return $content;
    }

    public function escapeArrayValues($arr) {
        $escaped = [];
        foreach ($arr as $key => $val) {
            if (is_array($val)) {
                $escaped[$key] = $this->escapeArrayValues($val);
            } else {
                # Added this if statement because, creating the first experiment (on devl stage)
                # throws:  htmlentities(): Passing null to parameter #1 ($string) of type string is deprecated in /usr/local/web/chronos/libraries/builder.php on line 111
                if($val == null) {
                    $val = "";
                }
                $escaped[$key] = htmlentities($val, ENT_QUOTES, "UTF-8");
            }
        }
        return $escaped;
    }

    /**
     * @array string
     * @throws Exception
     */
    public function buildExperiment($copyData = []) {
        $content = "";
        $js = "";
        foreach ($this->json as $group) {
            $c = "";
            foreach ($group['elements'] as $e) {
                if (strlen($c) > 0) {
                    $c .= "<hr>";
                }
                $element = $this->getElementFromIdentifier($e['type']);
                $template = $element->getRenderTemplate();
                $allData = $e;
                $copyValue = $element->copyValue($copyData, $e);

                $allData['copy'] = $copyValue;
                $c .= $template->render($this->escapeArrayValues($allData));
            }

            if ($group['depends'] == "") {
                $template = new Template("experiment/group");
                $obj = ['title' => $group['title'], 'id' => $group['id'], 'content' => $c];
                $content .= $template->render($obj);
            } else {
                $template = new Template("experiment/dependentGroup");
                $obj = ['title' => $group['title'], 'id' => $group['id'], 'depends' => $group['depends'], 'dependsValue' => $group['dependsValue'], 'content' => $c];
                $content .= $template->render($obj);
                $js .= "
                $(\"[name='" . $group['depends'] . "']\").change(function(){
                    if ($(\"[name='" . $group['depends'] . "']\").is(':disabled')) {
                        $(\"#" . $group['id'] . " :input\").prop('disabled',true);
                        $(\"#" . $group['id'] . "\").hide();
                    } else {
                        if ($(\"[name='" . $group['depends'] . "']\").val() == \"" . $group['dependsValue'] . "\") {
                            $(\"#" . $group['id'] . " :input\").prop('disabled',false);
                            $(\"#" . $group['id'] . "\").show();
                        } else {
                            $(\"#" . $group['id'] . " :input\").prop('disabled',true);
                            $(\"#" . $group['id'] . "\").hide();
                        }
                    }
                });
                
                // Set up a new observer for disable events
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        // Check the modified attributeName is disabled
                        if(mutation.attributeName === \"disabled\") {
                            if ($(\"[name='" . $group['depends'] . "']\").is(':disabled')) {
                                $(\"#" . $group['id'] . " :input\").prop('disabled',true);
                                $(\"#" . $group['id'] . "\").hide();
                            } else {
                                if ($(\"[name='" . $group['depends'] . "']\").val() == \"" . $group['dependsValue'] . "\") {
                                    $(\"#" . $group['id'] . " :input\").prop('disabled',false);
                                    $(\"#" . $group['id'] . "\").show();
                                } else {
                                    $(\"#" . $group['id'] . " :input\").prop('disabled',true);
                                    $(\"#" . $group['id'] . "\").hide();
                                }
                            }
                        }
                    });    
                });
                var config = { attributes: true }; // Configure to only listen to attribute changes
                observer.observe($(\"[name='" . $group['depends'] . "']\")[0], config); // Start observing target
                
                $( document ).ready(function() {                
                    if ($(\"[name='" . $group['depends'] . "']\").is(':disabled')) {
                        $(\"#" . $group['id'] . " :input\").prop('disabled',true);
                        $(\"#" . $group['id'] . "\").hide();
                    } else {
                        if ($(\"[name='" . $group['depends'] . "']\").val() == \"" . $group['dependsValue'] . "\") {
                            $(\"#" . $group['id'] . " :input\").prop('disabled',false);
                            $(\"#" . $group['id'] . "\").show();
                        } else {
                            $(\"#" . $group['id'] . " :input\").prop('disabled',true);
                            $(\"#" . $group['id'] . "\").hide();
                        }
                    }
                });
                ";
            }
        }
        return ["content" => $content, "js" => $js];
    }
}