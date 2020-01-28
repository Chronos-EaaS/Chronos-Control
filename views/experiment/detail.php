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
            url : '/api/v1/experiment/id=' + $('#id').val(),
            data : {
                name : $('#name').val(),
                description : $('#description').val(),
                deployment: $('#deployment').val()
            },
            type : 'PATCH',
            dataType: 'json',
            error: function (request, status, error) {
                $('#saveResultError').show();
            }
        }).done(function() {
            $('#saveResultSuccess').show();
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
                                    <a class="close" onclick="$('.alert').hide()">×</a>
                                    <span id="saveResult"><h4><i class="icon fa fa-check"></i> Success</h4></span>
                                </div>
                                <div id="saveResultError" style="display:none;" class="alert alert-danger">
                                  <a class="close" onclick="$('.alert').hide()">×</a>
                                  <span id="saveResult"><h4><i class="icon fa fa-times-circle"></i> Error</h4></span>
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
                                    <label>Select deployment</label>
                                    <select id="deployment" name="deployment" class="form-control">
                                        <?php if(!empty($data['deployments'])) { ?>
                                            <?php foreach ($data['deployments'] as $deployment) { ?>
                                                <option value="<?php echo $deployment->getItem(); ?>" <?php if(isset(json_decode($data['experiment']->getPostData(), true)['deployment']) && json_decode($data['experiment']->getPostData(), true)['deployment'] == $deployment->getItem()) echo 'selected'; ?>><?php echo $deployment->getItem(); ?></option>
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

                    <!-- Evaluations -->
                    <div class="box">
                        <div class="box-header with-border">
                            <h3 class="box-title">Evaluations</h3>
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
                                        <td><?php echo $e->getInternalId(); ?></td>
                                        <td><?php echo $e->getName(); ?></td>
                                        <td><?php echo $e->getDescription(); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="box-footer">
                            <a href="/builder/run/experimentId=<?php echo $data['experiment']->getId() ?>" class="btn btn-primary pull-right" name="group" value="settings">Run Evaluation</a>
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