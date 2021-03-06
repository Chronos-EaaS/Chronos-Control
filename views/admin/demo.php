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
            Demo Functions
        </h1>
    </section>

    <section class="content">

        <div class="row">
            <div class="col-md-6">
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Reset to Demo Initial State</h3>
                    </div>
                    <div class="box-body">
                        <button type="button" class="btn btn-block btn-warning btn-lg" onclick="location.href='/admin/demo/reset=true';"><span class="fa fa-eraser"></span> Reset</button>
                    </div>
                </div>
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Reset Local Systems</h3>
                    </div>
                    <div class="box-body">
                        <button type="button" class="btn btn-block btn-warning btn-lg" onclick="location.href='/admin/demo/local=true';"><span class="fa fa-eraser"></span> Reset</button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>