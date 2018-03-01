<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// CSS
$this->includeCSS('/assets/' . $dirname . '/css/font-awesome.min.css');
