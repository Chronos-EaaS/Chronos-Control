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

class Builder_API extends API {

    public $patch_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function patch() {
        if (!empty($this->request['systemId']) && !empty($this->request['content'])) {
            $system = new System($this->request['systemId']);
            $content = base64_decode($this->request['content']);
            $system->setParameters($content);
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
                case 'newgroup':
                    if (!empty($this->get['uid']) && !empty($this->get['name'])) {
                        $template = new Template("builder/group");
                        $this->add(base64_encode($template->render(['id' => $this->get['uid'], 'title' => $this->get['name'], 'depends' => $this->get['depends'], 'dependsValue' => $this->get['dependsValue'], 'content' => ""])));
                    }
                    break;
                case 'newelement':
                    if (!empty($this->get['uid']) && !empty($this->get['type']) && !empty($this->get['systemId'])) {
                        $system = new System($this->get['systemId']);
                        $builder = new Builder_Library($system);
                        $element = $builder->getElementFromIdentifier($this->get['type']);
                        $template = $element->getBuildTemplate();
                        $this->add(base64_encode($template->render(['id' => $this->get['uid'], 'name' => ''])));
                    }
                    break;
            }
        }
    }
}