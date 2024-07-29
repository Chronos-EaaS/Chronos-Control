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

if(!isset($data['app']->defaultValues['includePredefined']) || $data['app']->defaultValues['includePredefined'] == 1) {
    $this->includeInlineJS("
            function save() {
                var data = {};
                data['name'] = $('#saveName').val();
                $('.storable').each(function(i,e){
                    var id = $(e).attr('id');
                    if($(e).is(':checkbox')) {
                        var value = $(e).prop('checked');
                    } else {
                        var value = $(e).val();
                    }
                    data[id] = value;
                });
                $.post('/api/system/" . $data['app']->system->uniqueName . "/savePredefined/', data, function(result) {
                    if (result.result == 'success') {
                        $('#saveResultBox').show();
                    }
                });
            }
            $('#predefined').on('change', function(e) {
                var name = this.value;
                $.get('/api/system/" . $data['app']->system->uniqueName . "/getPredefined/name=' + name, function(data, status) {
                    for (var key in data.predefined) {
                        // skip loop if the property is from prototype
                        if (!data.predefined.hasOwnProperty(key)) continue;
                        if(data.predefined[key] == 'true') {
                            $('#' + key).prop('checked', true);
                        } else if(data.predefined[key] == 'false') {
                            $('#' + key).prop('checked', false);
                        } else {
                            $('#' + key).val(data.predefined[key]);
                        }
                    }
                });
            });
        ");
} ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>
            Create a new <?php echo $data['app']->system->displayName; ?> evaluation job
        </h1>
    </section>

    <section class="content">
        <form id="form" action="#" method="POST">
            <div class="row">
                <div class="col-md-6">

                    <!-- General -->
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">General</h3>
                        </div>
                        <div class="box-body">
                            <div class="form-group">
                                <label>Name</label>
                                <input class="form-control" placeholder="Name" name="name" type="text" required>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea class="form-control" rows="8" name="description" placeholder="Description"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Select environment</label>
                                <select id="environment" name="environment" class="form-control">
                                    <?php if(!empty($data['environments'])) { ?>
                                        <?php foreach ($data['environments'] as $environment) { ?>
                                            <option value="<?php echo $environment["key"]; ?>" <?php if(isset($data['app']->defaultValues['environment']) && $data['app']->defaultValues['environment'] == $environment["key"]) echo 'selected'; ?>><?php echo $environment["key"]; ?></option>
                                        <?php } ?>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <input type="hidden" name="phase_warmUp" value="0" />
                                <label>
                                    <input class="required storable" id="phase_warmUp" name="phase_warmUp" value="1" type="checkbox" <?php if (isset($data['app']->defaultValues['phases_warmUp']) && $data['app']->defaultValues['phases_warmUp']) echo "checked"; ?>>&nbsp;
                                    Execute warm-up phase
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Predefined -->
                    <?php if(!isset($data['app']->defaultValues['includePredefined']) || $data['app']->defaultValues['includePredefined'] == 1) { ?>
                        <div class="box box-default">
                            <div class="box-header with-border">
                                <h3 class="box-title">Predefined</h3>
                            </div>
                            <div class="box-body" id="schemainfopanel">
                                <div class="form-group">
                                    <label>Select predefined parameters</label>
                                    <select id="predefined" class="form-control">
                                        <option></option>
                                        <?php if(!empty($data['predefined'])) { ?>
                                            <?php foreach ($data['predefined'] as $key => $value) { ?>
                                                <option><?php echo $key; ?></option>
                                            <?php } ?>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Save parameters</label>
                                    <div class="input-group">
                                        <input class="form-control" id="saveName" placeholder="Name" type="text">
                                        <span class="input-group-btn">
                                            <button type="button" class="btn btn-info btn-flat" onclick="save();">Save</button>
                                        </span>
                                    </div>
                                </div>
                                <div id="saveResultBox" style="display:none;" class="alert alert-success alert-dismissible">
                                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                                    <span id="saveResult"><h4><i class="icon fa fa-check"></i> Success</h4></span>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="col-md-6">
                    <div class="box box-default">
                        <div class="box-body">
                            <button type="submit" class="btn btn-block btn-success btn-lg">Submit Job</button>
                        </div>
                    </div>

                    <?php echo $buffer; ?>

                </div>
            </div>
        </form>
    </section>
</div>
