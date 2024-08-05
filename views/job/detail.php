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


$this->includeAsset('ansi_js');
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
                status : " . Define::JOB_STATUS_SCHEDULED . "
            },
            type : 'PATCH',
            dataType: 'json'
        }).done(function() {
            location.reload(); 
        });
    }
    
    function updateAll() {
        var id = $('#id').val();
        var ansi_up = new AnsiUp;
        $.get('/api/ui/job/withLog=1/id=' + id, function(data, status) {
            updateProgressBar(data.response);
            $('#log').html(ansi_up.ansi_to_html(data.response.log).replace(/\\r\\n/g, '\\n').replace(/\\n/g, '<br>'));
            $('#log').scrollTop($('#log')[0].scrollHeight);
        });
    }
    
    function updateProgress() {
        var id = $('#id').val();
        $.get('/api/ui/job/withLog=0/id=' + id, function(data, status) {
            updateProgressBar(data.response);
        });
    }

    function updateProgressBar(data) {
        const phases = data.phases;
        const currentPhase = data.currentPhase;
        const progress = data.progress;
        const status = data.status;
        
        if (document.getElementById('progress-box') == null) {
            // There is no progress bar to update
            return;
        }
    
        let pastPhasesComplete = false;
        if (currentPhase == '') {
            pastPhasesComplete = true;
            
            const progressElementSetup = document.querySelector('.progress-bar.setup');
            if (progressElementSetup != null) {
                if (status == " . Define::JOB_STATUS_SETUP . ") {
                    progressElementSetup.style.width = '100%';
                } else {
                    progressElementSetup.style.width = '0%';
                }
            }
        } else {
            const progressElementSetup = document.querySelector('.progress-bar.setup');
            if (progressElementSetup != null) {
                progressElementSetup.style.width = '100%';
            }
        }
        
        phases.forEach((phase, index) => {
            const progressElement = document.querySelector('.progress-bar.' + phase.toLowerCase());
            if (phase === currentPhase) {
                progressElement.style.width = progress + '%';
                progressElement.classList.add('current-phase');
                pastPhasesComplete = true;
            } else if (!pastPhasesComplete) {
                progressElement.style.width = '100%';
                progressElement.classList.add('phase-completed');
            } else {
                progressElement.style.width = '0%';
            }
        });
    
        // Set the active phase
        if (currentPhase && currentPhase != '') {
            setActivePhase(currentPhase,status);
        }
    }
    
    $(document).ready(function(){
        updateAll();
        setInterval(function() {
            if ($('#autoupdateLog').prop('checked')) {
                updateAll();
            } else {
                updateProgress();
            }
        }, 2000);
        $('#log').scrollTop($('#log')[0].scrollHeight);
    });

    function setActivePhase(phase,status) {
        document.querySelectorAll('.progress').forEach(el => el.classList.remove('progress-striped', 'active'));
        if (phase.toLowerCase() != '') {
            if (status == " . Define::JOB_STATUS_FAILED . ") {
                document.querySelector('#progress-inner-' + phase.toLowerCase()).classList.remove('progress-bar-success');
                document.querySelector('#progress-inner-' + phase.toLowerCase()).classList.add('progress-bar-danger');
            } else {
                document.querySelector('#progress-outer-' + phase.toLowerCase()).classList.add('progress-striped', 'active');
            }
        }
    }
");

$this->includeInlineCSS("
    .btn-app {
        margin-left: 0;
        margin-bottom: 20px;
        margin-right: 10px;
    }
    
    .progress {
        height: 30px;
        border-radius: 4px;
        box-shadow: inset 0 1px 2px rgba(0,0,0,.1);
        margin: 0;
    }
    
    .progress-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
        color: #fff;
        text-align: center;
        transition: width 0.6s ease;
        font-size: 14px;
    }
    
    .phase {
        flex: 1;
        position: relative;
    }
    
    .phase-label {
        margin: 0 0 0 1px;
        color: gray;
    }
