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
	      'paging': false,
	      'lengthChange': false,
	      'searching': false,
	      'ordering': true,
	      'order': [[ 0, \"asc\" ]],
	      'info': false,
	      'autoWidth': false
	    });
  	});
  	
  	jQuery(document).ready(function($) {
		$(\".clickable-row\").click(function() {
			window.document.location = $(this).data(\"href\");
		});
	});
	
	function reloadPage() {
	    if($('#showArchivedSystems').prop('checked')) {
            var userStr = '';
	    } else {
            var userStr = 'archived=true/';
	    }
	    window.document.location = '/admin/systems/' + userStr;
	}
");
?>

<div class="content-wrapper">
	<section class="content-header">
		<h1> Systems </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li class="active">Systems</li>
        </ol>
	</section>

	<section class="content">
        <div class="box">
            <div class="box-body">
                <div class="checkbox">
                    <label>
                        <input id="showArchivedSystems" type="checkbox" <?php if(!$data['showArchivedSystems']) echo ' checked'; ?> onchange="reloadPage();">
                        Show archived systems
                    </label>
                </div>
            </div>
        </div>

		<div class="box">
			<div class="box-header with-border">
				<h3 class="box-title">The following systems are currently registered in Chronos:</h3>
			</div>
			<div class="box-body">
				<table id="evaluation" class="table table-hover">
					<thead>
						<tr>
							<th>Name</th>
							<th>Description</th>
							<th>Owner</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($data['systems'] as $s) { /** @var $s \DBA\System */ ?>
							<tr class='clickable-row' data-href='/admin/system/id=<?php echo $s->getId(); ?>' style="cursor: pointer;">
								<td><?php echo $s->getName(); if($s->getIsArchived()){echo " (Archived)";} ?></td>
								<td><?php echo $s->getDescription(); ?></td>
								<td><?php echo Util::getFullnameOfUser($s->getUserId()) ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
			<div class="box-footer">
				<button onclick="location.href='/admin/createSystem/';" class="btn btn-primary pull-right">Add System</button>
			</div>
		</div>
	</section>

</div>
