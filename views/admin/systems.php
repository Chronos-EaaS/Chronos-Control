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
            var userStr = 'archived=true/';
	    } else {
            var userStr = '';
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
                        <input id="showArchivedSystems" type="checkbox" <?php if($data['showArchivedSystems']) echo ' checked'; ?> onchange="reloadPage();">
                        Show archived systems
                    </label>
                </div>
            </div>
        </div>

        <div class="row">
            <?php foreach($data['systems'] as $s) { /** @var $s \DBA\System */ ?>
                <div class="col-md-3">
                    <div class="box box-primary">
                        <div class="box-body box-profile">
                            <img class="profile-user-img img-responsive" src="/systems/<?php echo $s->getId();?>/logo.png" alt="User profile picture">
                            <h3 class="profile-username text-center"><?php echo $s->getName(); if($s->getIsArchived()){echo " (Archived)";} ?></h3>
                            <p class="text-muted text-center"><?php echo $s->getDescription(); ?>&nbsp;</p>
                            <ul class="list-group list-group-unbordered">
                                <li class="list-group-item">
                                    <b>Owner</b> <span class="pull-right"><?php echo Util::getFullnameOfUser($s->getUserId()) ?></span>
                                </li>
                            </ul>

                            <a href="/admin/system/id=<?php echo $s->getId(); ?>" class="btn btn-primary btn-block"><b>View</b></a>
                        </div>
                    </div>
                </div>
            <?php } ?>

            <?php if($auth->isAdmin()){ ?>
                <div class="col-md-3">
                    <div class="box box-primary">
                        <div class="box-body box-profile">
                            <img class="profile-user-img img-responsive" src="/images/plus.png" alt="Create new System">
                            <h3 class="profile-username text-center">&nbsp;</h3>
                            <p class="text-muted text-center">&nbsp;</p>
                            <ul class="list-group list-group-unbordered">
                                <li class="list-group-item">
                                    <b>&nbsp;</b> <span class="pull-right">&nbsp;</span>
                                </li>
                            </ul>

                            <a href="/admin/createSystem/" class="btn btn-success btn-block"><b>Add System</b></a>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
	</section>

</div>
