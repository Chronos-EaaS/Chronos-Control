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

	<!-- Main content -->
	<section class="content">
		
		<div class="box box-default">
			<div class="box-header with-border">
				<i class="fa fa-warning"></i>
				<h3 class="box-title">Switch User</h3>
			</div>
			<form role="form" action="/admin/switchUser" method="post">
				<div class="box-body">
					<p>Do you really want to switch to the following user?</p>
					<p><b><?php echo $data['username']; ?></b></p>
					<div class="form-group">
						<div class="checkbox">
							<label>
								<input type="checkbox" name="changeUser" id="changeUser" value="1" />
								hard switch
							</label>
						</div>
					</div>
				</div>
				<div class="box-footer">
					<input type="hidden" name="username" value="<?php echo $data['username']; ?>">
					<button type="submit" class="btn btn-primary" name="switch" value="no">No</button>
					<button type="submit" class="btn btn-primary pull-right" name="switch" value="yes">Yes</button>
				</div>
			</form>
		</div>
	</section>
</div>