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
$this->setDesign(null);
$dirname = 'default';
?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="UTF-8">
		<title><?php echo SITE_NAME; ?> | Log in</title>

		<!-- Tell the browser to be responsive to screen width -->
		<meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">

		<!-- Bootstrap 3.3.4 -->
		<link href="/design/<?php echo $dirname; ?>/bootstrap/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
		<!-- Theme style -->
		<link href="/design/<?php echo $dirname; ?>/css/AdminLTE.min.css" rel="stylesheet" type="text/css" />

		<?php if(!empty($this->includeCSSFiles)) {
			foreach($this->includeCSSFiles as $file) {
				echo '<link type="text/css" rel="stylesheet" href="' . $file .'" />' . "\n";
			}
		}
		?>

		<?php if(!empty($this->includeJSFilesHead)) {
			foreach($this->includeJSFilesHead as $file) {
				echo '<script src="' . $file .'"></script>' . "\n";
			}
		}
		?>

	</head>
	<body class="login-page">

		<div class="login-box">
			<div class="login-logo">
                <img src="/design/<?php echo $dirname ?>/img/logo.svg" alt="<?php echo SITE_NAME; ?>" width="60%">
			</div>
			<div class="login-box-body">
				<p class="login-box-msg">Please sign in</p>
				<?php if(!empty($data['error'])) { ?>
					<div class="alert alert-danger alert-dismissable">
						<button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
						<h4><i class="icon fa fa-ban"></i> Alert!</h4>
						<?php echo $data['error']; ?>
					</div>
				<?php } ?>
				<form action="/user/login" method="post">
					<div class="form-group has-feedback">
						<input type="username" class="form-control" placeholder="Username" name="username" />
						<span class="glyphicon glyphicon-envelope form-control-feedback"></span>
					</div>
					<div class="form-group has-feedback">
						<input type="password" class="form-control" placeholder="Password" name="password" />
						<span class="glyphicon glyphicon-lock form-control-feedback"></span>
					</div>
					<div class="row">
						<div class="col-xs-8">
							<div class="checkbox icheck">
								<label>
									<input type="checkbox" name="remember" > Remember Me
								</label>
							</div>
						</div>
						<div class="col-xs-4">
							<button type="submit" class="btn btn-primary btn-block btn-flat">Sign In</button>
						</div>
					</div>
				</form>
			</div>
		</div>

   	    <!-- jQuery JS -->
        <script src="/design/<?php echo $dirname; ?>/jquery/jquery-3.3.1.min.js" type="text/javascript"></script>

		<!-- Bootstrap 3.3.2 JS -->
		<script src="/design/<?php echo $dirname; ?>/bootstrap/js/bootstrap.min.js" type="text/javascript"></script>

		<?php if(!empty($this->includeJSFilesBody)) {
			foreach($this->includeJSFilesBody as $file) {
				echo('<script src="' . $file .'"></script>' . "\n");
			}
		}
		?>

		<?php if(!empty($this->includeInlineJS)) {
			foreach($this->includeInlineJS as $file) {
        ?>
            <script type="text/javascript">
                //<!--
                    <?php echo $file; ?>
                // -->
            </script>
		<?php
			}
		}
		?>

		<!-- iCheck -->
		<script>
			$(function () {
				$('input').iCheck({
					checkboxClass: 'icheckbox_square-blue',
					radioClass: 'iradio_square-blue',
					increaseArea: '20%' // optional
				});
			});
		</script>

	</body>
</html>
