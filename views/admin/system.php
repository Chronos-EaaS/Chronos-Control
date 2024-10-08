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

// JS for deleting settings
use DBA\Setting;
use DBA\User;

$this->includeInlineJS("
var deleteLinks = document.querySelectorAll('.delete');

for (var i = 0; i < deleteLinks.length; i++) {
	deleteLinks[i].addEventListener('click', function(event) {
		event.preventDefault();

		var choice = confirm(this.getAttribute('data-confirm'));

		if (choice) {
			window.location.href = this.getAttribute('href');
		}
	});
}
");

$commits = "";
foreach ($data['history'] as $commit) {
    // ToDO: Improve
    $commit['author'] = "System";
    $commits .= "master.commit({message: \"" . $commit['message'] . "\", author: \"" . $commit['author'] . "\", sha1: \"" . substr($commit['hash'], 0, 7) . "\"});\n";
}
$this->includeInlineJS("
$(document).ready(function() {
    let isFirstTime = true;
    $('#modal-history').on('shown.bs.modal', function () {
        if (isFirstTime) {
            isFirstTime = false;
            var gitgraph = new GitGraph({
              template: 'metro',
              orientation: 'vertical',
              author: '',
              mode: 'extended' // or compact if you don't want the messages
            });
            var master = gitgraph.branch('master');    
            $commits
        }
    });
});
");

$this->includeInlineCSS("
        .btn-app {
            margin-left: 0;
            margin-bottom: 20px;
            margin-right: 10px;
        }
    ");
?>

<div class="content-wrapper">
	<section class="content-header">
		<h1>
            System: <?php echo $data['system']->getName(); ?>
		</h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/admin/systems">Systems</a></li>
            <li class="active">System</li>
        </ol>
	</section>

	<section class="content">
		<div class="row">
			<div class="col-md-6">

				<!-- General -->
				<div class="box box-default">
					<div class="box-header with-border">
						<h3 class="box-title">General<?php if($data['system']->getIsArchived()){echo " (Archived System)";} ?></h3>
                        <?php if( ($auth->isAdmin() || $data['system']->getUserId() == $auth->getUserID() ) && $data['system']->getIsArchived() == 0 ){ ?>
                            <a href="/admin/system/id=<?php echo $data['system']->getId(); ?>/archive=true"><button onclick="return confirm('Do you really want to archive this system?');" class="pull-right btn btn-danger">Archive this System</button></a>
                        <?php } ?>
					</div>
					<form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
						<div class="box-body">
							<div class="form-group">
								<label>Name</label>
								<input class="form-control required" name="name" id="name" type="text" value="<?php echo $data['system']->getName(); ?>" >
							</div>
							<div class="form-group">
								<label>Description</label>
								<textarea class="form-control" rows="8" name="description" id="description"><?php echo $data['system']->getDescription(); ?></textarea>
							</div>
                            <?php if($auth->isAdmin()) { ?>
                                <div class="form-group">
                                    <label>Owner</label>
                                    <select id="owner" name="owner" class="form-control required">
                                        <?php foreach ($data['users'] as $u) { /** @var $u User */ ?>
                                            <option <?php if($u->getId() == $data['system']->getUserId()) echo 'selected'; ?> value="<?php echo $u->getId(); ?>"><?php echo $u->getFirstname() . ' ' . $u->getLastname() . ' (' . $u->getUsername() . ')'; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            <?php } ?>
                            <?php if (ENABLE_REMOTE_REPOSITORY && $auth->isAdmin()) { ?>
                                <div class="form-group">
                                    <label>Repository</label>
                                    <input class="form-control required" name="repository" id="repository" value="<?php echo $data['system']->getVcsUrl(); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Repository Type</label>
                                    <select name="vcsType" class="form-control required" id="vcsType">
                                        <option value="git"<?php if($data['system']->getVcsType() == 'git') echo " selected" ?>>Git</option>
                                        <option value="hg"<?php if($data['system']->getVcsType() == 'hg') echo " selected" ?>>Mercurial</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Repository User</label>
                                    <input class="form-control required" name="vcsUser" id="vcsUser" value="<?php echo $data['system']->getVcsUser(); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Repository Password</label>
                                    <input type="password" class="form-control required" name="vcsPassword" id="vcsPassword" value="<?php echo $data['system']->getVcsPassword(); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Branch</label>
                                    <select id="branch" name="branch" class="form-control required">
                                        <?php foreach ($data['branches'] as $branch) { ?>
                                            <option <?php if($data['system']->getVcsBranch() == $branch) echo 'selected'; ?> value="<?php echo $branch; ?>"><?php echo $branch; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            <?php } ?>
						</div>
						<div class="box-footer">
							<input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
							<button type="submit" name="group" value="general" class="btn btn-primary pull-right">Save</button>
						</div>
					</form>
				</div>

				<!-- Environments -->
				<div class="box box-default">
					<div class="box-header with-border">
						<h3 class="box-title">Available Environments</h3>
					</div>
					<div class="box-body">
                        <?php if($data['system']->getCem()) { ?><p><i>Chronos Environment Management (CEM) enabling automated setup is enabled for this system. CEM-capable environments are maintained in the admin view. Additional environments added here are limited to custom setups.</i></p><?php } ?>
                        <?php foreach ($data['environments'] as $environment) { /** @var $environment Setting */ ?>
							<div class="form-group">
								<div class="input-group">
									<input class="form-control required" id="<?php echo $environment->getItem(); ?>" type="text" value="<?php echo $environment->getItem(); ?>" disabled>
									<span class="input-group-btn">
										<a class="btn btn-danger delete" href="/admin/system/id=<?php echo $data['system']->getId(); ?>/deleteEnvironment=<?php echo urlencode($environment->getItem()); ?>/" data-confirm="Are you sure to delete the environment '<?php echo $environment->getItem(); ?>'?">
											<i class="fa fa-trash" title="Delete" aria-hidden="true"></i>
											<span class="sr-only">Delete</span>
										</a>
									</span>
								</div>
							</div>
						<?php } ?>
					</div>
					<form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
						<div class="box-body">
							<div class="form-group">
								<label>Add Environment</label>
								<input class="form-control required" name="newEnvironmentName" id="newEnvironmentName" type="text">
							</div>
						</div>
						<div class="box-footer">
							<input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
							<button type="submit" name="group" value="newEnvironment" class="btn btn-primary pull-right">Add</button>
						</div>
					</form>
				</div>

                <?php if($data['system']->getCem()) { ?>
                    <!-- Setup Settings -->
                    <div class="box box-default">
                        <form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                            <div class="box-header with-border">
                                <h3 class="box-title">Additional Setup Settings <small>(Added to the setup-section of the CDL)</small></h3>
                            </div>
                            <div class="box-body">
                                <?php foreach ($data['setupOptions'] as $setting) { /** @var $setting Setting */ ?>
                                    <div class="form-group">
                                        <div class="input-group">
                                            <span class="input-group-addon text-bold" style="background-color: #f7f7f7;"><?php echo $setting->getItem(); ?></span>
                                            <input class="form-control required" name="<?php echo $setting->getItem(); ?>" id="<?php echo $setting->getItem(); ?>" type="text" value="<?php echo $setting->getValue(); ?>" autocomplete="off" >
                                            <span class="input-group-btn">
                                                <a class="btn btn-danger delete" href="/admin/system/id=<?php echo $data['system']->getId(); ?>/deleteSetupOption=<?php echo urlencode($setting->getItem()); ?>/" data-confirm="Are you sure to delete setting '<?php echo $setting->getItem(); ?>'?">
                                                    <i class="fa fa-trash" title="Delete" aria-hidden="true"></i>
                                                    <span class="sr-only">Delete</span>
                                                </a>
                                            </span>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="box-footer">
                                <input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
                                <button type="submit" name="group" value="setupOptions" class="btn btn-primary pull-right">Save</button>
                                <!-- Button to open the modal -->
                                <button type="button" class="btn btn-success pull-left" data-toggle="modal" data-target="#newSetupOptionModal">Add New Setting</button>
                            </div>
                        </form>
                    </div>
                    <div class="modal fade" id="newSetupOptionModal" tabindex="-1" role="dialog" aria-labelledby="newSetupOptionLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="newSetupOptionLabel">New Setup Setting</h5>
                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="form-group">
                                            <label>Key</label>
                                            <input class="form-control required" name="settingKey" id="settingKey" type="text">
                                        </div>
                                        <div class="form-group">
                                            <label>Value</label>
                                            <input class="form-control required" name="settingValue" id="settingValue" type="text">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                        <button type="submit" name="group" value="newSetupOption" class="btn btn-primary">Save</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php } ?>

			</div>

			<div class="col-md-6">

                <!-- Buttons -->
                <!-- System ID -->
                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-systemid">
                    <i class="fa fa-hashtag"></i> System ID
                </button>
                <div class="modal fade" id="modal-systemid">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <h4 class="modal-title">System ID</h4>
                            </div>
                            <div class="modal-body">
                                <pre style="" class="prettyprint prettyprinted"><code><?php echo trim(htmlentities($data['identifier'])); ?></code></pre>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Update -->
                <?php if (ENABLE_REMOTE_REPOSITORY && !empty($data['system']->getVcsUrl())) { ?>
                    <a class="btn btn-app" href="/admin/systemUpdate/id=<?php echo $data['system']->getId(); ?>">
                        <i class="fa fa-sync"></i> Update
                    </a>
                <?php } ?>
                <!-- VCS Log -->
                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-history">
                    <i class="fa fa-history"></i> History
                </button>
                <div class="modal fade" id="modal-history">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <h4 class="modal-title">History</h4>
                            </div>
                            <div class="modal-body">
                                <canvas id="gitGraph"></canvas>

                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Export -->
                <a class="btn btn-app" href="/admin/systemExport/id=<?php echo $data['system']->getId(); ?>">
                    <i class="fa fa-download"></i> Export
                </a>
                <!-- Import -->
                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-import">
                    <i class="fa fa-upload"></i> Import
                </button>
                <div class="modal fade" id="modal-import">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form action="/admin/systemImport/id=<?php echo $data['system']->getId(); ?>" enctype="multipart/form-data" method="post">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title">Import</h4>
                                </div>
                                <div class="modal-body">
                                    <input type="file" name="inputFile" id="inputFile">
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-warning pull-left">Upload</button>
                                    <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Upload Logo -->
                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-logo">
                  <i class="fa fa-file-image"></i> Upload Logo
                </button>
                <div class="modal fade" id="modal-logo">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                      <form action="/admin/system/id=<?php echo $data['system']->getId(); ?>/logo=upload" enctype="multipart/form-data" method="post">
                        <div class="modal-header">
                          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                          <h4 class="modal-title">Upload Logo</h4>
                        </div>
                        <div class="modal-body">
                          <input type="file" name="logoUpload" id="logoUpload">
                        </div>
                        <div class="modal-footer">
                          <button type="submit" class="btn btn-warning pull-left">Upload</button>
                          <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>

                <!-- CEM -->
                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-cem">
                    <i class="fa fa-magic"></i> CEM
                </button>
                <div class="modal fade" id="modal-cem">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form action="/admin/system/id=<?php echo $data['system']->getId(); ?>" enctype="multipart/form-data" method="post">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title">CEM</h4>
                                </div>
                                <div class="modal-body">
                                    Chronos Environment Management is <b><?php if($data['system']->getCem()) { ?>enabled<?php } else { ?>disabled<?php } ?></b> for this system.
                                    <input id="cem" name="cem" type="text" value="<?php echo intval(!$data['system']->getCem()); ?>" hidden>
                                    <button type="submit" name="setCem" value="1" class="btn btn-block <?php if($data['system']->getCem()) { ?>btn-danger<?php } else { ?>btn-success<?php } ?> btn-lg"><?php if($data['system']->getCem()) { ?>disable<?php } else { ?>enable<?php } ?></button>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Builder -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">System Builder</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-sm-12">
                                <button type="button" class="btn btn-block btn-primary btn-lg" onclick="location.href='/builder/build/id=<?php echo $data['system']->getId(); ?>';">Configure Parameters</button>
                            </div>
                        </div>
                        <br>
                        <b>Results Configurations</b>
                        <div class="row">
                            <div class="col-sm-12">
                                <table class="table">
                                    <tr>
                                        <th>ResultId</th>
                                        <th>Overall Results</th>
                                        <th>Job Results</th>
                                        <th style="width: 220px;">
                                            <form class="form-inline" role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                                                <button type="submit" name="newResult" value="1" class="btn btn-success pull-right">New</button>
                                            </form>
                                        </th>
                                    </tr>
                                    <?php foreach($data['results'] as $resultId => $result){ ?>
                                        <tr>
                                            <td>
                                                <?php echo $result['name'] ?> <?php if(strpos($resultId, "system") !== 0){
                                                    $split = explode("-", $resultId);
                                                ?><a href="/experiment/detail/id=<?php echo $split[1] ?>"><span class="fa fa-flask"></span></a><?php } ?>
                                            </td>
                                            <td>
                                                <a href="/results/build/systemId=<?php echo $data['system']->getId(); ?>/type=1/resultId=<?php echo $resultId ?>">Edit</a>
                                            </td>
                                            <td>
                                                <a href="/results/build/systemId=<?php echo $data['system']->getId(); ?>/type=2/resultId=<?php echo $resultId ?>">Edit</a>
                                            </td>
                                            <td>
                                                <form class="form-inline" onsubmit="return confirm('Do you really want to delete this result set?')" role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                                                    <input type="hidden" name="resultId" value="<?php echo $resultId ?>">
                                                    <button type="submit" name="deleteResult" value="1" class="btn btn-danger pull-right">Delete</button>
                                                </form>
                                                <form class="form-inline" role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                                                    <input type="hidden" name="resultId" value="<?php echo $resultId ?>">
                                                    <button type="submit" name="copyResult" value="1" style="margin-right: 5px;" class="btn btn-primary pull-right">Copy</button>
                                                </form>
                                                <button type="button" class="btn btn-info pull-right" style="margin-right: 5px;" data-toggle="modal" data-target="#modal-rename-<?php echo $resultId ?>">Rename</button>
                                                <div class="modal fade" id="modal-rename-<?php echo $resultId ?>">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                                                <h4 class="modal-title">Rename Result ID</h4>
                                                            </div>
                                                            <div class="modal-body">
                                                                <form class="form-inline" role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
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
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Default Values -->
                <div class="box box-default">
                    <form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                        <div class="box-header with-border">
                            <h3 class="box-title">Default Values</h3>
                        </div>
                        <div class="box-body">
                            <div class="form-group">
                                <label>Environment</label>
                                <select id="default_environment" name="default_environment" class="form-control">
                                    <option style="display:none"></option>
                                    <?php if(!empty($data['environments'])) { ?>
                                        <?php foreach ($data['environments'] as $environment) { ?>
                                            <option value="<?php echo $environment->getItem(); ?>" <?php if(isset($data['defaultValues']['environment']) && $data['defaultValues']['environment'] == $environment->getItem()) echo 'selected'; ?> ><?php echo $environment->getItem(); ?></option>
                                        <?php } ?>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Warm-up Phase</label>
                                <select id="default_phase_warmUp" name="default_phase_warmUp" class="form-control required">
                                    <option <?php if(!isset($data['defaultValues']['phase_warmUp']) || $data['defaultValues']['phase_warmUp'] == 'unchecked') echo 'selected'; ?> value="unchecked">unchecked</option>
                                    <option <?php if(isset($data['defaultValues']['phase_warmUp']) && $data['defaultValues']['phase_warmUp'] == 'checked') echo 'selected'; ?> value="checked">checked</option>
                                </select>
                            </div>
                        </div>
                        <div class="box-footer">
                            <input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
                            <button type="submit" name="group" value="defaultValues" class="btn btn-primary pull-right">Save</button>
                        </div>
                    </form>
                </div>

                <!-- Evaluation Settings -->
                <div class="box box-default">
                    <form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                        <div class="box-header with-border">
                            <h3 class="box-title">Additional Evaluation Settings <small>(Added to the evaluation-section of the CDL)</small></h3>
                        </div>
                        <div class="box-body">
                            <?php foreach ($data['settings'] as $setting) { /** @var $setting Setting */ ?>
                                <div class="form-group">
                                    <div class="input-group">
                                        <span class="input-group-addon text-bold" style="background-color: #f7f7f7;"><?php echo $setting->getItem(); ?></span>
                                        <input class="form-control required" name="<?php echo $setting->getItem(); ?>" id="<?php echo $setting->getItem(); ?>" type="text" value="<?php echo $setting->getValue(); ?>" autocomplete="off" >
                                        <span class="input-group-btn">
                                            <a class="btn btn-danger delete" href="/admin/system/id=<?php echo $data['system']->getId(); ?>/deleteEvaluationSetting=<?php echo urlencode($setting->getItem()); ?>/" data-confirm="Are you sure to delete setting '<?php echo $setting->getItem(); ?>'?">
                                                <i class="fa fa-trash" title="Delete" aria-hidden="true"></i>
                                                <span class="sr-only">Delete</span>
                                            </a>
                                         </span>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                        <div class="box-footer">
                            <input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
                            <button type="submit" name="group" value="settings" class="btn btn-primary pull-right">Save</button>
                            <!-- Button to open the modal -->
                            <button type="button" class="btn btn-success pull-left" data-toggle="modal" data-target="#newEvaluationSettingModal">Add New Setting</button>
                        </div>
                    </form>
                </div>
                <div class="modal fade" id="newEvaluationSettingModal" tabindex="-1" role="dialog" aria-labelledby="newEvaluationSettingLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="newEvaluationSettingLabel">New Setting</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label>Key</label>
                                        <input class="form-control required" name="settingKey" id="settingKey" type="text">
                                    </div>
                                    <div class="form-group">
                                        <label>Value</label>
                                        <input class="form-control required" name="settingValue" id="settingValue" type="text">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                    <button type="submit" name="group" value="newSetting" class="btn btn-primary">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

			</div>
		</div>
	</section>
</div>
