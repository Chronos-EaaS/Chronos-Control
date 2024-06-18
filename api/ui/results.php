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

class Results_API extends API {

    public $patch_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function patch() {
        if (!empty($this->request['systemId']) && !empty($this->request['content']) && !empty($this->request['resultId'])) {
            $system = new System($this->request['systemId']);
            $content = base64_decode($this->request['content']);
            $resultId = $this->request['resultId'];
            $type = intval($this->request['type']);
            if ($type == Results_Library::TYPE_JOB) {
                $system->setResultsJob($content, $resultId);
            } else {
                $system->setResultsAll($content, $resultId);
            }
            $this->add("SAVED");
        } else {
            throw new Exception("Invalid query!");
        }
    }

    public $get_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function get() {
        if (!empty($this->get['action'])) {
            switch ($this->get['action']) {
                case 'newplot':
                    if (!empty($this->get['uid']) && !empty($this->get['type']) && !empty($this->get['systemId']) && !empty($this->get['resultId'])) {
                        $system = new System($this->get['systemId']);
                        $builder = new Results_Library($system, $this->get['resultId']);
                        $element = $builder->getElementFromIdentifier($this->get['type']);
                        $template = $element->getBuildTemplate();
                        $this->add(base64_encode($template->render(['id' => $this->get['uid'], 'name' => '', 'parameter' => ''])));
                    } else {
                        throw new Exception("Not enough data provided for action 'newplot'!");
                    }
                    break;
                case 'up':
                    $system = new System($this->get['systemId']);
                    $id = $this->get['uid'];
                    echo "UID is " . $id . "\n";
                    $arr = $system->getResultsAll();
                    //echo $arr;
                    $jsonJob = json_decode($arr, true);
                    foreach ($jsonJob as $job) {
                        var_dump($job);
                        if($job == $id) {
                            echo "found in job. \n";
                            break;
                        }
                        else {
                            foreach ($job as $element) {
                                //echo gettype($element) . "<br>";
                                //echo 'Element: ' . $element. "<br>";
                                if (gettype($element)=='string') {
                                    if($element == $id) {
                                        echo "found in element.\n";
                                        break;
                                    }
                                }
                                else { // $element is an array
                                    foreach ($element as $e) {
                                        if($e == $id) {
                                            echo "found in e.\n";
                                            break;
                                        }
                                        if (gettype($e)=='array') {
                                            foreach ($e as $ok) {
                                                if ($ok == $id) {
                                                    echo "found in e.\n";
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    break;
                case 'down':
                    echo 'down';
                    break;
                default:
                    throw new Exception("Unknown action!");
            }
        }
    }
}