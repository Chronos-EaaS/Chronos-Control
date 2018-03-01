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
        global $FACTORIES;

        if (!empty($this->request['systemId']) && !empty($this->request['content'])) {
            $system = $FACTORIES::getSystemFactory()->get($this->request['systemId']);
            if ($system && strlen($system->getVcsUrl()) == 0) {
                $content = base64_decode($this->request['content']);
                $system->setBuilderJson($content);
                $FACTORIES::getSystemFactory()->update($system);
                $this->add("SAVED");
            } else {
                throw new Exception("Invalid system!");
            }
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
            $this->add(base64_encode($template->render(array('id' => $this->request['uid'], 'title' => $this->request['name'], 'content' => ""))));
        }
        return "";
    }

    public $newelement_access = Auth_Library::A_LOGGEDIN;

    /**
     * @return string
     * @throws Exception
     */
    public function newelement() {
        if (!empty($this->request['uid']) && !empty($this->request['type'])) {
            $template = new Template("builder/element/" . $this->request['type']);
            $this->add(base64_encode($template->render(array('id' => $this->request['uid'], 'name' => ''))));
        }
        return "";
    }
}