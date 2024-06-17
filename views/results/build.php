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

$location = "/admin/system/id=" . $data['system']->getId();
if($data['experimentId'] != 0){
    $location = "/experiment/detail/id=" . $data['experimentId'];
}
$this->includeInlineJS("
    function s4() {
        return Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
    }
    
    function uid() {
        return s4() + s4() + '-' + s4() + '-' + s4() + '-' + s4() + '-' + s4() + s4() + s4();
    }
    
    function createNewPlot(){
        var plotType = $('#plot-form').find('select[name=\"type\"]').val();
        document.getElementById('plot-form').reset()
        var id = uid();
        $.ajax({
            url : '/api/ui/results/uid=' + id + '/type=' + plotType + '/systemId=" . $data['system']->getId() . "/action=newplot/resultId=" . $data['resultId'] . "',
            type : 'GET',
            dataType: 'json'
        }).done(function(data, status) {
            if(data.status.code == 200){
                $('#build-content').append(atob(data.response)); 
            }
            else{
                alert('Error on creating new plot: ' + data.status.message);
            }
        });
    }
    
    function u_btoa(buffer) {
        var binary = [];
        var bytes = new Uint8Array(buffer);
        for (var i = 0, il = bytes.byteLength; i < il; i++) {
            binary.push(String.fromCharCode(bytes[i]));
        }
        return btoa(binary.join(''));
    }
    
    function deletePlot(id){
        if(confirm('Do you really want to delete this plot?')){
            $(\"#\" + id).remove();
        }
    }
    function movePlots(direction, id) {
        if (direction == 'up') {
            swap('up');
        }
        elif (direction == 'down') {
            swap('down');
        }
        else {
            alert('direction is neither up or down');
        }
    }
    function saveBuild(){
        var top = $('#build-content');
        var data = [];
        top.children('div').each(function() { 
            var plot = $(this);
            var plotObject = {\"id\": plot.attr('id')};
            var elements = [];
            plot.find('*').filter(':input').each(function(){
                var input = $(this);
                if(input.attr('name') != undefined){
                    plotObject[input.attr('name')] = input.val();
                }
            });
            data.push(plotObject);
        });
        var content = JSON.stringify(data);
        var id = $('#systemId').val();
        var resultId = $('#resultId').val();
        var experimentId = $('#experimentId').val();
        $.ajax({
            url : '/api/ui/results/',
            data : {
                'systemId' : id,
                'resultId': resultId,
                'experimentId': experimentId,
                'type': " . $data['type'] . ",
                'content' : u_btoa(new TextEncoder().encode(content))
            },
            type : 'PATCH',
            dataType: 'json'
        }).done(function(data, status) {
            if(data.status.code == 200){
                window.location='$location';
            }
            else{
                alert('Error on creating new plot: ' + data.status.message);
            }
        });
    }
");
?>
<div class="content-wrapper">
    <form id="form" action="#" method="POST">
        <section class="content-header">
            <h1>Result builder (<?php echo $data['system']->getName() ?> - <?php echo ($data['type'] == Results_Library::TYPE_ALL)?"All Jobs":"Single Jobs"; ?>)</h1>
            <ol class="breadcrumb">
                <li><a href="/home/main">Home</a></li>
                <?php if($data['experimentId'] != 0) {?>
                    <li><a href="/project/detail/id=<?php echo $data['experiment']->getProjectId() ?>">Project</a></li>
                    <li><a href="/experiment/detail/id=<?php echo $data['experiment']->getId() ?>">Experiment</a></li>
                <?php } else { ?>
                    <li><a href="/admin/systems">Systems</a></li>
                    <li><a href="/admin/system/id=<?php echo $data['system']->getId() ?>">System</a></li>
                <?php } ?>
                <li class="active">Result Builder</li>
            </ol>
        </section>

        <section class="content">
            <div class="row">

                <div class="col-md-12">

                    <!-- Add Graph -->
                    <a class="btn btn-app" data-toggle="modal" data-target="#modal-plot">
                        <i class="fa fa-plus"></i> Add Graph
                    </a>

                    <!-- Reset -->
                    <a class="btn btn-app" onclick="window.location.reload();">
                        <i class="fa fa-undo"></i> Reset
                    </a>

                    <!-- Save -->
                    <a class="btn btn-app" onclick="saveBuild();">
                        <i class="fa fa-save"></i> Save
                    </a>
                </div>
            </div>
            <h2 class="page-header">System Result Elements</h2>
            <div class="row">
                <div class="col-md-12">
                    <input type="hidden" id="systemId" name="systemId" value="<?php echo $data['system']->getId() ?>">
                    <input type="hidden" id="resultId" name="resultId" value="<?php echo $data['resultId'] ?>">
                    <?php if($data['experimentId'] != 0) {?>
                        <input type="hidden" id="experimentId" name="experimentId" value="<?php echo $data['experiment']->getId() ?>">
                    <?php } ?>
                    <div id="build-content">
                        <?php echo $data['content'] ?>
                    </div>
                </div>
            </div>
        </section>
    </form>
</div>

<div class="modal fade" id="modal-plot">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"
                        onclick="document.getElementById('plot-form').reset()">
                    <span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Add Element</h4>
            </div>
            <div class="modal-body">
                <form action="#" id="plot-form">
                    <div class="form-group">
                        <label>Plot Type</label>
                        <select class="form-control" name="type" title="Plot Type">
                            <option value="">&nbsp;</option>
                            <?php foreach ($data['plots'] as $type) { ?>
                                <option value="<?php echo $type->getType() ?>"><?php echo $type->getName() ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="createNewPlot()">Add
                </button>
                <button type="button" class="btn btn-default pull-left" data-dismiss="modal"
                        onclick="document.getElementById('plot-form').reset()">Close
                </button>
            </div>
        </div>
    </div>
</div>