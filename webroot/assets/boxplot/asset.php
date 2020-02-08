<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// JavaScript
$this->includeJS('/assets/' . $dirname . '/js/Chart.BoxPlot.min.js');
