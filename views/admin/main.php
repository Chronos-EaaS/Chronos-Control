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

use DBA\User;

$this->includeAsset('icheck');
$this->includeInlineJS("
		//iCheck for checkbox and radio inputs
        $('input[type=\"checkbox\"].minimal, input[type=\"radio\"].minimal').iCheck({
          checkboxClass: 'icheckbox_minimal-blue',
          radioClass: 'iradio_minimal-blue'
        });
	");
$this->includeInlineJS("
		$('.dead').hide();
		$('#filter').on('ifChanged', function(){
			if(this.checked) {
		        $('.dead').hide();
		    } else {
		        $('.dead').show();
		    }            
		
		});
	");

?>
<div class="content-wrapper">
    <section class="content-header">
        <h1>
            Administration
            <small>Remember: With great power comes great responsibility.</small>
        </h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li class="active">Administration</li>
        </ol>
    </section>

    <section class="content">

        <div class="row">
            <div class="col-md-6">
                <?php if($auth->isSuperAdmin()) { ?>
                    <!-- Update -->
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">Update</h3>
                        </div>
                        <div class="box-body">
                            <p>Branch: <?php echo REPOSITORY_BRANCH; ?></p>
                            <button type="button" class="btn btn-block btn-warning btn-lg" onclick="location.href='/admin/update/';"><span class="fa fa-download"></span> Update</button>
                        </div>
                    </div>
                <?php } ?>


                <!-- Mount Status -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Mount Status</h3>
                    </div>
                    <div class="box-body no-padding">
                        <table class="table">
                            <tbody>
                                <tr>
                                    <td><?php echo(UPLOADED_DATA_PATH) ?></td>
                                    <?php if(isset($data['mountStatus']) && $data['mountStatus'] === false) { ?>
                                        <td><a href="/admin/mountDataDirectory/">mount</a></td>
                                    <?php } ?>
                                    <td style="text-align: right;">
                                        <?php if(!empty($data['mountStatusError'])) { ?>
                                            <span class="label label-default">unknown</span>
                                        <?php } else if($data['mountStatus'] === true) { ?>
                                            <span class="label label-success">mounted</span>
                                        <?php } else { ?>
                                            <span class="label label-danger">not mounted</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <?php
                            if(!empty($data['mountStatusError'])) {
                                echo ('<pre>' . $data['mountStatusError'] . '</pre>');
                            }
                        ?>
                    </div>
                </div>


                <!-- Settings -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Settings</h3>
                    </div>
                    <div class="box-body">

                    </div>
                </div>


                <!-- MAAS -->
                <!--<div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">MAAS Cluster</h3>
                    </div>
                    <form class="form-horizontal" role="form" action="/admin/main" method="post">
                        <div class="box-body">
                            <div class="form-group">
                                <label for="maas_key" class="col-sm-2 control-label">MAAS key</label>
                                <div class="col-sm-10">
                                    <input type="text" class="form-control" id="maaskey" name="maas_key" placeholder="MAAS key" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="key" class="col-sm-2 control-label">Key</label>
                                <div class="col-sm-10">
                                    <input type="text" class="form-control" id="key" value="<?php echo $data['maas']['key']; ?>" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="secret" class="col-sm-2 control-label">Secret</label>
                                <div class="col-sm-10">
                                    <input type="text" class="form-control" id="secret" value="<?php echo $data['maas']['secret']; ?>" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="consumer_key" class="col-sm-2 control-label">Consumer key</label>
                                <div class="col-sm-10">
                                    <input type="text" class="form-control" id="consumer_key" value="<?php echo $data['maas']['consumer_key']; ?>" disabled>
                                </div>
                            </div>
                        </div>
                        <div class="box-footer">
                            <button type="submit" class="btn btn-primary pull-right" name="group" value="maas">Save</button>
                        </div>
                    </form>
                </div>-->

            </div>

            <div class="col-md-6">

                <!-- Users -->
                <div class="box">
                    <div class="box-header">
                        <h3 class="box-title">Users</h3>
                    </div>
                    <div class="box-body no-padding">
                        <table class="table">
                            <tr>
                                <th style="width: 10px">Alive</th>
                                <th>Username</th>
                                <th>Last name</th>
                                <th>First name</th>
                                <th style="width: 40px">Admin</th>
                                <th style="width: 40px">Edit</th>
                                <th style="width: 40px">Switch</th>
                            </tr>
                            <?php foreach($data['users'] as $user) { /** @var $user User */ ?>
                                <tr <?php if(!$user->getAlive()) { ?>class="dead"<?php } ?>>
                                    <td class="table_settings"><?php if(!$user->getAlive()) { ?><img src="/images/deactivated.png" title="deactivated" alt="deactivated"><?php } ?>
                                        <?php if($user->getAlive()) { ?><img src="/images/active.png" title="active" alt="active"><?php } ?></td>
                                    <td><?php echo $user->getUsername();?></td>
                                    <td><?php echo $user->getLastname();?></td>
                                    <td><?php echo $user->getFirstname(); ?></td>
                                    <td><?php if ($user->getRole() == 1) { ?><span class="label label-success">Admin</span><?php } else if ($user->getRole() > 1) { ?><span class="label label-primary">Superadmin</span><?php } ?></td>
                                    <td><a href="/user/edit/id=<?php echo $user->getId(); ?>"><img src="/images/settings.png" title="edit" alt="edit"></a></td>
                                    <td><?php if(!$auth->isSwitchedUser() && $auth->getUserID() != $user->getId() && $user->getAdmin() < 2) { ?><a href="/admin/switchUser/username=<?php echo $user->getUsername(); ?>"><img src="/images/switch.png" title="switch user" alt="switchuser"></a><?php } ?></td>
                                </tr>
                            <?php } ?>
                        </table>
                    </div>
                    <div class="box-footer">
                        <label>
                            <input type="checkbox" class="minimal" id="filter" checked />
                            only alive
                        </label>
                        <button onclick="location.href='/admin/newUser';" class="btn btn-primary pull-right">Add user</button>
                    </div>
                </div>

                <!-- Systems -->
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Systems</h3>
                    </div>
                    <div class="box-body no-padding">
                        <table class="table">
                            <tr>
                                <th>Display name</th>
                                <th>Identifier</th>
                            </tr>
                            <?php foreach($data['systems'] as $system) { ?>
                                <tr>
                                    <td><?php echo $system->getName(); ?></td>
                                    <td><?php echo $system->getIdentifier(); ?></td>
                                </tr>
                            <?php } ?>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </section>
</div>

