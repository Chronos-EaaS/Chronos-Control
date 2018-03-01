<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// CSS
$this->includeCSS('/assets/' . $dirname . '/dataTables.bootstrap.css');

// JavaScript
$this->includeJS('/assets/' . $dirname . '/jquery.dataTables.min.js');
$this->includeJS('/assets/' . $dirname . '/dataTables.bootstrap.min.js');
