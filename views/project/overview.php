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
	    window.document.location = '/project/overview/' + userStr;
	}
	
	function reloadPageArchive(id) {
	    if($('#showArchivedProjects').prop('checked')) {
            var userStr = '/archived=true/';
	    } else {
            var userStr = '';
	    }
	    window.document.location = '/project/overview/' + userStr;
	}
");
?>

<div class="content-wrapper">
	<section class="content-header">
		<h1> Projects </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li class="active">Projects</li>
        </ol>
	</section>

	<section class="content">

        <div class="box">
            <div class="box-body">
                <?php if($auth->isAdmin()) { ?>
                    <div class="checkbox">
                        <label>
                            <input id="showAllUser" type="checkbox" <?php if(!$data['showAllUser']) echo ' checked'; ?> onchange="reloadPage();">
                            Show only my own projects
                        </label>
                    </div>
                <?php } ?>
                <div class="checkbox">
                    <label>
                        <input id="showArchivedProjects" type="checkbox" <?php if($data['showArchivedProjects']) echo ' checked'; ?> onchange="reloadPageArchive();">
                        Show archived projects
                    </label>
                </div>
            </div>
        </div>


        <div class="box">
			<div class="box-body">
				<table id="evaluation" class="table table-hover">
					<thead>
						<tr>
							<th style="width: 10px;">#</th>
							<th>Name</th>
							<th>Description</th>
							<th>System</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($data['projects'] as $p) { /** @var $p DataSet */ ?>
							<tr class='clickable-row' data-href='/project/detail/id=<?php echo $p->getVal('projectId'); ?>' style="cursor: pointer;">
								<td><?php echo $p->getVal('projectId'); if($p->getVal('isArchived')){echo " (Archived)";} ?></td>
								<td><?php echo $p->getVal('name'); ?></td>
								<td><?php echo $p->getVal('description'); ?></td>
								<td><?php echo $p->getVal('systemName'); ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
            <div class="box-footer">
                <button onclick="location.href='/project/create/';" class="btn btn-primary pull-right">Add Project</button>
            </div>
		</div>

	</section>

</div>
