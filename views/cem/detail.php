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
            Node Information
        </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/cem/overview">CEM</a></li>
            <li class="active">Node</li>
        </ol>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-6">

                <div class="box box-widget widget-user">
                    <div class="widget-user-header bg-light-blue" style="height:auto">
                        <h3 class="widget-user-username"><?php echo $data['node']->getHostname(); ?></h3>
                        <h5 class="widget-user-desc"><?php echo $data['node']->getId(); ?></h5>
                    </div>
                    <div class="box-footer" style="padding-top: 5px">
                        <ul class="nav nav-stacked">
                            <li style="padding:10px;"><span>Environment <span class="pull-right"><b><?php echo $data['node']->getEnvironment(); ?></b></span></span></li>
                            <li style="padding:10px;"><span>Version <span class="pull-right"><b><?php echo $data['node']->getVersion(); ?></b></span></span></li>
                            <li style="padding:10px;"><span>OS <span class="pull-right"><b><?php echo $data['node']->getOs(); ?></b></span></span></li>
                            <li style="padding:10px;"><span>IP <span class="pull-right"><b><?php echo $data['node']->getIp(); ?></b></span></span></li>
                            <li style="padding:10px;" title="<?php echo $data['node']->getLastUpdate(); ?>"><span>Last Status Update <span class="pull-right"><b><?php echo Util::timeDifferenceString($data['node']->getLastUpdate()); ?></b></span></span></li>
                        </ul>
                    </div>
                </div>

                <!-- CPU -->
                <?php if (is_numeric($data['node']->getCpu())) { ?>
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
                            <span class="info-box-number"><?php echo $cpu; ?>%</span>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $cpu; ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <!-- Memory -->
                <?php if (!empty($data['node']->getMemoryUsed()) && !empty($data['node']->getMemoryUsed())) { ?>
                    <?php $memoryPercentageUsed = round(($data['node']->getMemoryUsed() / $data['node']->getMemoryTotal())*100); ?>
                    <?php if ($memoryPercentageUsed > 85) { ?>
                    <div class="info-box bg-red">
                    <?php } else if ($memoryPercentageUsed > 60) { ?>
                    <div class="info-box bg-yellow">
                    <?php } else { ?>
                    <div class="info-box bg-green">
                        <?php } ?>
                        <span class="info-box-icon"><i class="fa fa-memory"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Memory</span>
                            <span class="info-box-number"><?php echo $memoryPercentageUsed; ?>%</span>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $memoryPercentageUsed; ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

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

            </div>
            <div class="col-md-6">


                <!-- Timeline -->
                <?php
                $eventLibrary = new Event_Library();
                echo $eventLibrary->renderTimeline($data['events']);
                ?>

            </div>
        </div>
    </section>
</div>