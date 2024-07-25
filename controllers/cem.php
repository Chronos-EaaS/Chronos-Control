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

use DBA\Factory;
use DBA\QueryFilter;
use DBA\Node;

class CEM_Controller extends Controller {
    public $overview_access = Auth_Library::A_ADMIN;

    /**
     * @throws Exception
     */
    public function overview() {
        if (!empty($this->get['missing']) && $this->get['missing'] == 'true') {
            $this->view->assign('showMissingNodes', true);
            $nodes = Factory::getNodeFactory()->filter([]);
        } else {
            $archived[] = new QueryFilter(Node::LAST_UPDATE, date('Y-m-d H:i:s', strtotime('-10 minutes')), ">");
            $nodes = Factory::getNodeFactory()->filter([Factory::FILTER => $archived]);
            $this->view->assign('showMissingNodes', false);
        }

        $this->view->assign('nodes', $nodes);
    }

    public $detail_access = Auth_Library::A_ADMIN;

    /**
     * @throws Exception
     */
    public function detail() {
        $nodeId = trim($this->get['id']);
        if (empty($nodeId)) {
            throw new Exception('No node id provided');
        }
        $node = Factory::getNodeFactory()->get($nodeId);
        $this->view->assign('node', $node);
        if ($node->getCurrentJob() !== null) {
            $job = Factory::getJobFactory()->get($node->getCurrentJob());
            $this->view->assign('job', $job);
        }
    }

}
