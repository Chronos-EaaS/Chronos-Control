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

$this->includeAsset('datatables');
$this->includeAsset('ionicons');
$this->includeInlineJS("
	$(function () {
	    $('#evaluation').DataTable({
	      'paging': false,
	      'lengthChange': false,
	      'searching': false,
	      'ordering': false,
	      'order': [[ 0, \"desc\" ]],
	      'info': false,
	      'autoWidth': false,
	      'pageLength': 25
	    });
  	});
  	
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
            url : '/api/ui/experiment/id=' + $('#id').val(),
            data : {
                name : $('#name').val(),
                description : $('#description').val(),
                environment: $('#default-environment').val()
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
    
                    // Update the environment dropdown in the run-modal with the new default value
                    var newEnvironment = $('#default-environment').val();
                    $('#environment option').each(function() {
                        if ($(this).val() == newEnvironment) {
                            $(this).prop('selected', true);
                        } else {
                            $(this).prop('selected', false);
                        }
                    });
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
?>
<div class="content-wrapper">
    <form id="form" action="#" method="POST">
        <section class="content-header">
            <h1>
                Experiment: <?php echo $data['experiment']->getName() ?>
            </h1>
            <ol class="breadcrumb">
                <li><a href="/home/main">Home</a></li>
                <li><a href="/project/detail/id=<?php echo $data['experiment']->getProjectId() ?>">Project</a></li>
                <li class="active">Experiment Details</li>
            </ol>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-6">
                    <div class="box box-info">
                        <div class="box-header with-border">
                            <h3 class="box-title">General</h3>
                        </div>
                        <form role="form" action="#" method="post">
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
                                    <label for="name">Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo $data['experiment']->getName(); ?>" required="required">
                                </div>
                                <div class="form-group">
                                    <label for="description">Description</label>
                                    <textarea class="form-control" rows="8" name="description" id="description"><?php echo $data['experiment']->getDescription() ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Default Environment</label>
                                    <select id="default-environment" name="default-environment" class="form-control" required>
                                        <?php if(!empty($data['environments'])) { ?>
                                            <?php foreach ($data['environments'] as $environment) { ?>
                                                <option value="<?php echo $environment->key; ?>" <?php if($environment->default) echo 'selected'; ?>><?php echo $environment->displayStr; ?></option>
                                            <?php } ?>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="box-footer">
                                    <input id="id" type="text" value="<?php echo $data['experiment']->getId(); ?>" hidden>
                                    <button type="button" class="btn btn-primary pull-right" name="group" value="settings" onclick="if(validateForm()) submitData();">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Results -->
                    <div class="box">
                        <div class="box-header with-border">
                            <h3 class="box-title">Results Configuration</h3>
                        </div>
                        <div class="box-body">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Overall Results</th>
                                        <th>Job Results</th>
                                        <th style="width: 300px;">&nbsp;</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($data['results'] as $resultId => $result) { ?>
                                        <tr>
                                            <td><?php echo $result['name']; ?>
                                                <?php if(strpos($resultId, "system") === 0){?>
                                                    <span class="fa fa-cubes text-blue"></span>
                                                <?php } ?>
                                                <?php if($resultId == $data['experiment']->getResultId()){echo "<span class='fa fa-check-square'></span>";} ?></td>
                                            <td>
                                                <?php if(strpos($resultId, "system") === 0){ ?>
                                                    ---
                                                <?php }else{ ?>
                                                    <a href="/results/build/experimentId=<?php echo $data['experiment']->getId(); ?>/type=1/resultId=<?php echo $resultId ?>">Edit</a>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <?php if(strpos($resultId, "system") === 0){ ?>
                                                    ---
                                                <?php }else{ ?>
                                                    <a href="/results/build/experimentId=<?php echo $data['experiment']->getId(); ?>/type=2/resultId=<?php echo $resultId ?>">Edit</a>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <form class="form-inline" onsubmit="return confirm('Do you really want to delete this result set?')" role="form" action="/experiment/detail/id=<?php echo $data['experiment']->getId(); ?>" method="post">
                                                    <input type="hidden" name="resultId" value="<?php echo $resultId ?>">
                                                    <button type="submit" name="deleteResult" value="1" class="btn btn-danger pull-right" <?php if(strpos($resultId, "system") === 0){ ?>disabled<?php } ?>>Delete</button>
                                                </form>
                                                <form class="form-inline" role="form" action="/experiment/detail/id=<?php echo $data['experiment']->getId(); ?>" method="post">
                                                    <input type="hidden" name="resultId" value="<?php echo $resultId ?>">
                                                    <button type="submit" name="copyResult" value="1" style="margin-right: 5px;" class="btn btn-primary pull-right">Copy</button>
                                                </form>
                                                <button type="button" class="btn btn-info pull-right" style="margin-right: 5px;" data-toggle="modal" data-target="#modal-rename-<?php echo $resultId ?>" <?php if(strpos($resultId, "system") === 0){ ?>disabled<?php } ?>>Rename</button>
                                                <div class="modal fade" id="modal-rename-<?php echo $resultId ?>">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                                                <h4 class="modal-title">Rename Result ID</h4>
                                                            </div>
                                                            <div class="modal-body">
                                                                <form class="form-inline" role="form" action="/experiment/detail/id=<?php echo $data['experiment']->getId(); ?>" method="post">
                                                                    <input type="hidden" name="resultId" value="<?php echo $resultId ?>">
                                                                    <input type="text" class="form-control" name="newName" value="<?php echo $result['name'] ?>">
                                                                    <button type="submit" name="renameResult" value="1" style="margin-right: 5px;" class="btn btn-primary">Rename</button>
                                                                </form>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <form class="form-inline" role="form" action="/experiment/detail/id=<?php echo $data['experiment']->getId(); ?>/select=<?php echo $resultId ?>" method="post">
                                                    <button type="submit" value="1" style="margin-right: 5px;" class="btn btn-default pull-right" <?php if($resultId == $data['experiment']->getResultId()){echo "disabled";} ?>>Select</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="box-footer">
                            <form action="/experiment/detail/id=<?php echo $data['experiment']->getId() ?>" method="post">
                                <input type="hidden" name="createResult" value="1">
                                <button type="submit" class="pull-right btn btn-success">Create New</button>
                            </form>
                        </div>
                    </div>

                    <!-- Evaluations -->
                    <div class="box">
                        <div class="box-header with-border">
                            <h3 class="box-title"><?php if(!empty($data['show']) && $data['show'] == 'archived'){ echo "Archived "; } ?>Evaluations</h3>
                            <?php if(!empty($data['show']) && $data['show'] == 'archived'){ ?>
                                <a href='/experiment/detail/id=<?php echo $data['experiment']->getId()?>' class="btn btn-primary pull-right" data-toggle="tooltip" data-placement="top" title="Show Current">
                                    Show Current
                                </a>
                            <?php } else { ?>
                                <a href='/experiment/detail/id=<?php echo $data['experiment']->getId()?>/show=archived' class="btn btn-primary pull-right" data-toggle="tooltip" data-placement="top" title="Show Archived">
                                    Show Archived
                                </a>
                            <?php } ?>
                        </div>
                        <div class="box-body">
                            <table id="evaluation" class="table table-hover">
                                <thead>
                                <tr>
                                    <th style="width: 10px;">#</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th style="min-width: 80px;">&nbsp;</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach($data['evaluations'] as $e) { /** @var $e Evaluation */ ?>
                                    <tr class='clickable-row' data-href='/evaluation/detail/id=<?php echo $e->getId(); ?>' style="cursor: pointer;">
                                        <td><?php echo $e->getInternalId(); ?></td>
                                        <td><?php echo $e->getName(); if($e->getIsStarred()){echo " <span class='fa fa-star'></span>";} ?></td>
                                        <td><?php echo $e->getDescription(); ?></td>
                                        <td>
                                            <?php if(!empty($data['show']) && $data['show'] == 'archived'){ ?>
                                                <form class="form-inline pull-right" role="form" action="/experiment/detail/id=<?php echo $data['experiment']->getId()?>" method="post">
                                                    <input type="hidden" name="evaluationId" value="<?php echo $e->getId(); ?>">
                                                    <button type="submit" name="unarchiveEvaluation" value="1" style="margin-right: 5px;" class="btn btn-success pull-right"><span class="fa fa-box-open"></span></button>
                                                </form>
                                            <?php } else { ?>
                                                <form class="form-inline pull-right" role="form" action="/experiment/detail/id=<?php echo $data['experiment']->getId()?>" method="post">
                                                    <input type="hidden" name="evaluationId" value="<?php echo $e->getId(); ?>">
                                                    <button type="submit" name="archiveEvaluation" value="1" style="margin-right: 5px;" class="btn btn-warning pull-right"><span class="fa fa-archive"></span></button>
                                                </form>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="box-footer">
                            <button data-toggle="modal" data-target="#modal-run-evaluation" type="button"  class="btn btn-primary pull-right">Run Evaluation</button>
                        </div>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="col-md-6">
                    <?php
                    $eventLibrary = new Event_Library();
                    echo $eventLibrary->renderTimeline($data['events']);
                    ?>
                </div>
            </div>
        </section>
    </form>
</div>

<div class="modal fade" id="modal-run-evaluation">
    <form id="run-evaluation-form" action="/builder/run" method="POST">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"
                            onclick="document.getElementById('form').reset()">
                        <span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Select Environment</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="experimentId" value="<?php echo $data['experiment']->getId() ?>">
                    <div class="form-group">
                        <label>Environment</label>
                        <select class="form-control" id="environment" name="environment" title="environment" required>
                            <?php if(!empty($data['environments'])) { ?>
                                <?php foreach ($data['environments'] as $environment) { ?>
                                    <option value="<?php echo $environment->key; ?>" <?php if($environment->default) echo 'selected'; ?>><?php echo $environment->displayStr; ?></option>
                                <?php } ?>
                            <?php } ?>
                        </select>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="document.getElementById('run-evaluation-form').submit();">Run</button>
                    <button type="button" class="btn btn-default pull-left" data-dismiss="modal" onclick="document.getElementById('run-evaluation-form').reset()">Cancel</button>
                </div>
            </div>
        </div>
    </form>
</div>