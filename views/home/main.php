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

?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1>
			Chronos Control
		</h1>
	</section>

	<!-- Main content -->
	<section class="content">
        <div class="row">
            <div class="col-md-6">
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa fa-project-diagram"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Number of Projects</span>
                        <span class="info-box-number"><?php echo $data['numProjects'] ?></span>
                    </div>
                </div>
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa fa-flask"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Number of Experiments</span>
                        <span class="info-box-number"><?php echo $data['numExperiments'] ?></span>
                    </div>
                </div>
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa fa-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed Evaluations</span>
                        <span class="info-box-number"><?php echo $data['numFinished'] ?></span>
                    </div>
                </div>
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa fa-sync"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Running Evaluations</span>
                        <span class="info-box-number"><?php echo $data['numRunning'] ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <?php
                $eventLibrary = new Event_Library();
                echo $eventLibrary->renderTimeline($data['events']);
                ?>
            </div>
        </div>
	</section>
</div>
