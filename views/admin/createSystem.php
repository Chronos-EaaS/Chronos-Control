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
	<section class="content-header">
		<h1>
			Add a System
		</h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/admin/systems">Systems</a></li>
            <li class="active">Create System</li>
        </ol>
	</section>

	<?php use DBA\User;

    if(!empty($data['result'])) { ?>
		<section class="content">
			<div class="box box-default">
				<div class="box-header with-border">
					<h3 class="box-title">Added new System</h3>
				</div>
				<div class="box-body">
					<p>Output:</p>
					<pre><?php echo $data['result']; ?></pre>
					<a href="/admin/system/id=<?php echo $data['systemID']; ?>">back</a>
				</div>
			</div>
		</section>
	<?php } else { ?>
		<section class="content">
            <form role="form" action="/admin/createSystem/" method="post">

                <!-- General -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">General</h3>
                    </div>

                    <div class="box-body">
                        <div class="form-group">
                            <label>Name</label>
                            <input class="form-control required" name="name" id="name" type="text">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea class="form-control" rows="8" name="description" id="description"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Owner</label>
                            <select id="owner" name="owner" class="form-control required">
                                <?php foreach ($data['users'] as $u) { /** @var $u \DBA\User */ ?>
                                    <option <?php if($u->getId() == $auth->getUserID()) echo 'selected'; ?> value="<?php echo $u->getId(); ?>"><?php echo $u->getFirstname() . ' ' . $u->getLastname() . ' (' . $u->getUsername() . ')'; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">System Repository (To checkout an existing system configuration)</h3>
                    </div>

                    <div class="box-body">
                        <div class="form-group">
                            <label>Repository</label>
                            <input class="form-control required" name="repository" id="repository">
                        </div>
                        <div class="form-group">
                            <label>Repository Type</label>
                            <select name="vcsType" class="form-control required" id="vcsType">
                                <option value="git">Git</option>
                                <option value="hg">Mercurial</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Repository User</label>
                            <input class="form-control required" name="vcsUser" id="vcsUser">
                        </div>
                        <div class="form-group">
                            <label>Repository Password</label>
                            <input type="password" class="form-control required" name="vcsPassword" id="vcsPassword">
                        </div>
                        <div class="form-group">
                            <label>Branch</label>
                            <input class="form-control required" name="branch" id="branch">
                        </div>
                    </div>
                </div>

                <div class="box box-default">
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary pull-right">Add</button>
                    </div>
                </div>


            </form>
		</section>
	<?php } ?>
</div>
