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
            Create a Project
        </h1>
    </section>
    <section class="content">
        <!-- General -->
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">General</h3>
            </div>
            <form role="form" action="/project/create/" method="post">
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
                            <?php use DBA\User;

                            foreach ($data['users'] as $u) { /** @var $u User */ ?>
                                <option <?php if($u->getId() == $auth->getUserID()) echo 'selected'; ?> value="<?php echo $u->getId(); ?>"><?php echo $u->getFirstname() . ' ' . $u->getLastname() . ' (' . $u->getUsername() . ')'; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>System</label>
                        <select id="system" name="system" class="form-control required">
                            <?php foreach ($data['systems'] as $s) { /** @var $s \DBA\System */ ?>
                                <option value="<?php echo $s->getId(); ?>"><?php echo $s->getName(); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary pull-right">Create</button>
                </div>
            </form>
        </div>
    </section>
</div>
