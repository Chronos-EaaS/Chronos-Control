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
            url : '/api/ui/project/id=' + $('#id').val(),
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
	    
	    $('#experiment').DataTable({
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
  	
  	jQuery(document).ready(function($) {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
");
?><div class="content-wrapper">
    <section class="content-header">
        <h1>
            Project: <?php echo $data['project']->getName() ?>
        </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/project/overview">Projects</a></li>
            <li class="active">Project</li>
        </ol>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-6">

                <!-- General -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">General<?php if($data['project']->getIsArchived()){echo " (Archived Project)";} ?></h3>
                        <?php if(($data['project']->getUserId() == $auth->getUserID() || $auth->isAdmin()) && $data['project']->getIsArchived() == 0){ ?>
                            <a href="/project/detail/id=<?php echo $data['project']->getId(); ?>/archive=true"><button onclick="return confirm('Do you really want to archive this project?');" class="pull-right btn btn-danger">Archive this Project</button></a>
                        <?php }  else if(($data['project']->getUserId() == $auth->getUserID() || $auth->isAdmin()) && $data['project']->getIsArchived() == 1){ ?>
                            <a href="/project/detail/id=<?php echo $data['project']->getId(); ?>/unarchive=true"><button  onclick="return confirm('Do you really want to un-archive this project?');" class="pull-right btn btn-danger">Un-Archive this Project</button></a>
                        <?php } ?>
                    </div>
                    <form id="form" action="#" method="POST">
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
                                <input class="form-control required" id="name" type="text" value="<?php echo $data['project']->getName(); ?>" >
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea class="form-control" rows="8" id="description"><?php echo $data['project']->getDescription(); ?></textarea>
                            </div>
                        </div>
                    </form>
                    <div class="box-footer">
                        <input id="id" type="text" value="<?php echo $data['project']->getId(); ?>" hidden>
                        <button type="button" class="btn btn-primary pull-right" name="group" onclick="if(validateForm()) submitData();">Save</button>
                    </div>
                </div>

                <!-- Experiments -->
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title"><?php if(!empty($data['show']) && $data['show'] == 'archived'){ echo "Archived "; } ?>Experiments</h3>
                        <?php if(!empty($data['show']) && $data['show'] == 'archived'){ ?>
                            <a href='/project/detail/id=<?php echo $data['project']->getId()?>' class="btn btn-primary pull-right" data-toggle="tooltip" data-placement="top" title="Show Current">
                                Show Current
                            </a>
                        <?php } else { ?>
                            <a href='/project/detail/id=<?php echo $data['project']->getId()?>/show=archived' class="btn btn-primary pull-right" data-toggle="tooltip" data-placement="top" title="Show Archived">
                                Show Archived
                            </a>
                        <?php } ?>
                    </div>
                    <div class="box-body">
                        <table id="experiment" class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 10px;">#</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th style="min-width: 80px;">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($data['experiments'] as $e) { /** @var $e Experiment */ ?>
                                    <tr class='clickable-row' data-href='/experiment/detail/id=<?php echo $e->getId(); ?>' style="cursor: pointer;">
                                        <td><?php echo $e->getInternalId(); ?></td>
                                        <td><?php echo $e->getName(); ?></td>
                                        <td><?php echo $e->getDescription(); ?></td>
                                        <td>
                                            <a href='/builder/create/projectId=<?php echo $data['project']->getId()?>/copyExperimentId=<?php echo $e->getId(); ?>' class="btn btn-primary pull-right" data-toggle="tooltip" data-placement="top" title="Copy">
                                                <span class="fa fa-copy"></span>
                                            </a>
                                            <?php if(!empty($data['show']) && $data['show'] == 'archived'){ ?>
                                                <form class="form-inline pull-right" role="form" action="/project/detail/id=<?php echo $data['project']->getId()?>" method="post">
                                                    <input type="hidden" name="experimentId" value="<?php echo $e->getId(); ?>">
                                                    <button type="submit" name="unarchiveExperiment" value="1" style="margin-right: 5px;" class="btn btn-success pull-right"><span class="fa fa-box-open"></span></button>
                                                </form>
                                            <?php } else { ?>
                                                <form class="form-inline pull-right" role="form" action="/project/detail/id=<?php echo $data['project']->getId()?>" method="post">
                                                    <input type="hidden" name="experimentId" value="<?php echo $e->getId(); ?>">
                                                    <button type="submit" name="archiveExperiment" value="1" style="margin-right: 5px;" class="btn btn-warning pull-right"><span class="fa fa-archive"></span></button>
                                                </form>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="box-footer">
                        <a href='/builder/create/projectId=<?php echo $data['project']->getId()?>' class="btn btn-primary pull-right">Create Experiment</a>
                    </div>
                </div>

                <!-- Evaluations -->
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Currently running</h3>
                    </div>
                    <div class="box-body">
                        <table id="evaluation" class="table table-hover">
                            <thead>
                            <tr>
                                <th style="width: 10px;">#</th>
                                <th>Name</th>
                                <th>Description</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach($data['evaluations'] as $e) { /** @var $e Evaluation */ ?>
                                <tr class='clickable-row' data-href='/evaluation/detail/id=<?php echo $e->getId(); ?>' style="cursor: pointer;">
                                    <td><?php echo $data['experiments-ds']->getVal($e->getExperimentId())->getInternalId()."-".$e->getInternalId(); ?></td>
                                    <td><?php echo $e->getName(); ?></td>
                                    <td><?php echo $e->getDescription(); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if(sizeof($data['starredEvaluations']) > 0) { ?>
                    <!-- Starred Evaluations -->
                    <div class="box">
                        <div class="box-header with-border">
                            <h3 class="box-title">Starred Evaluations</h3>
                        </div>
                        <div class="box-body">
                            <table id="evaluation" class="table table-hover">
                                <thead>
                                <tr>
                                    <th style="width: 10px;">#</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach($data['starredEvaluations'] as $e) { /** @var $e Evaluation */ ?>
                                    <tr class='clickable-row' data-href='/evaluation/detail/id=<?php echo $e->getId(); ?>' style="cursor: pointer;">
                                        <td><?php echo $data['experiments-ds']->getVal($e->getExperimentId())->getInternalId()."-".$e->getInternalId(); ?></td>
                                        <td><?php echo $e->getName(); if($e->getIsStarred()){echo " <span class='fa fa-star'></span>";} ?></td>
                                        <td><?php echo $e->getDescription(); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php } ?>

                <?php if($data['project']->getUserId() == $data['loginUser'] || $auth->isAdmin()){ ?>
                    <!-- Users -->
                    <div class="box">
                        <div class="box-header with-border">
                            <h3 class="box-title">Members</h3>
                        </div>
                        <div class="box-body">
                            <table id="experiment" class="table table-hover">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>&nbsp;</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach($data['members'] as $m) { /** @var $m User */ ?>
                                    <tr>
                                        <td><?php echo $m->getFirstname() . ' ' . $m->getLastname() . ' (' . $m->getUsername() . ')'; ?></td>
                                        <?php if($m->getId() != $data['project']->getUserId()){ ?>
                                        <td><a href="/project/detail/id=<?php echo $data['project']->getId() ?>/remove=<?php echo $m->getId(); ?>"><button class="btn btn-danger">Remove</button></a></td>
                                        <?php }else { echo "<td>Owner</td>"; }?>
                                    </tr>
                                <?php } if(sizeof($data['members']) == 0){echo "<tr><td>---</td><td>---</td></tr>"; } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="box-footer">
                            <form action="/project/detail/id=<?php echo $data['project']->getId() ?>" method="post" class="form-inline">
                                <select name="member" class="form-control" title="User">
                                    <?php if (isset($data['users'])) {
                                        foreach($data['users'] as $user) { /** @var $user \DBA\User */ ?>
                                            <option value="<?php echo $user->getId(); ?>"><?php echo $user->getFirstname() . ' ' . $user->getLastname() . ' (' . $user->getUsername() . ')'; ?></option>
                                        <?php }
                                    }
                                    else { ?>
                                        <option value="no members found"><?php echo "No users found."/** Error, data['users'] undefined */?></option>
                                        <?php } ?>
                                </select>
                                <button type="submit" class="btn btn-success">Add as Member</button>
                            </form>
                        </div>
                    </div>
                <?php } ?>

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
</div>