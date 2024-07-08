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

$this->includeInlineJS("
		function validateForm() {
			var isValid = true;
			$('.required').each(function() {
				if ($(this).val() === '') {
					isValid = false;
				}
			});
			return isValid;
		}
		
		function submitData() {
			$.ajax({
			 	url : '/api/ui/evaluation/id=' + $('#id').val(),
			 	data : {
			 		name : $('#name').val(),
			 		description : $('#description').val()
				},
			 	type : 'PATCH',
			 	dataType: 'json',
			 	error: function (data) {
              $('#errorMessage').text('Unknown');
              $('#saveResultError').show();
        },
        success: function (data) {
            if(data.status.code == 200){
                $('#saveResultSuccess').show();
            } else {
                $('#errorMessage').text(data.status.message);
                $('#saveResultError').show();
            }
        }
			});
		}
		
		jQuery(document).ready(function($) {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
");

$this->includeInlineCSS("
    .btn-app {
        margin-left: 0;
        margin-bottom: 20px;
        margin-right: 10px;
    }
");
?>
<div class="content-wrapper">

		<section class="content-header">
			<h1>
				Evaluation: <?php echo $data['evaluation']->getName(); if($data['evaluation']->getIsStarred()){echo " <span class='fa fa-star'></span>";}?>
			</h1>
            <ol class="breadcrumb">
                <li><a href="/home/main">Home</a></li>
                <li><a href="/project/detail/id=<?php echo $data['experiment']->getProjectId() ?>">Project</a></li>
                <li><a href="/experiment/detail/id=<?php echo $data['experiment']->getId() ?>">Experiment</a></li>
                <li class="active">Evaluation Details</li>
            </ol>
		</section>
	
		<section class="content">
			<div class="row">
				<div class="col-md-6">
					
					<!-- General -->
          <form id="form" action="#" method="POST">
					<div class="box box-default">
						<div class="box-header with-border">
							<h3 class="box-title">General</h3>
						</div>
						<div class="box-body">
              <div id="saveResultSuccess" style="display:none;" class="alert alert-success">
                <a class="close" onclick="$('#saveResultSuccess').hide()">×</a>
                <h4><i class="icon fa fa-check"></i> Success</h4>
              </div>
              <div id="saveResultError" style="display:none;" class="alert alert-danger">
                <a class="close" onclick="$('#saveResultError').hide()">×</a>
                <h4><i class="icon fa fa-times-circle"></i> Error: </h4><span id="errorMessage">Unknown</span>
              </div>
							<div class="form-group">
								<label>Name</label>
								<input class="form-control required" id="name" type="text" value="<?php echo $data['evaluation']->getName(); ?>" >
			                </div>
							<div class="form-group">
								<label>Description</label>
								<textarea class="form-control" rows="8" id="description"><?php echo $data['evaluation']->getDescription(); ?></textarea>
			                </div>
						</div>
						<div class="box-footer">
							<input id="id" type="text" value="<?php echo $data['evaluation']->getId(); ?>" hidden>
							<button type="button" class="btn btn-primary pull-right" name="group" onclick="if(validateForm()) submitData();">Save</button>
						</div>
					</div>
  </form>



                </div>
				
				<div class="col-md-6">


                    <div class="clearfix">
                        <!-- Show Results -->
                        <?php if ($data['resultsAvailable'] === true && $data['supportsShowResults'] === true) { ?>
                          <a class="btn btn-app" href="/results/show/id=<?php echo $data['evaluation']->getId(); ?>">
                              <i class="fa fa-chart-bar "></i> Results
                          </a>
                        <?php } ?>

                        <!-- Download All -->
                        <?php if ($data['isFinished'] === true) { ?>
                          <a class="btn btn-app" href="/evaluation/download/id=<?php echo $data['evaluation']->getId(); ?>">
                              <i class="fa fa-download"></i> Download
                          </a>
                        <?php } ?>

                        <!-- Re-schedule All -->
                        <?php if ($data['isFinished'] === false) { ?>
                          <form action="/evaluation/detail/id=<?php echo $data['evaluation']->getId(); ?>" method="post" class="pull-left form-inline">
                            <input type="hidden" name="reschedule" value="all">
                            <button class="btn btn-app" type="submit">
                                <i class="fa fa-redo"></i> Re-schedule All
                            </button>
                          </form>
                        <?php } ?>

                        <!-- Abort All -->
                        <?php if ($data['isFinished'] === false) { ?>
                          <form action="/evaluation/detail/id=<?php echo $data['evaluation']->getId(); ?>" method="post" class="pull-left form-inline">
                            <input type="hidden" name="abort" value="all">
                            <button class="btn btn-app" type="submit">
                              <i class="fa fa-ban"></i> Abort All
                            </button>
                          </form>
                        <?php } ?>

                        <!-- Starring -->
                        <?php if ($data['evaluation']->getIsStarred() == 0) { ?>
                            <form action="/evaluation/detail/id=<?php echo $data['evaluation']->getId(); ?>" method="post" class="pull-right form-inline">
                                <input type="hidden" name="star" value="1">
                                <button class="btn btn-app" type="submit">
                                    <i class="fa fa-star"></i> Star
                                </button>
                            </form>
                        <?php } else  { ?>
                            <form action="/evaluation/detail/id=<?php echo $data['evaluation']->getId(); ?>" method="post" class="pull-right form-inline">
                                <input type="hidden" name="unstar" value="1">
                                <button class="btn btn-app" type="submit">
                                    <i class="fa fa-broom"></i> Clear Star
                                </button>
                            </form>
                        <?php } ?>
                    </div>


                    <!-- Jobs -->
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">Jobs</h3>
                        </div>
                        <div class="box-body">
                            <table id="jobs" class="table table-hover">
                                <thead>
                                <tr>
                                    <th style="width: 10px;">#</th>
                                    <th>Description</th>
                                    <th>Keywords</th>
                                    <th>Log Size</th>
                                    <th>Status</th>
                                    <th style="width: 10px"></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach($data['subjobs'] as $job) { /** @var $job Job */ ?>
                                    <tr class='clickable-row' data-href='/job/detail/id=<?php echo $job->getId(); ?>' style="cursor: pointer;">
                                        <td><?php echo $job->getInternalId(); ?></td>
                                        <td><?php echo $job->getDescription(); ?></td>
                                        <td>
                                            <?php if($job->getLogAlert() == "error") { ?>
                                                <span class="glyphicon glyphicon-alert pull right"></span>
                                            <?php } else if($job->getLogAlert() == "warning") { ?>
                                                <span class="glyphicon exclamation-sign pull right"></span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <?php if($job->getSizeWarning()) { ?>
                                            <span class="glyphicon glyphicon-hourglass pull right"></span>
                                            <?php } ?>
                                        </td>
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
                                        <?php if($job->getStatus() == Define::JOB_STATUS_FINISHED) { ?>
                                            <td><a href="<?php echo UPLOADED_DATA_PATH_RELATIVE; ?>evaluation/<?php echo $job->getId(); ?>.zip"><i class="fa fa-download"></i></a></td>
                                        <?php } else { ?>
                                            <td></td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>

                        </div>
                    </div>
				</div>
			</div>
		</section>
</div>
