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
	    $('#nodes').DataTable({
	      'paging': true,
	      'lengthChange': false,
	      'searching': true,
	      'ordering': true,
	      'order': [[ 1, \"asc\" ]],
	      'info': true,
	      'autoWidth': true,
	      'pageLength': 25
	    });
  	});
  	
  	jQuery(document).ready(function($) {
        $('#nodes').on('click', '.clickable-row', function() {
            window.document.location = $(this).data('href');
        });
    });
	
	function reloadPage() {
	    var userStr = '';
	    if($('#showMissingNodes').prop('checked')) {
          userStr += 'missing=true/';
	    } 
	    window.document.location = '/cem/overview/' + userStr;
	}
");
?>

<div class="content-wrapper">
    <section class="content-header">
        <h1> Chronos Environment Management </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li class="active">CEM</li>
        </ol>
    </section>

	  <section class="content">

        <div class="box">
            <div class="box-body">
                <div class="checkbox">
                    <label>
                        <input id="showMissingNodes" type="checkbox" <?php if($data['showMissingNodes']) echo ' checked'; ?> onchange="reloadPage();">
                        Include missing nodes
                    </label>
                </div>
            </div>
        </div>


        <div class="box">
            <div class="box-body">
                <table id="nodes" class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 10px;">ID</th>
                            <th>Hostname</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($data['nodes'] as $n) { /** @var $n DBA\Node */ ?>
                            <tr class='clickable-row' data-href='/cem/detail/id=<?php echo $n->getId(); ?>' style="cursor: pointer;">
                                <td><?php echo $n->getId(); ?></td>
                                <td><?php echo $n->getHostname(); ?></td>
                                <td><?php if ($n->getCurrentJob() == null) { echo("Idle"); } else { echo "Working on job " . $n->getCurrentJob(); } ?></td>
                                <td><?php if (!empty($n->getHealthStatus())) { ?> <span class="glyphicon glyphicon-alert" style="color:yellow" title="Warnings detected"></span> <?php } ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

	  </section>

</div>
