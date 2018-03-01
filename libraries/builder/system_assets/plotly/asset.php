<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// JavaScript
$this->includeJS('/system_assets/' . $dirname . '/plotly-latest.min.js');

