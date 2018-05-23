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

class Element {
    private $path;
    private $config;

    /**
     * Element constructor.
     * @param $path
     * @throws Exception
     */
    public function __construct($path) {
        $this->path = $path . "/";
        if (!file_exists($this->path . "config.json")) {
            throw new Exception("No config available for element '$this->path'!");
        }
        $this->config = json_decode(file_get_contents($this->path . "config.json"), true);
    }

    public function isValid() {
        /*
         * To check:
         * - type and name defined in config
         * - build and render template present
         */
        // TODO: finish config check
        return true;
    }

    public function getType() {
        return $this->config['type'];
    }

    public function getName() {
        return $this->config['name'];
    }

    public function generatesMultiJob() {
        return $this->config['multiJob'];
    }

    /**
     * @param $data
     * @param $parameter
     * @param $allCDL CDL_Library[]
     * @throws Exception
     */
    public function process($data, $parameter, &$allCDL) {
        // when generating multi-job, we need to copy the current CDLs and apply our setting for every of them
        if (file_exists($this->path . "process.php")) {
            // we include the external process function
            include($this->path . "process.php");
        } else {
            // default handling
            // TODO: do default processing here
            if ($this->generatesMultiJob()) {
                // multi-jobs are too complicated for any default
                throw new Exception("Multijobs need to have process to create evaluations!");
            } else {
                // add this value too all CDLs
                foreach ($allCDL as &$cdl) {
                    // default: take the parameter value (which should be the same name by default) and add it to the CDL
                    $eval = $cdl->getEvaluation();
                    $eval->appendChild($cdl->createElement($parameter, $data[$parameter]));
                }
            }
        }
    }

    /**
     * @return Template
     * @throws Exception
     */
    public function getBuildTemplate() {
        return new Template(file_get_contents($this->path . "build.template.html"), true);
    }

    /**
     * @return Template
     * @throws Exception
     */
    public function getRenderTemplate() {
        return new Template(file_get_contents($this->path . "render.template.html"), true);
    }
}