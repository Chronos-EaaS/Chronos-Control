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
    function s4() {
        return Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
    }
    
    function uid() {
        return s4() + s4() + '-' + s4() + '-' + s4() + '-' + s4() + '-' + s4() + s4() + s4();
    }
    
    function createGroup(){
        var name = $('#group-form').find('input[name=\"name\"]').val();
        document.getElementById('group-form').reset()
        var id = uid();
        $.ajax({
            url : '/api/v1/builder/',
            data : {
                'uid' : id,
                'name' : name
            },
            type : 'NEWGROUP',
            dataType: 'json'
        }).done(function(data, status) {
            $('#build-content').append(atob(data.response));
        });
    }
    
    function createNewElement(){
        var elementType = $('#element-form').find('select[name=\"type\"]').val();
        var groupId = $('#forGroup').val();
        document.getElementById('element-form').reset()
        var id = uid();
        $.ajax({
            url : '/api/v1/builder/',
            data : {
                'uid' : id,
                'type' : elementType,
                'systemId': " . $data['system']->getId() . "
            },
            type : 'NEWELEMENT',
            dataType: 'json'
        }).done(function(data, status) {
            $('#' + groupId).find('.box-body').append(atob(data.response));
        });
    }
    
    function addElement(groupId){
        $('#modal-element').modal();
        $('#forGroup').val(groupId);
    }
    
    $('element-form').on(\"keypress\", function (e) {
        if (e.which == 13) createNewElement();
    });
    $('group-form').on(\"keypress\", function (e) {
        if (e.which == 13) createGroup();
    });
    
    function u_btoa(buffer) {
        var binary = [];
        var bytes = new Uint8Array(buffer);
        for (var i = 0, il = bytes.byteLength; i < il; i++) {
            binary.push(String.fromCharCode(bytes[i]));
        }
        return btoa(binary.join(''));
    }
    
    function deleteElement(id){
        if(confirm('Do you really want to delete this element?')){
            $(\"#\" + id).remove();
        }
    }
    
    function deleteGroup(id){
        if(confirm('Do you really want to delete this group?')){
            $(\"#\" + id).remove();
        }
    }
    
    function saveBuild(){
        var top = $('#build-content');
        var data = [];
        top.children('div').each(function() { 
            var group = $(this);
            var groupObject = {\"id\": group.attr('id'), \"title\": group.find('.box-title').text()};
            var elements = [];
            group.find('.box-body').children('div').each(function(){
                var elem = $(this);
                var elemObject = {\"id\": elem.attr('id')}
                elem.find('input').each(function(){
                    var o = $(this);
                    elemObject[o.attr('name')] = o.val();
                });
                elements.push(elemObject);
            });
            groupObject['elements'] = elements;
            data.push(groupObject);
        });
        var content = JSON.stringify(data);
        var id = $('#systemId').val();
        $.ajax({
            url : '/api/v1/builder/',
            data : {
                'systemId' : id,
                'content' : u_btoa(new TextEncoder().encode(content))
            },
            type : 'SAVE',
            dataType: 'json'
        }).done(function() {
            window.location='/admin/system/id=" . $data['system']->getId() . "';
        });
    }
");
?>
<div class="content-wrapper">
    <section class="content-header">
        <h1>System Parameter builder (<?php echo $data['system']->getName() ?>)</h1>
        <ol class="breadcrumb">
            <li><a href="/home/main">Home</a></li>
            <li><a href="/admin/systems">Systems</a></li>
            <li><a href="/admin/system/id=<?php echo $data['system']->getId() ?>">System</a></li>
            <li class="active">Parameter Builder</li>
        </ol>
    </section>

    <section class="content">
        <div class="row">

            <div class="col-md-12">

                <!-- Add Group -->
                <a class="btn btn-app" data-toggle="modal" data-target="#modal-group">
                    <i class="fa fa-plus"></i> Add Group
                </a>

                <!-- Reset -->
                <a class="btn btn-app" onclick="window.location.reload();">
                    <i class="fa fa-undo"></i> Reset
                </a>

                <!-- Save -->
                <a class="btn btn-app" href="#" onclick="saveBuild();">
                    <i class="fa fa-floppy-o"></i> Save
                </a>
            </div>
        </div>
        <h2 class="page-header">System Experiment Elements</h2>
        <div class="row">
            <div class="col-md-12">
                <input type="hidden" id="systemId" name="systemId" value="<?php echo $data['system']->getId() ?>">
                <div id="build-content">
                    <?php echo $data['content'] ?>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="modal-group">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"
                        onclick="document.getElementById('group-form').reset()">
                    <span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Add Group</h4>
            </div>
            <div class="modal-body">
                <form action="#" id="group-form">
                    <div class="form-group">
                        <label>Name</label>
                        <input name="name" type="text" class="form-control" placeholder="Group Name">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="createGroup()">Add</button>
                <button type="button" class="btn btn-default pull-left" data-dismiss="modal"
                        onclick="document.getElementById('group-form').reset()">Close
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-element">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"
                        onclick="document.getElementById('element-form').reset()">
                    <span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Add Element</h4>
            </div>
            <div class="modal-body">
                <form action="#" id="element-form">
                    <input type="hidden" name="forGroup" id="forGroup" value="">
                    <div class="form-group">
                        <label>Element Type</label>
                        <select class="form-control" name="type">
                            <option value="">&nbsp;</option>
                            <?php foreach ($data['elementTypes'] as $type) { ?>
                                <option value="<?php echo $type->getType() ?>"><?php echo $type->getName() ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="createNewElement()">Add
                </button>
                <button type="button" class="btn btn-default pull-left" data-dismiss="modal"
                        onclick="document.getElementById('element-form').reset()">Close
                </button>
            </div>
        </div>
    </div>
</div>