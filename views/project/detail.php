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
            url : '/api/v1/project/id=' + $('#id').val(),
            data : {
                name : $('#name').val(),
                description : $('#description').val()
            },
            type : 'PATCH',
            dataType: 'json'
        }).done(function() {
            $('#saveResultBox').show();
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
    <form id="form" action="#" method="POST">
        <section class="content-header">
            <h1>
                Project: <?php echo $data['project']->getName() ?>
            </h1>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-6">

                    <!-- General -->
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">General</h3>
                        </div>
                        <div class="box-body">
                            <div id="saveResultBox" style="display:none;" class="alert alert-success alert-dismissible">
                                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                                <span id="saveResult"><h4><i class="icon fa fa-check"></i> Success</h4></span>
                            </div>
                            <div class="form-group">
                                <label>Name</label>
                                <input class="form-control required" id="name" type="text" value="<?php echo $data['project']->getName(); ?>" >
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea class="form-control required" rows="8" id="description"><?php echo $data['project']->getDescription(); ?></textarea>
                            </div>
                        </div>
                        <div class="box-footer">
                            <input id="id" type="text" value="<?php echo $data['project']->getId(); ?>" hidden>
                            <button type="button" class="btn btn-primary pull-right" name="group" onclick="if(validateForm()) submitData();">Save</button>
                        </div>
                    </div>

                    <!-- Experiments -->
                    <div class="box">
                        <div class="box-header with-border">
                            <h3 class="box-title">Experiments</h3>
                        </div>
                        <div class="box-body">
                            <table id="experiment" class="table table-hover">
                                <thead>
                                <tr>
                                    <th style="width: 10px;">#</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach($data['experiments'] as $e) { /** @var $e Experiment */ ?>
                                    <tr class='clickable-row' data-href='/experiment/detail/id=<?php echo $e->getId(); ?>' style="cursor: pointer;">
                                        <td><?php echo $e->getInternalId(); ?></td>
                                        <td><?php echo $e->getName(); ?></td>
                                        <td><?php echo $e->getDescription(); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="box-footer">
                            <a href='/system/<?php echo strtolower($data['system']->getName()); ?>/wizard/projectId=<?php echo $data['project']->getId()?>' class="btn btn-primary pull-right">Create Experiment</a>
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