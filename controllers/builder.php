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

class Builder_Controller extends Controller {
    public $build_access = Auth_Library::A_LOGGEDIN;

    /**
     * @throws Exception
     */
    public function build() {
        global $FACTORIES;

        if (!empty($this->get['id'])) {
            $system = $FACTORIES::getSystemFactory()->get($this->get['id']);
            if ($system) {
                if (strlen($system->getVcsUrl()) > 0) {
                    throw new Exception("This system is not intended to use with the builder!");
                }
                $builder = new Builder_Library($system);
                $this->view->assign('system', $system);
                $this->view->assign('content', $builder->buildContent());

                // get available elements
                $dir = scandir(SERVER_ROOT . "/templates/builder/element/");
                $elements = array();
                foreach ($dir as $entry) {
                    if (strpos($entry, ".template.html") !== false) {
                        $entry = str_replace(".template.html", "", $entry);
                        $elements[] = array('type' => $entry, "name" => ucfirst($entry) . " Element");
                    }
                }
                $this->view->assign('elementTypes', $elements);
            } else {
                throw new Exception("No system with id: " . $this->get['id']);
            }
        } else {
            throw new Exception("No system id provided!");
        }
    }
}