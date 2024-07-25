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

function startsWith($haystack, $needle) {
    // search backwards starting from haystack length characters from the end
    return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
}
function createMenuItem($page, $currentPage, $title, $icon) {
	$active = '';
	if(startsWith($currentPage, $page)) {
		$active = 'class="active"';
	}
	return '<li ' . $active . '><a href="' . $page . '"><i class="fa ' . $icon .'"></i> <span>' . $title . '</span></a></li>';
}
?>

<!-- Left side column. contains the logo and sidebar -->
<aside class="main-sidebar">
	<!-- sidebar: style can be found in sidebar.less -->
	<section class="sidebar">
		<!-- Sidebar Menu -->
		<ul class="sidebar-menu" data-widget="tree">
			<li class="header">&nbsp;</li>
			<!-- Optionally, you can add icons to the links -->
			<?php echo createMenuItem('/home/main', $currentPage, 'Overview', 'fa-home'); ?>
            <?php echo createMenuItem('/project/overview', $currentPage, 'Projects', 'fa-archive'); ?>
			<?php echo createMenuItem('/evaluation/overview', $currentPage, 'Status', 'fa-tasks'); ?>
			<?php echo createMenuItem('/admin/systems', $currentPage, 'Systems', 'fa-cubes'); ?>
            <?php if($auth->isAdmin()) { ?>
                <?php echo createMenuItem('/cem/overview', $currentPage, 'CEM', 'fa-server'); ?>
            <?php } ?>
			<!--
			<li class="treeview">
				<a href="#"><i class="fa fa-link"></i> <span>MAAS API Test</span> <i class="fa fa-angle-left pull-right"></i></a>
				<ul class="treeview-menu">
		    		<li><a href="#">Test 1</a></li>
					<li><a href="#">Test 2</a></li>
		  		</ul>
			</li>
			-->
		</ul>
	</section>
</aside>