");
?>
<div class="content-wrapper">
    <form id="form" action="#" method="POST">
        <section class="content-header">
            <h1>
                Job Details
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
            <?php if ($data['job']->getStatus() != Define::JOB_STATUS_FINISHED && $data['job']->getStatus() != Define::JOB_STATUS_ABORTED) { ?>
                <div id="progress-box" class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Progress</h3>
                    </div>
                    <div class="box-body">
                        <div style="display: flex;width:100%">
                            <?php if ($data['cem']) { ?>
                                <div style="width:100%; padding-right:5px">
                                    <span class="phase-label">Setup</span>
                                    <div id="progress-outer-setup" class="progress">
                                        <div id="progress-inner-setup" class="progress-bar progress-bar-success setup phase" style="width: 0%;"></div>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php foreach ($data['phases'] as $phase) {
                                $lowercasePhase = strtolower($phase);
                                $progressBarClass = '';
                                $width = 100;
                                switch ($lowercasePhase) {
                                    case 'warmup':
                                    case 'prepare':
                                        $width = 150;
                                        break;
                                    case 'execute':
                                        $width = 400;
                                        break;
                                    case 'clean':
                                    case 'analyze':
                                        $width = 100;
                                        break;
                                }
                                ?>
                                <div style="width:<?php echo $width; ?>%; padding-right:5px">
                                    <span class="phase-label"><?php echo ucfirst($lowercasePhase); ?></span>
                                    <div id="progress-outer-<?php echo $lowercasePhase; ?>" class="progress">
                                        <div id="progress-inner-<?php echo $lowercasePhase; ?>" class="progress-bar progress-bar-success phase <?php echo $lowercasePhase; ?>" style="width: 0%;"></div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <div class="row">
                <div class="col-md-6">

                    <?php if ($data['cem']) { ?>
                        <div id="cemJob" class="alert alert-info">
                            <p style="font-size: 18px"><i class="icon fa fa-magic"></i>This job is automatically deployed by the Chronos Environment Management (CEM).</p>
                        </div>
                    <?php } ?>

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
                                <label>Job ID</label>
                                <input class="form-control" id="id" type="text" value="<?php echo $data['job']->getId(); ?>" disabled="">
                            </div>
                            <div class="form-group">
                                <label>User</label>
                                <input class="form-control" id="username" type="text" value="<?php echo $data['user']->getFirstname() . ' ' . $data['user']->getLastname() . ' (' . $data['user']->getUsername() . ')'; ?>" disabled="">
                            </div>
                            <div class="form-group">
                                <label>Environment</label>
                                <input class="form-control" id="environment" type="text" value="<?php echo $data['environment']; ?>" disabled="">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <input class="form-control" id="status" type="text" value="<?php
                                if ($data['job']->getStatus() == Define::JOB_STATUS_SCHEDULED) echo 'scheduled';
                                else if ($data['job']->getStatus() == Define::JOB_STATUS_SETUP) echo 'setup';
                                else if ($data['job']->getStatus() == Define::JOB_STATUS_RUNNING) echo 'running';
                                else if ($data['job']->getStatus() == Define::JOB_STATUS_FINISHED) echo 'finished';
                                else if ($data['job']->getStatus() == Define::JOB_STATUS_ABORTED) echo 'aborted';
                                else if ($data['job']->getStatus() == Define::JOB_STATUS_FAILED) echo 'failed';
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
                            <div style="overflow: auto; height: 400px; border: 1px solid #AAA; padding: 5px;" id="log"></div>
                        </div>
                    </div>

                </div>

                <div class="col-md-6">

                    <!-- Link to evaluation -->
                    <a class="btn btn-app" href="/evaluation/detail/id=<?php echo $data['evaluation']->getId(); ?>">
                        <i class="fa fa-link"></i> Evaluation
                    </a>

                    <!-- Abort -->
                    <?php if ($data['job']->getStatus() == Define::JOB_STATUS_SCHEDULED || $data['job']->getStatus() == Define::JOB_STATUS_SETUP || $data['job']->getStatus() == Define::JOB_STATUS_RUNNING || $data['job']->getStatus() == Define::JOB_STATUS_FAILED) { ?>
                        <a class="btn btn-app" onclick="abortJob();">
                            <i class="fa fa-ban"></i> Abort
                        </a>
                    <?php } ?>

                    <!-- Reschedule -->
                    <?php if ($data['job']->getStatus() == Define::JOB_STATUS_FINISHED || $data['job']->getStatus() == Define::JOB_STATUS_ABORTED || $data['job']->getStatus() == Define::JOB_STATUS_FAILED) { ?>
                        <a class="btn btn-app" onclick="rescheduleJob();">
                            <i class="fa fa-redo"></i> Reschedule
                        </a>
                    <?php } ?>

                    <!-- Download -->
                    <?php if ($data['job']->getStatus() == Define::JOB_STATUS_FINISHED) { ?>
                        <a class="btn btn-app" href="<?php echo UPLOADED_DATA_PATH_RELATIVE; ?>evaluation/<?php echo $data['job']->getId(); ?>.zip">
                            <i class="fa fa-download"></i> Download
                        </a>
                    <?php } ?>

                    <!-- Job Navigation -->
                    <?php if ($data['nextJob'] != null) { ?>
                        <a class="btn btn-app pull-right" href="/job/detail/id=<?php echo $data['nextJob'] ?>">
                            <i class="fa fa-arrow-circle-right"></i> Next
                        </a>
                    <?php } ?>
                    <?php if ($data['previousJob'] != null) { ?>
                        <a class="btn btn-app pull-right" href="/job/detail/id=<?php echo $data['previousJob'] ?>">
                            <i class="fa fa-arrow-circle-left"></i> Previous
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
