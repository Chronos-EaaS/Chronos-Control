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
        if (!empty($this->request['systemId']) && !empty($this->request['content'])) {
            $system = new System($this->request['systemId']);
            $content = base64_decode($this->request['content']);
            $type = intval($this->request['type']);
            if ($type == Results_Library::TYPE_JOB) {
                $system->setResultsJob($content);
            } else {
                $system->setResultsAll($content);
            }
            $this->add("SAVED");
        } else {
            throw new Exception("Invalid query!");
        }
    }

    public $get_access = Auth_Library::A_LOGGEDIN;

    /**
     * @return string
     * @throws Exception
     */
    public function get() {
        if (!empty($this->request['action'])) {
            switch ($this->request['action']) {
                case 'newplot':
                    if (!empty($this->request['uid']) && !empty($this->request['type']) && !empty($this->request['systemId'])) {
                        $system = new System($this->request['systemId']);
                        $builder = new Results_Library($system);
                        $element = $builder->getElementFromIdentifier($this->request['type']);
                        $template = $element->getBuildTemplate();
                        $this->add(base64_encode($template->render(array('id' => $this->request['uid'], 'name' => '', 'parameter' => ''))));
                    }
                    break;
            }
        }
        return "";
    }
}