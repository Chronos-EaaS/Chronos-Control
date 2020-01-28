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

    const TYPE_ALL = 1;
    const TYPE_JOB = 2;

    /**
     * Builder_Library constructor.
     * @param $system System
     */
    public function __construct($system) {
        $this->system = $system;
        $this->json[Results_Library::TYPE_ALL] = json_decode($system->getResultsAll(), true);
        $this->json[Results_Library::TYPE_JOB] = json_decode($system->getResultsJob(), true);
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

    /**
     * @param $jobs Job[][]
     * @param $view View
     * @return string
     * @throws Exception
     */
    public function buildResults($jobs, $view) {
        $content = "";
        foreach ($this->json[Results_Library::TYPE_ALL] as $p) {
            $wrapperTemplate = new Template("builder/plotbox");
            $plot = $this->getElementFromIdentifier($p['type']);
            $template = $plot->getRenderTemplate();
            $p['plotData'] = $plot->process($jobs, $p);
            $p['plotId'] = str_replace("-", "", $p['id']);
            $plotContent = "<div class='col-sm-12'>" . $template->render($p) . "</div>";
            foreach ($plot->getRequired() as $required) {
                $view->includeAsset($required);
            }
            $view->includeInlineJS("plot" . $p['plotId'] . "();");
            $content .= $wrapperTemplate->render(array('plotData' => $plotContent, 'title' => $p['name']));
        }

        foreach ($jobs as $job) {
            $wrapperTemplate = new Template("builder/plotbox");
            $wrapperContent = "";
            if(!is_array($job[0]->getConfiguration())) { // only decode if needed
                $job[0]->setConfiguration(json_decode($job[0]->getConfiguration(), TRUE));
            }
            $title = $job[0]->getConfiguration()[Define::CONFIGURATION_TITLE];
            foreach ($this->json[Results_Library::TYPE_JOB] as $p) {
                $plot = $this->getElementFromIdentifier($p['type']);
                $template = $plot->getRenderTemplate();
                $p['plotData'] = $plot->process([$job], $p);
                $p['plotId'] = str_replace("-", "", $p['id']) . $job[0]->getInternalId();
                $wrapperContent .= "<div class='col-sm-6'><h5>" . $p['name'] . "</h5>" . $template->render($p) . "</div>";
                foreach ($plot->getRequired() as $required) {
                    $view->includeAsset($required);
                }
                $view->includeInlineJS("plot" . $p['plotId'] . "();");
            }
            $content .= $wrapperTemplate->render(array('plotData' => $wrapperContent, 'title' => $title));
        }
        return $content;
    }
}