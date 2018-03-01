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

$this->includeAsset('chartjs');
$this->includeAsset('plotly');

$colors = array("#00a65a", "#1C86EE", "#FF7F24", "#CD1076", "#FFD700", "#FF00FF", "#00FFFF", "#80FF00", "#FF0000", "#BDBDBD", "#01A9DB", "#380B61");

// Runtime Chart
$minValue = 0;
$maxValue = 0;
$dataArray = array();
$runtimeChartData = new stdClass();
$runtimeChartData->datasets = array();
$runtimeChartData->labels = array();
foreach ($data["runtimes"] as $store => $runtime) {
    array_push($runtimeChartData->labels, $store);
    array_push($dataArray, intval($runtime) / 1000);
    if ($runtime > $maxValue) {
        $maxValue = $runtime;
    }
    if ($runtime < $minValue || $minValue == 0) {
        $minValue = $runtime;
    }
}
$runtimeChartData->datasets[] = new stdClass();
$runtimeChartData->datasets[0]->label = "Runtime";
$runtimeChartData->datasets[0]->fillColor = "#00a65a";
$runtimeChartData->datasets[0]->strokeColor = "#00a65a";
$runtimeChartData->datasets[0]->pointColor = "#00a65a";
$runtimeChartData->datasets[0]->data = $dataArray;

$this->includeInlineJS('
$(function () {   
    var barChartOptions = {
        //Boolean - Whether the scale should start at zero, or an order of magnitude down from the lowest value
        scaleBeginAtZero: true,
        //Boolean - Whether grid lines are shown across the chart
        scaleShowGridLines: true,
        //String - Colour of the grid lines
        scaleGridLineColor: "rgba(0,0,0,.05)",
        //Number - Width of the grid lines
        scaleGridLineWidth: 1,
        //Boolean - Whether to show horizontal lines (except X axis)
        scaleShowHorizontalLines: true,
        //Boolean - Whether to show vertical lines (except Y axis)
        scaleShowVerticalLines: true,
        //Boolean - If there is a stroke on each bar
        barShowStroke: true,
        //Number - Pixel width of the bar stroke
        barStrokeWidth: 2,
        //Number - Spacing between each of the X value sets
        barValueSpacing: 5,
        //Number - Spacing between data sets within X values
        barDatasetSpacing: 1,
        //String - A legend template
        legendTemplate: "<ul class=\"<%=name.toLowerCase()%>-legend\"><% for (var i=0; i<datasets.length; i++){%><li><span style=\"background-color:<%=datasets[i].fillColor%>\"></span><%if(datasets[i].label){%><%=datasets[i].label%><%}%></li><%}%></ul>",
        //Boolean - whether to make the chart responsive
        responsive: true,
        maintainAspectRatio: true,
    };
    barChartOptions.datasetFill = false;
    
    var barChartRuntimeCanvas = $("#barChartRuntime").get(0).getContext("2d");
    var barChartRuntime = new Chart(barChartRuntimeCanvas);
    var barChartRuntimeData = ' . json_encode($runtimeChartData) . ';
    barChartRuntime.Bar(barChartRuntimeData, barChartOptions);
  });
  
');
?>
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">Data</h3>
    </div>
    <div class="box-body">
        <p>Fastest Job: <?php echo $data['fastestJob']; ?></p>
        <p>Time: <?php echo number_format((floatval($data['fastestJobRuntime']) / 1000), 3) . ' s'; ?></p>
    </div>
</div>


<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">Job Runtime</h3>
    </div>
    <div class="box-body">
        <div class="chart">
            <canvas height="230" width="787" id="barChartRuntime" style="height: 230px; width: 787px;"></canvas>
        </div>
    </div>
</div>
