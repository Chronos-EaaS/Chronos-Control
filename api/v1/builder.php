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

    public $save_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function save() {
        if (!empty($this->request['systemId']) && !empty($this->request['content'])) {
            $system = new System($this->request['systemId']);
            $content = base64_decode($this->request['content']);
            $system->setParameters($content);
            $this->add("SAVED");
        } else {
            throw new Exception("Invalid query!");
        }
    }

    public $newgroup_access = Auth_Library::A_LOGGEDIN;

    /**
     * @return string
     * @throws Exception
     */
    public function newgroup() {
        if (!empty($this->request['uid']) && !empty($this->request['name'])) {
            $template = new Template("builder/group");
            $this->add(base64_encode($template->render(array('id' => $this->request['uid'], 'title' => $this->request['name'], 'depends' => $this->request['depends'], 'dependsValue' => $this->request['dependsValue'], 'content' => ""))));
        }
        return "";
    }

    public $newelement_access = Auth_Library::A_LOGGEDIN;

    /**
     * @return string
     * @throws Exception
     */
    public function newelement() {
        if (!empty($this->request['uid']) && !empty($this->request['type']) && !empty($this->request['systemId'])) {
            $system = new System($this->request['systemId']);
            $builder = new Builder_Library($system);
            $element = $builder->getElementFromIdentifier($this->request['type']);
            $template = $element->getBuildTemplate();
            $this->add(base64_encode($template->render(array('id' => $this->request['uid'], 'name' => ''))));
        }
        return "";
    }
}