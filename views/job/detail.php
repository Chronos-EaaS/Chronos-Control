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
			 	url : '/api/ui/job/id=' + $('#id').val(),
			 	data : {
			 		description : $('#description').val(),
					//status : $('#status').val()
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
		
		function abortJob() {
			$.ajax({
			 	url : '/api/ui/job/id=' + $('#id').val(),
			 	data : {
					status : " . Define::JOB_STATUS_ABORTED . "
				},
			 	type : 'PATCH',
			 	dataType: 'json'
			}).done(function() {
			    location.reload(); 
			});
		}
		
		function rescheduleJob() {
			$.ajax({
			 	url : '/api/ui/job/id=' + $('#id').val(),
			 	data : {
					status : " . Define::JOB_STATUS_SCHEDULED . ",
					progress: 0
				},
			 	type : 'PATCH',
			 	dataType: 'json'
			}).done(function() {
			    location.reload(); 
			});
		}
		
		function updateAll() {
			var id = $('#id').val();
			$.get('/api/v1/job/withLog=1/id=' + id, function(data, status) {
				var obj = JSON.parse(data);
				$('#progress').width(obj.response.progress + '%');
				$('#log').text(obj.response.log);
				$('#log').scrollTop($('#log')[0].scrollHeight);
			});
		}
		
		function updateProgress() {
			var id = $('#id').val();
			$.get('/api/v1/job/withLog=0/id=' + id, function(data, status) {
				var obj = JSON.parse(data);
				$('#progress').width(obj.response.progress + '%');
			});
		}

		$(document).ready(function(){
		    updateAll();
			setInterval(function() {
				if($('#autoupdateLog').prop('checked')) {
					updateAll();
				} else {
					updateProgress();
				}
			}, 2000);
			$('#log').scrollTop($('#log')[0].scrollHeight);
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
	<form id="form" action="#" method="POST">
		<section class="content-header">
			<h1>
				Job details
			</h1>
            <ol class="breadcrumb">
                <li><a href="/home/main">Home</a></li>
                <li><a href="/project/detail/id=<?php echo $data['experiment']->getProjectId() ?>">Project</a></li>
                <li><a href="/experiment/detail/id=<?php echo $data['experiment']->getId() ?>">Experiment</a></li>
                <li><a href="/evaluation/detail/id=<?php echo $data['evaluation']->getId() ?>">Evaluation</a></li>
                <li class="active">Job Details</li>
            </ol>
		</section>
	
		<section class="content">
			<?php if($data['job']->getStatus() != Define::JOB_STATUS_FINISHED && $data['job']->getStatus() != Define::JOB_STATUS_ABORTED) { ?>
					<div class="box box-default">
						<div class="box-header with-border">
							<h3 class="box-title">Progress</h3>
						</div>
						<div class="box-body">
                            <div class="progress progress-xs progress-striped active">
                                <div class="progress-bar progress-bar-success" id="progress" style="width: <?php echo $data['job']->getProgress(); ?>%"></div>
                            </div>

						</div>
					</div>
			<?php } ?>
			<div class="row">
				<div class="col-md-6">
					
					<!-- General -->
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
								<label>Evaluation Name</label>
								<input class="form-control" id="evaluationName" type="text" value="<?php echo $data['evaluation']->getName(); ?>" disabled="">
			                </div>
                            <div class="form-group">
                                <label>ID</label>
                                <input class="form-control" id="id" type="text" value="<?php echo $data['job']->getId(); ?>" disabled="">
                            </div>
                            <div class="form-group">
                                <label>User</label>
                                <input class="form-control" id="username" type="text" value="<?php echo $data['user']->getFirstname() . ' ' . $data['user']->getLastname() . ' (' . $data['user']->getUsername() . ')'; ?>" disabled="">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <input class="form-control" id="status" type="text" value="<?php
                                if($data['job']->getStatus() == Define::JOB_STATUS_SCHEDULED) echo 'scheduled';
                                else if($data['job']->getStatus() == Define::JOB_STATUS_RUNNING) echo 'running';
                                else if($data['job']->getStatus() == Define::JOB_STATUS_FINISHED) echo 'finished';
                                else if($data['job']->getStatus() == Define::JOB_STATUS_ABORTED) echo 'aborted';
                                else if($data['job']->getStatus() == Define::JOB_STATUS_FAILED) echo 'failed';
                                ?>" disabled="">
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <input class="form-control required" id="description" type="text" value="<?php echo $data['job']->getDescription(); ?>">
                            </div>
						</div>
                        <div class="box-footer">
                            <button type="button" class="btn btn-primary pull-right" name="group" onclick="if(validateForm()) submitData();">Save</button>
                        </div>
					</div>

					<!-- Log -->
					<div class="box box-default">
						<div class="box-header with-border">
							<h3 class="box-title">Log</h3>
						</div>
						<div class="box-body">
							<div class="row">
								<div class="col-xs-4">
									<div class="form-group">
										<div class="checkbox">
											<label>
												<input id="autoupdateLog" type="checkbox">
												AutoUpdate
											</label>
										</div>
									</div>
								</div>
								<div class="col-xs-8">
									<div class="form-group">
										<button type="button" class="btn btn-primary pull-right" onclick="updateAll();"><i class="fa fa-refresh"></i> Refresh</button>
									</div>
								</div>
							</div>
							<textarea class="form-control" rows="20" id="log" placeholder=""></textarea>
						</div>
					</div>

				</div>
				
				<div class="col-md-6">

                    <!-- Link to evaluation -->
                    <a class="btn btn-app" href="/evaluation/detail/id=<?php echo $data['evaluation']->getId(); ?>">
                        <i class="fa fa-link"></i> Evaluation
                    </a>

                    <!-- Abort -->
                    <?php if($data['job']->getStatus() == Define::JOB_STATUS_SCHEDULED || $data['job']->getStatus() == Define::JOB_STATUS_RUNNING || $data['job']->getStatus() == Define::JOB_STATUS_FAILED) { ?>
                        <a class="btn btn-app" onclick="abortJob();">
                            <i class="fa fa-ban"></i> Abort
                        </a>
                    <?php } ?>

                    <!-- Reschedule -->
                    <?php if($data['job']->getStatus() == Define::JOB_STATUS_FINISHED || $data['job']->getStatus() == Define::JOB_STATUS_ABORTED || $data['job']->getStatus() == Define::JOB_STATUS_FAILED) { ?>
                        <a class="btn btn-app" onclick="rescheduleJob();">
                            <i class="fa fa-redo"></i> Reschedule
                        </a>
                    <?php } ?>

                    <!-- Download -->
                    <?php if($data['job']->getStatus() == Define::JOB_STATUS_FINISHED) { ?>
                        <a class="btn btn-app" href="<?php echo UPLOADED_DATA_PATH_RELATIVE; ?>evaluation/<?php echo $data['job']->getId(); ?>.zip">
                            <i class="fa fa-download"></i> Download
                        </a>
                    <?php } ?>

                    <!-- Job Navigation -->
                    <?php if($data['previousJob'] != null) { ?>
                        <a class="btn btn-app pull-right" href="/job/detail/id=<?php echo $data['previousJob'] ?>">
                            <i class="fa fa-arrow-circle-left"></i> Previous
                        </a>
                    <?php } ?>
                    <?php if($data['nextJob'] != null) { ?>
                        <a class="btn btn-app pull-right" href="/job/detail/id=<?php echo $data['nextJob'] ?>">
                            <i class="fa fa-arrow-circle-right"></i> Next
                        </a>
                    <?php } ?>

                    <!-- CDL -->
                    <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-cdl">
                        <i class="fa fa-code"></i> View CDL
                    </button>
                    <div class="modal fade" id="modal-cdl">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title">CDL</h4>
                                </div>
                                <div class="modal-body">
                                    <pre style="" class="prettyprint prettyprinted">
<code class="xml"><?php echo trim(htmlentities($data['cdl'])); ?></code>
							</pre>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <?php
                    $eventLibrary = new Event_Library();
                    echo $eventLibrary->renderTimeline($data['events']);
                    ?>
                </div>
			</div>
		</section>
	</form>
</div>
