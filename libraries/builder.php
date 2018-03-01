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
    private $templates;

    /**
     * Builder_Library constructor.
     * @param $system \DBA\System
     */
    public function __construct($system) {
        $this->templates = dirname(__FILE__) . "/builder/templates";
        $this->json = json_decode($system->getBuilderJson(), true);
    }

    /**
     * @param $system \DBA\System
     */
    public static function initSystem($system) {
        // create fake system
        $folder = SERVER_ROOT . "/webroot/systems/" . strtolower($system->getName());
        mkdir($folder);

        // load class
        $class = file_get_contents(dirname(__FILE__) . "/builder/system_template.php");
        $class = str_replace("__NAME__", $system->getName(), $class);
        file_put_contents($folder . "/" . strtolower($system->getName()) . ".php", $class);

        // copy system assets
        Util::recursiveCopy(dirname(__FILE__) . "/builder/system_assets", $folder . "/system_assets/");

        // copy view
        mkdir($folder . "/views");
        copy(dirname(__FILE__) . "/builder/views/wizard_template.php", $folder . "/views/wizard.php");
        copy(dirname(__FILE__) . "/builder/views/evaluationResults_template.php", $folder . "/views/evaluationResults.php");
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
            foreach ($group['elements'] as $element) {
                $template = new Template("builder/element/" . $element['type']);
                $c .= $template->render($element);
            }

            $template = new Template("builder/group");
            $obj = array('title' => $group['title'], 'id' => $group['id'], 'content' => $c);
            $content .= $template->render($obj);
        }
        return $content;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function buildExperiment() {
        $content = "";
        foreach ($this->json as $group) {
            $c = "";
            foreach ($group['elements'] as $element) {
                $template = new Template("experiment/element/" . $element['type']);
                $element['elementName'] = strtolower(str_replace(" ", "-", $element['name']));
                $c .= $template->render($element);
            }

            $template = new Template("experiment/group");
            $obj = array('title' => $group['title'], 'id' => $group['id'], 'content' => $c);
            $content .= $template->render($obj);
        }
        return $content;
    }
}