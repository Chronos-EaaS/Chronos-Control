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

$this->includeAsset('icheck');
$this->includeInlineJS("
    //iCheck for checkbox and radio inputs
    $('input[type=\"checkbox\"].minimal, input[type=\"radio\"].minimal').iCheck({
      checkboxClass: 'icheckbox_minimal-blue',
      radioClass: 'iradio_minimal-blue'
    });
");
?>
<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1>Profile</h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/admin/main">Administration</a></li>
            <li class="active">Edit User</li>
        </ol>
	</section>
	<section class="content">
		<div class="row">
			
			<?php if(!empty($data['error'])) { ?>
				<div class="col-md-12">
					<div class="box box-danger box-solid">
						<div class="box-header with-border">
							<h3 class="box-title">Error</h3>
							<div class="box-tools pull-right">
								<button class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
							</div>
						</div>
						<div class="box-body"><?php echo $data['error']; ?></div>
					</div>
				</div>
			<?php } ?>
			
			<?php if(!empty($data['success'])) { ?>		
				<div class="col-md-12">
					<div class="box box-success box-solid">
						<div class="box-header with-border">
							<h3 class="box-title">Success</h3>
							<div class="box-tools pull-right">
								<button class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
							</div>
						</div>
						<div class="box-body"><?php echo $data['success']; ?></div>
					</div>
				</div>
			<?php } ?>
			
			<!-- left column -->
			<div class="col-md-6">
				
				<?php if($auth->isAdmin() && $auth->getUserID() != $data['user']->getId()) { ?>
					<!-- Admin Options -->
					<div class="box box-info">
						<div class="box-header with-border">
							<h3 class="box-title">Admin Options</h3>
						</div>
						<form role="form" action="/user/edit/id=<?php echo $data['user']->getId(); ?>" method="post">
							<div class="box-body">
								<div class="form-group">
									<label>
										<input type="checkbox" class="minimal" name="alive" <?php if($data['user']->getAlive() == 1) { ?> checked <?php } ?> />
										Alive
									</label>
								</div>
								<div class="form-group">
									<label>
										<input type="checkbox" class="minimal" name="activated" <?php if($data['user']->getActivated() == 1) { ?> checked <?php } ?> />
										Activated
									</label>
								</div>
								<?php if($auth->isAdmin() == 2) { ?>
									<div class="form-group">
										<label>Admin</label>
										<select class="form-control" name="admin">
											<option value="0" <?php if($data['user']->getRole() == 0) { ?> selected <?php } ?>>User</option>
											<option value="1" <?php if($data['user']->getRole() == 1) { ?> selected <?php } ?>>Admin</option>
											<option value="2" <?php if($data['user']->getRole() == 2) { ?> selected <?php } ?>>Superadmin</option>
										</select>
									</div>
								<?php } ?>
								<div class="box-footer">
									<input type="text" name="id" value="<?php echo $data['user']->getId(); ?>" hidden autocomplete="off">
									<button type="submit" class="btn btn-primary pull-right" name="group" value="admin">Save</button>
								</div>
							</div>
						</form>
					</div>
				<?php } ?>
				
				<!-- Identity -->
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Identity</h3>
					</div>
					<form role="form" action="/user/edit/id=<?php echo $data['user']->getId(); ?>" method="post">
						<div class="box-body">
							<div class="form-group">
								<label for="username">Username</label>
								<input type="text" class="form-control" id="username" name="username" value="<?php echo $data['user']->getUsername(); ?>" <?php if(!$auth->isAdmin() || $auth->getUserID() == $data['user']->getId()) { ?> disabled <?php } ?> required="required" autocomplete="off">
							</div>
							<div class="form-group">
								<label for="lastname">Last name</label>
								<input type="text" class="form-control" id="lastname" name="lastname" value="<?php echo $data['user']->getLastname(); ?>" required autocomplete="off">
							</div>
							<div class="form-group">
								<label for="firstname">First name</label>
								<input type="text" class="form-control" id="firstname" name="firstname" value="<?php echo $data['user']->getFirstname(); ?>" required autocomplete="off">
							</div>
							<div class="form-group">
								<label for="gender">Gender</label>
								<select class="form-control" data-placeholder="Gender" name="gender" required="required">
									<option value="" label=" "></option>
									<option value="1" <?php if($data['user']->getGender() == 1) echo 'selected'; ?>>Male</option>
									<option value="2" <?php if($data['user']->getGender() == 2) echo 'selected'; ?>>Female</option>
								</select>
							</div>
							<div class="form-group">
								<label for="email">E-Mail:</label>
								<input type="email" class="form-control" id="email" name="email" value="<?php echo $data['user']->getEmail(); ?>" required autocomplete="off">
							</div>
						</div>

						<div class="box-footer">
							<input type="text" name="id" value="<?php echo $data['user']->getId(); ?>" hidden autocomplete="off">
							<button type="submit" class="btn btn-primary pull-right" name="group" value="identity">Save</button>
						</div>
					</form>
				</div>
            </div>
            
			<!-- right column -->
			<div class="col-md-6">
				
				<!-- Password -->
				<div class="box box-info">
					<div class="box-header with-border">
						<h3 class="box-title">Password</h3>
					</div>
					<form role="form" action="/user/edit/id=<?php echo $data['user']->getId(); ?>" method="post">
						<div class="box-body">
							<?php if(!$auth->isAdmin() || $auth->getUserID() == $data['user']->getId()) { ?>
								<div class="form-group">
									<label for="old-password">Old password</label>
									<input type="password" class="form-control" id="old-password" name="old-password" placeholder="Old password" required autocomplete="off">
								</div>
							<?php } ?>
							<div class="form-group">
								<label for="new-password">New password</label>
								<input type="password" class="form-control" id="new-password" name="new-password" placeholder="New password" required autocomplete="off">
							</div>
							<div class="form-group">
								<label for="new-password-repeat">Repeat</label>
								<input type="password" class="form-control" id="new-password-repeat" name="new-password-repeat" placeholder="New password repeat" required autocomplete="off">
							</div>
						</div>
						<div class="box-footer">
							<input type="text" name="id" value="<?php echo $data['user']->getId(); ?>" hidden autocomplete="off">
							<button type="submit" class="btn btn-primary pull-right" name="group" value="password">Save</button>
						</div>
					</form>
				</div>
								
			</div>
		</div>
	</section>
</div>
      
