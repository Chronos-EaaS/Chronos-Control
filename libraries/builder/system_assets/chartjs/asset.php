<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// JavaScript
$this->includeJS('/system_assets/' . $dirname . '/js/Chart.min.js');
