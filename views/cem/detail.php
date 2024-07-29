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

use DBA\Evaluation;
use DBA\Experiment;
use DBA\User;

$this->includeAsset('datatables');
$this->includeAsset('ionicons');
?><div class="content-wrapper">
    <section class="content-header">
        <h1>
            Node: <?php echo $data['node']->getId(); ?>
        </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/cem/overview">CEM</a></li>
            <li class="active"><?php echo $data['node']->getId(); ?></li>
        </ol>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-6">

                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">General</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label>Unique Node ID</label>
                            <input class="form-control required" id="nodeId" type="text" value="<?php echo $data['node']->getId(); ?>" disabled="" >
                        </div>
                        <div class="form-group">
                            <label>Hostname</label>
                            <input class="form-control required" id="hostname" type="text" value="<?php echo $data['node']->getHostname(); ?>" disabled="" >
                        </div>
                        <div class="form-group">
                            <label>Version</label>
                            <input class="form-control required" id="version" type="text" value="<?php echo $data['node']->getVersion(); ?>" disabled="" >
                        </div>
                        <div class="form-group">
                            <label>Environment</label>
                            <input class="form-control required" id="environment" type="text" value="<?php echo $data['node']->getEnvironment(); ?>" disabled="" >
                        </div>
                        <div class="form-group">
                            <label>Last Update</label>
                            <input class="form-control required" id="environment" type="text" title="<?php echo $data['node']->getLastUpdate(); ?>" value="<?php echo (new DateTime())->diff(new DateTime($data['node']->getLastUpdate()))->format('%i minutes ago');  ?>" disabled="" >
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </section>
</div>