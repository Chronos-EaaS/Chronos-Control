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
    function updateCalculation(changed, percentage, result, isFloat, allowNegative = false){
        var c = changed.val();
        var p = percentage.val();
        if(p < 0 && !allowNegative){
            p = 0;
        }
        var r = c * p / 100;
        if(!isFloat){
            r = Math.floor(r);
        }
        result.val(r);
        result.trigger('change');
    }
    
    function updateCalculationInterval(changed, percentage, result, isFloat, allowNegative = false){
        var c1 = $(changed + '-start').val();
        var c2 = $(changed + '-end').val();
        var c3 = $(changed + '-step').val();
        var p = percentage.val();
        if(p < 0 && !allowNegative){
            p = 0;
        }
        var r1 = c1 * p / 100;
        var r2 = c2 * p / 100;
        var r3 = c3 * p / 100;
        if(!isFloat){
            r1 = Math.floor(r1);
            r2 = Math.floor(r2);
            r3 = Math.floor(r3);
        }
        $(result + '-start').val(r1);
        $(result + '-end').val(r2);
        $(result + '-step').val(r3);
        $(result + '-start').trigger('change');
        $(result + '-end').trigger('change');
        $(result + '-step').trigger('change');
    }
    
    function checkPercentages(dependency, modifier = ''){
        var sum = 0;
        var elements = [];
        $('input[name=\"' + dependency + '\"]').each(function(index){
            var parameter = $(this).val();
            elements.push($('#parameter-' + parameter + '-percentage' + modifier));
            var value = parseInt($('#parameter-' + parameter + '-percentage' + modifier).val());
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
    
    function checkPercentageIntervals(dependency){
        var sumMin = 0;
        var sumMax = 0;
        var sumStep = 0;
        var elementsMin = [];
        var elementsMax = [];
        var elementsStep = [];
        $('input[name=\"' + dependency + '\"]').each(function(index){
            var parameter = $(this).val();
            elementsMin.push($('#parameter-' + parameter + '-percentage-min'));
            elementsMax.push($('#parameter-' + parameter + '-percentage-max'));
            elementsStep.push($('#parameter-' + parameter + '-percentage-step'));
            var value = parseInt($('#parameter-' + parameter + '-percentage-min').val());
            if(value >= 0){
                sumMin += value;
            }
            value = parseInt($('#parameter-' + parameter + '-percentage-max').val());
            if(value >= 0){
                sumMax += value;
            }
            value = parseInt($('#parameter-' + parameter + '-percentage-step').val());
            sumStep += value;
        });
        elementsMin.forEach(function(entry){
            if(sumMin != 100){
                entry.addClass('percentage-error');
            }
            else{
                entry.removeClass('percentage-error');
            }
        });
        elementsMax.forEach(function(entry){
            if(sumMax != 100){
                entry.addClass('percentage-error');
            }
            else{
                entry.removeClass('percentage-error');
            }
        });
        elementsStep.forEach(function(entry){
            if(sumStep == 0){
                entry.removeClass('percentage-error');
            }
            else{
                entry.addClass('percentage-error');
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
    
    label.error {
        color: #a94442;
        background-color: #f2dede;
        padding:1px 5px 1px 5px;
    }
");

?>

<div class="content-wrapper">
    <form id="form" action="/builder/create/" method="POST">
        <script type="text/javascript">$('#form').validate();</script>
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
                                    <input class="form-control" placeholder="Runs" name="runs" type="number" value="<?php echo $data['copyData']['runs'] ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Run Distribution</label>
                                    <select name="run-distribution" class="form-control">
                                        <option value="alter"<?php if($data['copyData']['run-distribution'] == "alter") echo " selected"; ?>>Alternating</option>
                                        <option value="order"<?php if($data['copyData']['run-distribution'] == "order") echo " selected"; ?>>Ascending</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Description</label>
                                    <textarea class="form-control" rows="8" name="description" placeholder="Description"><?php echo $data['copyData']['description']; ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Select deployment</label>
                                    <select id="environment" name="deployment" class="form-control" required>
                                        <?php if(!empty($data['deployments'])) { ?>
                                            <?php foreach ($data['deployments'] as $deployment) { ?>
                                                <option value="<?php echo $deployment->getItem(); ?>" <?php if($data['copyData']['deployment'] == $deployment->getItem()) echo 'selected'; ?>><?php echo $deployment->getItem(); ?></option>
                                            <?php } ?>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <div class="checkbox-inline">
                                        <label style="font-weight: normal">
                                            <input type="hidden" name="phase_warmUp" value="0">
                                            <input type="checkbox" name="phase_warmUp" value="1" title="Warm-up Phase" <?php if($data['copyData']['phase_warmUp'] && $data['copyData']['phase_warmUp'] != 'unchecked'){echo "checked";} ?>>
                                            Warm-up Phase
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="box box-default">
                            <div class="box-body">
                                <div class="row">
                                    <div class="col-xs-12">
                                        <button id="confirm" class="btn btn-block btn-success btn-lg">Create</button>
                                        <div id="confirmation-box" class="modal">
                                            <div class="popup-content">
                                                <p>Deployment '<script>document.getElementById('environment')</script>' was selected. Please confirm or cancel. </p>
                                                <button type="submit" class="btn btn-block btn-success btn-lg">Confirm</button>
                                                <button id="cancel" class="btn btn-block btn-success btn-lg">Cancel</button>
                                            </div>
                                            <script>
                                                document.getElementById("confirm").onclick = function() {
                                                    document.getElementById("confirmation-box").style.display ="block";
                                                }

                                                document.getElementById("cancel").onclick = function() {
                                                    document.getElementById("confirmation-box").style.display ="none";
                                                }
                                                window.onclick = function(event) {
                                                    if (event.target == document.getElementById("confirmation-box")) {
                                                        document.getElementById("confirmation-box").style.display = "none";
                                                    }
                                                }

                                            </script>

                                        </div>
                                        <!--<button type="submit" class="btn btn-block btn-success btn-lg">Create</button> -->
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
