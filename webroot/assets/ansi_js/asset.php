<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// JavaScript
$this->includeJS('/assets/' . $dirname . '/js/ansi_up.js');
