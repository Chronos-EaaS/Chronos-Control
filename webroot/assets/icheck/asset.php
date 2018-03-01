<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// CSS
$this->includeCSS('/assets/' . $dirname . '/square/blue.css');
$this->includeCSS('/assets/' . $dirname . '/minimal/minimal.css');
$this->includeCSS('/assets/' . $dirname . '/minimal/blue.css');

// JavaScript
$this->includeJS('/assets/' . $dirname . '/icheck.min.js');
