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
<!-- Header Navbar -->
<nav class="navbar navbar-static-top" role="navigation">
	<!-- Sidebar toggle button-->
	<a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button">
		<span class="sr-only">Toggle navigation</span>
	</a>
	<!-- Navbar Right Menu -->
	<div class="navbar-custom-menu">
		<ul class="nav navbar-nav">
			<!-- Switched User -->
  			<?php if($auth->isSwitchedUser()) { ?>	
              <div class="navbar-form navbar-left">
              	<div class="bg-red-active color-palette"><span style="font-size: 22px;">&nbsp;&nbsp;Switch User Mode&nbsp;&nbsp;</span></div>
              </div>
			<?php } ?>
					
  			<?php if($auth->isLoggedIn()) { ?>
				<!-- User Account Menu -->
				<li class="dropdown user user-menu">
					<!-- Menu Toggle Button -->
					<a href="#" class="dropdown-toggle" data-toggle="dropdown">
						<!-- The user image in the navbar-->
						<img src="<?php echo $auth->getGravatar(160); ?>" class="user-image" alt="User Image" />
						<!-- hidden-xs hides the username on small devices so only the image appears. -->
						<span class="hidden-xs"><?php echo $auth->getUser()->getFirstname() . ' ' . $auth->getUser()->getLastname(); ?></span>
					</a>
					<ul class="dropdown-menu">
						<!-- The user image in the menu -->
						<li class="user-header">
							<img src="<?php echo $auth->getGravatar(160); ?>" class="img-circle" alt="User Image" />
							<p>
								<?php echo $auth->getUser()->getFirstname() . ' ' . $auth->getUser()->getLastname() . ' (' . $auth->getUser()->getUsername() . ')'; ?>
								<?php if($auth->isAdmin()) { ?><small>Admin</small> <?php } ?>
							</p>
						</li>
						<!-- Menu Footer-->
						<li class="user-footer">
							<div class="pull-left">
								<a href="/user/edit" class="btn btn-default btn-flat">Profile</a>
							</div>
							<div class="pull-right">
								<a href="/user/logout" class="btn btn-default btn-flat">Log out</a>
							</div>
						</li>
					</ul>
				</li>
				<?php if($auth->isAdmin()) { ?>
				<li>
					<a href="/admin/main"><i class="fa fa-gears"></i></a>
				</li>
				<?php } ?>
			<?php } ?>
		</ul>
	</div>
</nav>
