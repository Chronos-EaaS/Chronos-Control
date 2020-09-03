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

$path = explode('/' , __DIR__);
$dirname = array_pop($path);
?>
<!DOCTYPE html>
    <head>
        <meta charset="utf-8">
        <title><?php echo SITE_NAME; ?></title> 
        
		<?php if($this->redirect != '') { ?>
        	<meta http-equiv="refresh" content="0; url=<?php echo $this->redirect; ?>" />
        <?php } ?>
        
        <!-- Tell the browser to be responsive to screen width -->
	    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">
	    
	    <!-- Bootstrap 3.3.4 -->
	    <link href="/design/<?php echo $dirname; ?>/bootstrap/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
	    <!-- Theme style -->
	    <link href="/design/<?php echo $dirname; ?>/css/AdminLTE.min.css" rel="stylesheet" type="text/css" />
	    <!-- AdminLTE Skins. -->
	    <link href="/design/<?php echo $dirname; ?>/css/skins/skin-blue.min.css" rel="stylesheet" type="text/css" />


        <!-- jQuery JS -->
        <script src="/design/<?php echo $dirname; ?>/jquery/jquery-3.3.1.min.js" type="text/javascript"></script>
        <script src="/design/<?php echo $dirname; ?>/jquery/jquery.validate-1.19.2.min.js" type="text/javascript"></script>

        <style>
            body {
                overflow: scroll;
            }
        </style>


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

		<?php if(!empty($this->includeInlineCSS)) {
			foreach($this->includeInlineCSS as $code) {
				echo "<style> \n" . $code . "\n </style>";
			}
		}
		?>
       
    </head>
	<body class="skin-blue sidebar-mini">
		<div class="wrapper">

			<!-- Main Header -->
			<header class="main-header">
				<!-- Logo -->
				<a href="/home/main" class="logo">
					<!-- mini logo for sidebar mini 50x50 pixels -->
					<span class="logo-mini">CC</span>
					<!-- logo for regular state and mobile devices -->
					<span class="logo-lg">Chronos Control</span>
				</a>
				<?php include(SERVER_ROOT . '/webroot/design/' . $dirname . '/navbar.php'); ?>
			</header>
			
			<?php 
				if($auth->isLoggedIn()) {
					include(SERVER_ROOT . '/webroot/design/' . $dirname . '/menu.php');
			 	}
			 ?>
			
	