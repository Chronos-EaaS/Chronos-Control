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

$this->includeInlineJS("
    function updateCalculation(changed, percentage, result, isFloat){
        var c = changed.val();
        var p = percentage.val();
        if(p<0){
            p = 0;
        }
        var r = c * p / 100;
        if(!isFloat){
            r = Math.floor(r);
        }
        result.val(r);
    }
    
    function checkPercentages(dependency){
        var sum = 0;
        var elements = [];
        $('input[name=\"' + dependency + '\"]').each(function(index){
            var parameter = $(this).val();
            elements.push($('#parameter-' + parameter + '-percentage'));
            var value = parseInt($('#parameter-' + parameter + '-percentage').val());
            if(value >= 0){
                sum += value;
            }
        });
        elements.forEach(function(entry){
            if(sum != 100){
                entry.addClass('percentage-error');
            }
            else{
                entry.removeClass('percentage-error');
            }
        });
    }
");

$this->includeInlineCSS("
    .percentage-error {
        border-color: #a00;
    }
    
    .percentage-error:focus {
        border-color: #f00;
    }
");

?>

<div class="content-wrapper">
    <form id="form" action="/builder/create/" method="POST">
        <input id="projectId" type="hidden" name="projectId" value="<?php echo $data['project']->getId() ?>">
        <div id="icarus">
            <section class="content-header">
                <h1>Create Experiment (Project <?php echo $data['project']->getName() ?>)</h1>
                <ol class="breadcrumb">
                    <li><a href="/home/main">Home</a></li>
                    <li><a href="/project/detail/id=<?php echo $data['project']->getId() ?>">Project</a></li>
                    <li class="active">Create Experiment</li>
                </ol>
            </section>

            <section class="content">
                <div class="row">
                    <div class="col-md-6">
                        <div class="box">
                            <div class="box-header with-border">
                                <h3 class="box-title">General</h3>
                            </div>
                            <div class="box-body">
                                <div class="form-group">
                                    <label>Name</label>
                                    <input class="form-control" placeholder="Name" name="name" type="text" required>
                                </div>
                                <div class="form-group">
                                    <label>Number of Runs per Setting</label>
                                    <input class="form-control" placeholder="Runs" name="runs" type="number" value="1" required>
                                </div>
                                <div class="form-group">
                                    <label>Run Distribution</label>
                                    <select name="run-distribution" class="form-control">
                                        <option value="alter">Alternating</option>
                                        <option value="order">Ascending</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                  <label>Description</label>
                                  <textarea class="form-control" rows="8" name="description" placeholder="Description"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Select deployment</label>
                                    <select id="environment" name="deployment" class="form-control">
                                        <?php if(!empty($data['deployments'])) { ?>
                                            <?php foreach ($data['deployments'] as $deployment) { ?>
                                                <option value="<?php echo $deployment->getItem(); ?>" <?php if(isset($data['app']->defaultValues['environment']) && $data['app']->defaultValues['environment'] == $deployment->getItem()) echo 'selected'; ?>><?php echo $deployment->getItem(); ?></option>
                                            <?php } ?>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="box box-default">
                            <div class="box-body">
                                <div class="row">
                                    <div class="col-xs-12">
                                        <button type="submit" class="btn btn-block btn-success btn-lg">Create</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                        echo $data['content'];
                    ?>
                </div>
            </section>
        </div>
    </form>
</div>