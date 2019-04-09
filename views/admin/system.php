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
                        <?php if($data['system']->getUserId() == $data['auth']->getUserID() && $data['system']->getIsArchived() == 0){ ?>
                            <a href="/admin/system/id=<?php echo $data['system']->getId(); ?>/archive=true"><button class="pull-right btn btn-danger">Archive this System</button></a>
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
							<div class="form-group">
								<label>Owner</label>
								<select id="owner" name="owner" class="form-control required">
									<?php foreach ($data['users'] as $u) { /** @var $u User */ ?>
										<option <?php if($u->getId() == $data['system']->getUserId()) echo 'selected'; ?> value="<?php echo $u->getId(); ?>"><?php echo $u->getFirstname() . ' ' . $u->getLastname() . ' (' . $u->getUsername() . ')'; ?></option>
									<?php } ?>
								</select>
							</div>
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
						</div>
						<div class="box-footer">
							<input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
							<button type="submit" name="group" value="general" class="btn btn-primary pull-right">Save</button>
						</div>
					</form>
				</div>

				<!-- Deployments -->
				<div class="box box-default">
					<div class="box-header with-border">
						<h3 class="box-title">Available deployments</h3>
					</div>
					<div class="box-body">
						<?php foreach ($data['environments'] as $environment) { /** @var $environment Setting */ ?>
							<div class="form-group">
								<div class="input-group">
									<input class="form-control required" id="<?php echo $environment->getItem(); ?>" type="text" value="<?php echo $environment->getItem(); ?>" disabled>
									<span class="input-group-btn">
										<a class="btn btn-danger delete" href="/admin/system/id=<?php echo $data['system']->getId(); ?>/deleteEnvironment=<?php echo urlencode($environment->getItem()); ?>/" data-confirm="Are you sure to delete the environment '<?php echo $environment->getItem(); ?>'?">
											<i class="fa fa-trash-o" title="Delete" aria-hidden="true"></i>
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
								<label>Add deployment</label>
								<input class="form-control required" name="newEnvironmentName" id="newEnvironmentName" type="text">
							</div>
						</div>
						<div class="box-footer">
							<input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
							<button type="submit" name="group" value="newEnvironment" class="btn btn-primary pull-right">Add</button>
						</div>
					</form>
				</div>
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
                                <pre style="" class="prettyprint prettyprinted">
<code><?php echo trim(htmlentities($data['identifier'])); ?></code>
                                </pre>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- VCS Log -->
                <button type="button" class="btn btn-app" data-toggle="modal" data-target="#modal-history">
                    <i class="fa fa-history"></i> History
                </button>
                <div class="modal fade" id="modal-history">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <h4 class="modal-title">VCS Log</h4>
                            </div>
                            <div class="modal-body">
                                <?php print_r($data['history']); ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Export System -->
                <a class="btn btn-app" href="/admin/systemexport/id=<?php echo $data['system']->getId(); ?>">
                    <i class="fa fa-download"></i> Export
                </a>
                <!-- Export System -->
                <a class="btn btn-app" href="/admin/systemimport/id=<?php echo $data['system']->getId(); ?>">
                    <i class="fa fa-upload"></i> Import
                </a>


                <!-- Update -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title" <?php if(strlen($data['system']->getVcsUrl()) == 0){ ?>disabled<?php }?>>Update</h3>
                    </div>
                    <div class="box-body">
                        <p>Current revision: <?php echo $data['revision']; ?></p>
                        <button type="button" class="btn btn-block btn-warning btn-lg" onclick="location.href='/admin/systemUpdate/id=<?php echo $data['system']->getId(); ?>';"><span class="fa fa-download"></span> Update</button>
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
                        <div class="row">
                            <div class="col-sm-6">
                                <button type="button" class="btn btn-block btn-primary btn-lg" onclick="location.href='/results/build/id=<?php echo $data['system']->getId(); ?>/type=1';">Configure Overall Results</button>
                            </div>
                            <div class="col-sm-6">
                                <button type="button" class="btn btn-block btn-primary btn-lg" onclick="location.href='/results/build/id=<?php echo $data['system']->getId(); ?>/type=2';">Configure Job Results</button>
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
                                <label>Deployment</label>
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
                                <select id="default_phases_warmUp" name="default_phases_warmUp" class="form-control required">
                                    <option <?php if(!isset($data['defaultValues']['phases_warmUp']) || $data['defaultValues']['phases_warmUp'] == 0) echo 'selected'; ?> value="0">unchecked</option>
                                    <option <?php if(isset($data['defaultValues']['phases_warmUp']) && $data['defaultValues']['phases_warmUp'] == 1) echo 'selected'; ?> value="1">checked</option>
                                </select>
                            </div>
                        </div>
                        <div class="box-footer">
                            <input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
                            <button type="submit" name="group" value="defaultValues" class="btn btn-primary pull-right">Save</button>
                        </div>
                    </form>
                </div>

				<!-- Settings -->
				<div class="box box-default">
					<form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
						<div class="box-header with-border">
							<h3 class="box-title">Settings</h3>
						</div>
						<div class="box-body">
							<?php foreach ($data['settings'] as $setting) { /** @var $setting Setting */ ?>
								<div class="form-group">
									<label><?php echo $setting->getItem(); ?></label>
									<div class="input-group">
										<input class="form-control required" name="<?php echo $setting->getItem(); ?>" id="<?php echo $setting->getItem(); ?>" type="text" value="<?php echo $setting->getValue(); ?>" autocomplete="off" >
										<span class="input-group-btn">
											<a class="btn btn-danger delete" href="/admin/system/id=<?php echo $data['system']->getId(); ?>/delete=<?php echo urlencode($setting->getItem()); ?>/" data-confirm="Are you sure to delete setting '<?php echo $setting->getItem(); ?>'?">
												<i class="fa fa-trash-o" title="Delete" aria-hidden="true"></i>
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
						</div>
					</form>
				</div>

				<!-- newSetting -->
				<div class="box box-default">
					<form role="form" action="/admin/system/id=<?php echo $data['system']->getId(); ?>" method="post">
						<div class="box-header with-border">
							<h3 class="box-title">New Setting</h3>
						</div>
						<div class="box-body">
							<div class="form-group">
								<label>Key</label>
								<input class="form-control required" name="settingKey" id="settingKey" type="text">
							</div>
							<div class="form-group">
								<label>Value</label>
								<input class="form-control required" name="settingValue" id="settingValue" type="text">
							</div>
						</div>
						<div class="box-footer">
							<input id="id" name="id" type="text" value="<?php echo $data['system']->getId(); ?>" hidden>
							<button type="submit" name="group" value="newSetting" class="btn btn-primary pull-right">Save</button>
						</div>
					</form>
				</div>

			</div>
		</div>
	</section>
</div>
