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
    
    function fetchJobsData() {
        fetch('/api/ui/evaluation/id=" . $data['evaluation']->getId() . "/action=jobs')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                updateJobsTable(data.response.jobs);
            })
            .catch(error => {
                console.error('Error fetching jobs data:', error);
            });
    }

    function updateJobsTable(jobs) {
        var tbody = document.getElementById('jobs-tbody');
        var loadingDiv = document.getElementById('loading');
        var jobsTable = document.getElementById('jobs');

        tbody.innerHTML = ''; // Clear the table body

        jobs.forEach(function(job) {
            var tr = document.createElement('tr');
            tr.className = 'clickable-row';
            tr.setAttribute('data-href', '/job/detail/id=' + job.id);
            tr.style.cursor = 'pointer';

            var jobProgressHtml = '';
            if (job.status === 'RUNNING') {
                jobProgressHtml = '<span style=\"color:green;margin:0\">' +
                    (job.currentPhase ? job.currentPhase.charAt(0).toUpperCase() + job.currentPhase.slice(1).toLowerCase() : ' ') +
                    '</span><div style=\"margin-top:1px;\" class=\"progress progress-xs progress-xs progress-striped active\">' +
                    '<div class=\"progress-bar progress-bar-success\" style=\"width: ' + job.progress + '%\"></div></div>';
            }

            var jobStatusHtml = '';
            if (job.status === 'SCHEDULED') {
                jobStatusHtml = '<span class=\"label label-success\">scheduled</span>';
            } else if (job.status === 'SETUP') {
                jobStatusHtml = '<span class=\"label label-warning\">setup</span>';
            } else if (job.status === 'RUNNING') {
                jobStatusHtml = '<span class=\"label label-warning\">running</span>';
            } else if (job.status === 'FINISHED') {
                jobStatusHtml = '<span class=\"label label-info\">finished</span>';
            } else if (job.status === 'ABORTED') {
                jobStatusHtml = '<span class=\"label label-default\">aborted</span>';
            } else if (job.status === 'FAILED') {
                jobStatusHtml = '<span class=\"label label-danger\">failed</span>';
            }

            var downloadLinkHtml = '';
            if (job.status === 'FINISHED') {
                downloadLinkHtml = '<a href=\"/uploaded_data/evaluation/' + job.id + '.zip\"><i class=\"fa fa-download\"></i></a>';
            }

            tr.innerHTML = '<td>' + job.internalId + '</td>' +
                '<td>' + job.description + '</td>' +
                '<td style=\"padding: 2px 10px 2px 10px;\">' + jobProgressHtml + '</td>' +
                '<td>' + jobStatusHtml + '</td>' +
                '<td>' + downloadLinkHtml + '</td>';
            
            tbody.appendChild(tr);
        });

        // Hide the loading spinner and show the table
        loadingDiv.style.display = 'none';
        jobsTable.style.display = '';
        
        // Make rows clickable
        $(\".clickable-row\").click(function() {
            window.document.location = $(this).data(\"href\");
        });
    }

    // Fetch data every 10 seconds
    setInterval(fetchJobsData, 10000);
    // Fetch initial data immediately
    fetchJobsData();
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
                                <input class="form-control required" id="name" type="text" value="<?php echo $data['evaluation']->getName(); ?>">
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea class="form-control" rows="8" id="description"><?php echo $data['evaluation']->getDescription(); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Environment</label>
                                <input class="form-control" id="environment" type="text" value="<?php echo $data['environment']; ?>" disabled="">
                            </div>
                        </div>
                        <div class="box-footer">
                            <input id="id" type="text" value="<?php echo $data['evaluation']->getId(); ?>" hidden>
                            <button type="button" class="btn btn-primary pull-right" name="group" onclick="if(validateForm()) submitData();">Save
                            </button>
                        </div>
                    </div>
                </form>


            </div>
				
				<div class="col-md-6">


                    <div class="clearfix">
                        <!-- Show Results -->
                        <?php if ($data['resultsAvailable'] === true && $data['supportsShowResults'] === true) { ?>
                            <?php if (isset($data['noResultConfigSelected']) && $data['noResultConfigSelected'] === true) { ?>
                                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-default">
                                    <i class="fa fa-chart-bar "></i> Results
                                </button>

                                <!-- Modal -->
                                <div class="modal fade" id="modal-default">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                    <span aria-hidden="true">&times;</span></button>
                                                <h4 class="modal-title">No results configuration!</h4>
                                            </div>
                                            <div class="modal-body">
                                                <p>To display results, please select a result configuration in the <a href="/experiment/detail/id=<?php echo $data['experiment']->getId() ?>">experiment settings</a>.</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } else { ?>
                                <a class="btn btn-app" href="/results/show/id=<?php echo $data['evaluation']->getId(); ?>">
                                    <i class="fa fa-chart-bar "></i> Results
                                </a>
                            <?php } ?>
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

                    <?php if ($data['cem']) { ?>
                        <div id="cemJob" class="alert alert-info">
                            <p style="font-size: 18px"><i class="icon fa fa-magic"></i>These jobs are automatically deployed by the Chronos Environment Management (CEM).</p>
                        </div>
                    <?php } ?>

                    <!-- Jobs -->
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">Jobs</h3>
                        </div>
                        <div class="box-body">
                            <div id="loading" style="text-align: center;">
                                <i class="fa fa-spinner fa-spin" style="font-size: 24px;"></i>
                                Loading...
                            </div>
                            <table id="jobs" class="table table-hover" style="display: none;">
                                <thead>
                                <tr>
                                    <th style="width: 10px;">#</th>
                                    <th>Description</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th style="width: 10px"></th>
                                </tr>
                                </thead>
                                <tbody id="jobs-tbody">
                                <!-- Data will be dynamically loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>

				</div>
			</div>
		</section>
</div>
