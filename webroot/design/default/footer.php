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
			<!-- Main Footer -->
			<footer class="main-footer" <?php if(DEBUGMODE && error_get_last()) { ?> style="background-color: darkred;" <?php } ?>>
				<!-- To the right -->
				<div class="pull-right hidden-xs">
			 		v<?php echo APP_VERSION; ?>
				</div>
				<strong><?php echo SITE_NAME; ?></strong>
				<div class="col-md-6">
			 		Databases and Information Systems (DBIS) Group at the University of Basel 
				</div>

				
				<!-- PHP Error -->
				<?php if(DEBUGMODE && error_get_last()) { ?>
					<div style="text-align: center;">
			  			<?php
			  				$errorStr = print_r(error_get_last(), true);
			  				// remove new line (because of javascript)
			  				$errorStr = trim(preg_replace('/\s+/', '\n', $errorStr));
			  			?>
			  			<script>
			  				// <!--
			  				function phpErrorBox() {
								alert("<?php echo $errorStr; ?>");
							}
							// -->
						</script>
			  			<a style="color: white; font-size:large;" class="center" href="javascript:phpErrorBox();">PHP-Error</a>
		  			</div>
		  		<?php } ?>
	  		
			</footer>
		</div>
	   
   	    <!-- jQuery JS -->
	    <script src="/design/<?php echo $dirname; ?>/jquery/jquery-3.3.1.min.js" type="text/javascript"></script>
	    <!-- Bootstrap 3.3.2 JS -->
	    <script src="/design/<?php echo $dirname; ?>/bootstrap/js/bootstrap.min.js" type="text/javascript"></script>
	    <!-- AdminLTE App -->
	    <script src="/design/<?php echo $dirname; ?>/js/app.js" type="text/javascript"></script>

		<?php if(!empty($this->includeJSFilesBody)) {
        	foreach($this->includeJSFilesBody as $file) {
        		echo '<script src="' . $file .'"></script>' . "\n";
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
  	</body>
</html>

