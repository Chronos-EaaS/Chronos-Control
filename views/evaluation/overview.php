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
	      'paging': true,
	      'lengthChange': false,
	      'searching': false,
	      'ordering': true,
	      'order': [[ 0, \"desc\" ]],
	      'info': true,
	      'autoWidth': false,
	      'pageLength': 25
	    });
	    $('#f-evaluation').DataTable({
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
	$('#evaluation').bind('DOMSubtreeModified', function() {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
	
	function reloadPage() {
	    if($('#showAllUser').prop('checked')) {
            var userStr = '';
	    } else {
            var userStr = 'user=all/';
	    }
	    window.document.location = '/evaluation/overview/' + userStr;
	}
");
?>

<div class="content-wrapper">
	<section class="content-header">
		<h1> Evaluations </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li class="active">Evaluations</li>
        </ol>
	</section>

	<section class="content">

        <?php if($auth->isAdmin()) { ?>
            <div class="box">
                <div class="box-body">
                    <div class="checkbox">
                        <label>
                            <input id="showAllUser" type="checkbox" <?php if(!$data['showAllUser']) echo ' checked'; ?> onchange="reloadPage();">
                            Show only my own evaluations
                        </label>
                    </div>
                </div>
            </div>
        <?php } ?>


        <div class="box">
			<div class="box-header with-border">
				<h3 class="box-title">Running Evaluations</h3>
			</div>
			<div class="box-body">
				<table id="evaluation" class="table table-hover">
					<thead>
						<tr>
							<th style="width: 20px;">#</th>
							<th>Name</th>
                            <th>Experiment</th>
                            <th>Project</th>
							<th>System</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($data['evaluations-running'] as $e) { /** @var $e Evaluation */ ?>
							<tr class='clickable-row' data-href='/evaluation/detail/id=<?php echo $e->getId(); ?>' style="cursor: pointer;">
								<td><?php echo $data['experiments']->getVal($e->getExperimentId())->getId()."-".$data['experiments']->getVal($e->getExperimentId())->getInternalId()."-".$e->getInternalId(); ?></td>
								<td><?php echo $e->getName(); ?></td>
                                <td><?php echo $data['experiments']->getVal($e->getExperimentId())->getName(); ?></td>
                                <td><?php echo $data['projects']->getVal($data['experiments']->getVal($e->getExperimentId())->getProjectId())->getName(); ?></td>
								<td><?php echo $data['systems']->getVal($e->getSystemId())->getName(); ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
        </div>
	</section>

</div>
