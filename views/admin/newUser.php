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

?>
<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1>
			New user
		</h1>
	</section>

	<!-- Main content -->
	<section class="content">
		
		<?php if(!empty($data['error'])) { ?>
			<div class="alert alert-danger alert-dismissable">
				<button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
				<h4><i class="icon fa fa-ban"></i> Alert!</h4>
				<?php echo $data['error']; ?>
			</div>
		<?php } ?>
		
		<div class="box box-primary">
			<div class="box-header with-border">
				<h3 class="box-title">New User</h3>
			</div>
			<form role="form" action="/admin/newUser" method="post">
				<div class="box-body">
					<div class="form-group">
						<label for="username">Username</label>
						<input type="text" class="form-control" id="username" name="username">
					</div>
					<div class="form-group">
						<label for="lastname">Last name</label>
						<input type="text" class="form-control" id="lastname" name="lastname">
					</div>
					<div class="form-group">
						<label for="firstname">First name</label>
						<input type="text" class="form-control" id="firstname" name="firstname">
					</div>
					<div class="form-group">
						<label>Gender</label>
						<select class="form-control" data-placeholder="Gender" name="gender" required="required">
							<option value="" label=" "></option>
							<option value="1">Male</option>
							<option value="2">Female</option>
						</select>
					</div>
					<div class="form-group">
						<label for="email">E-Mail:</label>
						<input type="email" class="form-control" id="email" name="email">
					</div>
					<div class="form-group">
						<label for="password">Password:</label>
						<input type="password" class="form-control" id="password" name="password">
					</div>
					<div class="form-group">
						<label for="password-repeat">Repeat password:</label>
						<input type="password" class="form-control" id="password-repeat" name="password-repeat">
					</div>
				</div>

				<div class="box-footer">
					<button onclick="location.href='/admin/main';" class="btn btn-primary">Abort</button>
					<button type="submit" class="btn btn-primary pull-right">Save</button>
				</div>
			</form>
		</div>
	</section>
</div>
		
	