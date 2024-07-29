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
                            <input class="form-control required" id="environment" type="text" title="<?php echo $data['node']->getLastUpdate(); ?>" value="<?php echo Util::timeDifferenceString($data['node']->getLastUpdate());  ?>" disabled="" >
                        </div>
                    </div>
                </div>

            </div>
            <div class="col-md-6">

                <!-- Health status -->
                <div class="info-box">
                <?php if (empty($data['node']->getHealthStatus())) { ?>
                    <span class="info-box-icon bg-green"><i class="fa fa-check"></i></span>
                <?php } else { ?>
                    <span class="info-box-icon bg-red"><i class="fa fa-exclamation-circle"></i></span>
                <?php } ?>
                    <div class="info-box-content">
                        <span class="info-box-text">Health Status</span>
                        <span class="info-box-number"><?php echo (empty($data['node']->getHealthStatus()) ? "OK" : $data['node']->getHealthStatus()); ?></span>
                    </div>
                </div>

                <!-- CPU -->
                <?php $cpu = round($data['node']->getCpu()); ?>
                <?php if ($cpu > 50) { ?>
                <div class="info-box bg-red">
                <?php } else if ($cpu > 35) { ?>
                <div class="info-box bg-yellow">
                <?php } else { ?>
                <div class="info-box bg-green">
                <?php } ?>
                    <span class="info-box-icon"><i class="fa fa-microchip"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">CPU</span>
                        <span class="info-box-number"><?php echo $cpu; ?></span>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $cpu; ?>%"></div>
                        </div>
                    </div>
                </div>


                <!-- Memory -->
                <?php $memoryPercentageUsed = ($data['node']->getMemoryUsed() / $data['node']->getMemoryTotal())*100; ?>
                <?php if ($memoryPercentageUsed > 85) { ?>
                <div class="info-box bg-red">
                <?php } else if ($memoryPercentageUsed > 60) { ?>
                <div class="info-box bg-yellow">
                <?php } else { ?>
                <div class="info-box bg-green">
                    <?php } ?>
                    <span class="info-box-icon"><i class="fa fa-memory"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">CPU</span>
                        <span class="info-box-number"><?php echo $memoryPercentageUsed; ?>%</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $memoryPercentageUsed; ?>%"></div>
                        </div>
                    </div>
                </div>


            </div>

        </div>

    </section>
</div>