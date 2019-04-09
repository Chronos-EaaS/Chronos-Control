<?php

$path = explode('/' , __DIR__);
$dirname = array_pop($path);

// JS
$this->includeJS('/assets/' . $dirname . '/gitgraph.min.js');
