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

use DBA\Job;

$this->includeAsset('datatables');
$this->includeAsset('ionicons');
$this->includeInlineJS("
	$(function () {
	    $('#running').DataTable({
	      'paging': false,
	      'lengthChange': false,
	      'searching': false,
	      'ordering': true,
	      'info': true,
	      'autoWidth': false
	    });
	    $('#finished').DataTable({
	      'paging': true,
	      'lengthChange': false,
	      'searching': false,
	      'ordering': true,
	      'order': [[ 0, \"desc\" ]],
	      'info': true,
	      'autoWidth': false,
	      'pageLength': 25
	    });
  	});
  	
  	jQuery(document).ready(function($) {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
	
	$('#running').bind('DOMSubtreeModified', function() {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
	$('#finished').bind('DOMSubtreeModified', function() {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
	
	function reloadPage() {
	    if(!$('#showAllUser').length || $('#showAllUser').prop('checked')) {
            var userStr = '';
	    } else {
	        var userStr = 'user=all/';
	    }
	    if($('#showOnlyActive').prop('checked')) {
	        var statusStr = 'status=active/';
	    } else {
	        var statusStr = 'status=all/';
	    }
	    window.document.location = '/job/jobs/' + userStr + statusStr;
	}
");
?>

<div class="content-wrapper">
	<section class="content-header">
		<h1> Jobs </h1>
	</section>

	<section class="content">

        <div class="box">
            <div class="box-body">
                <div class="checkbox">
                    <label>
                        <input id="showOnlyActive" type="checkbox" <?php if($data['showOnlyActive']) echo ' checked'; ?> onchange="reloadPage();">
                        Show only active jobs
                    </label>
                </div>
                <?php if($auth->isAdmin()) { ?>
                    <div class="checkbox">
                        <label>
                            <input id="showAllUser" type="checkbox" <?php if(!$data['showAllUser']) echo ' checked'; ?> onchange="reloadPage();">
                            Show only my own jobs
                        </label>
                    </div>
                <?php } ?>
            </div>
        </div>

		<!-- running -->
		<div class="box">
			<div class="box-header">
				<h3 class="box-title">Scheduled and running jobs</h3>
			</div>
			<div class="box-body">
				<table id="running" class="table table-hover">
					<thead>
						<tr>
							<th style="width: 10px;">#</th>
							<th>Evaluation</th>
							<th>User</th>
							<th>Type</th>
							<th>System</th>
							<th>Status</th>
							<th>Progress</th>
							<!-- <th style="width: 10px"></th> -->
						</tr>
					</thead>
					<tbody>
						<?php foreach($data['jobs'] as $job) { /** @var $job Job */ ?>
							<?php if($job->getStatus() == Define::JOB_STATUS_SCHEDULED || $job->getStatus() == Define::JOB_STATUS_RUNNING || $job->getStatus() == Define::JOB_STATUS_FAILED) { ?>
								<tr class='clickable-row' data-href='/job/detail/id=<?php echo $job->getId(); ?>' style="cursor: pointer;">
									<td><?php echo $job->getId(); ?></td>
									<td><?php echo $data['evaluations']->getVal($job->getEvaluationId())->getName(); ?></td>
									<td><?php echo $data['users']->getVal($job->getUserId())->getFirstname() . ' ' . $data['users']->getVal($job->getUserId())->getLastname() . ' (' . $data['users']->getVal($job->getUserId())->getUsername() . ')'; ?></td>
									<td>
										<?php 
											if($job->getType() == 1) {
												echo "data generation";
											} else if($job->getType() == 2) {
												echo "evaluation";
											}
										?>
									</td>
									<td><?php echo $data['systems']->getVal($job->getSystemId())->getName(); ?></td>
									<td>
										<?php if($job->getStatus() == Define::JOB_STATUS_SCHEDULED) { ?>
											<span class="label label-success">scheduled</span>
										<?php } else if($job->getStatus() == Define::JOB_STATUS_RUNNING) { ?>
											<span class="label label-warning">running</span>
										<?php } else if($job->getStatus() == Define::JOB_STATUS_FINISHED) { ?>
											<span class="label label-info">finished</span>
                                        <?php } else if($job->getStatus() == Define::JOB_STATUS_ABORTED) { ?>
                                            <span class="label label-default">aborted</span>
                                        <?php } else if($job->getStatus() == Define::JOB_STATUS_FAILED) { ?>
                                            <span class="label label-danger">failed</span>
                                        <?php } ?>
									</td>
									<td>	
										<?php if($job->getStatus() == Define::JOB_STATUS_RUNNING && $job->getProgress() > 0) { ?>
											<div class="progress progress-xs progress-striped active">
												<div class="progress-bar progress-bar-success" style="width: <?php echo $job->getProgress(); ?>%"></div>
											</div>
										<?php } ?>
									</td>
									<!-- <td><a href="/job/detail/id=<?php echo $job->getId(); ?>"><i class="fa fa-info"></i></a></td> -->
								</tr>
							<?php } ?>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
		
		<!-- finished -->
        <?php if(!$data['showOnlyActive']) { ?>
            <div class="box">
                <div class="box-header">
                    <h3 class="box-title">Finished jobs</h3>
                </div>
                <div class="box-body">
                    <table id="finished" class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 10px;">#</th>
                                <th>Evaluation</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>System</th>
                                <th>Status</th>
                                <!-- <th style="width: 10px"></th> -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($data['jobs'] as $job) { /** @var $job Job */ ?>
                                <?php if($job->getStatus() == Define::JOB_STATUS_FINISHED || $job->getStatus() == Define::JOB_STATUS_ABORTED) { ?>
                                    <tr class='clickable-row' data-href='/job/detail/id=<?php echo $job->getId(); ?>' style="cursor: pointer;">
                                        <td><?php echo $job->getId(); ?></td>
                                        <td><?php echo $data['evaluations']->getVal($job->getEvaluationId())->getName(); ?></td>
                                        <td><?php echo $data['users']->getVal($job->getUserId())->getFirstname() . ' ' . $data['users']->getVal($job->getUserId())->getLastname() . ' (' . $data['users']->getVal($job->getUserId())->getUsername() . ')'; ?></td>
                                        <td>
                                            <?php
                                                if($job->getType() ==  Define::JOB_TYPE_DATA) {
                                                    echo "data generation";
                                                } else if($job->getType() == Define::JOB_TYPE_EVALUATION) {
                                                    echo "evaluation";
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo $data['systems']->getVal($job->getSystemId())->getName(); ?></td>
                                        <td>
                                            <?php if($job->getStatus() == Define::JOB_STATUS_SCHEDULED) { ?>
                                                <span class="label label-success">scheduled</span>
                                            <?php } else if($job->getStatus() == Define::JOB_STATUS_RUNNING) { ?>
                                                <span class="label label-warning">running</span>
                                            <?php } else if($job->getStatus() == Define::JOB_STATUS_FINISHED) { ?>
                                                <span class="label label-info">finished</span>
                                            <?php } else if($job->getStatus() == Define::JOB_STATUS_ABORTED) { ?>
                                                <span class="label label-default">aborted</span>
                                            <?php } else if($job->getStatus() == Define::JOB_STATUS_FAILED) { ?>
                                                <span class="label label-danger">failed</span>
                                            <?php } ?>
                                        <!-- <td><a href="/job/detail/id=<?php echo $job->getId(); ?>"><i class="fa fa-info"></i></a></td> -->
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php } ?>
	</section>
</div>

