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

use DBA\Job;

?>
<div class="content-wrapper">
	<form id="form" action="#" method="POST">
		<section class="content-header">
			<h1>
				Evaluation Results: <?php echo $data['evaluation']->getName(); ?>
			</h1>
            <ol class="breadcrumb">
                <li><a href="/home/main">Home</a></li>
                <li><a href="/project/detail/id=<?php echo $data['experiment']->getProjectId() ?>">Project</a></li>
                <li><a href="/experiment/detail/id=<?php echo $data['experiment']->getId() ?>">Experiment</a></li>
                <li><a href="/evaluation/detail/id=<?php echo $data['evaluation']->getId() ?>">Evaluation</a></li>
                <li class="active">Evaluation Results</li>
            </ol>
		</section>
	
		<section class="content">
			<?php echo $data['content']; ?>
		</section>
	</form>
</div>
