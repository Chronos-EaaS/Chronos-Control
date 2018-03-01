<?php

require_once(dirname(__FILE__) . "/zip/src/ZipStream.php");

use ZipStream\ZipStream;

class Zip_Library {
    private $zip = null;

    public function __construct($name) {
        $this->zip = new ZipStream($name);
    }

    function addFile($data, $name) {
        $this->zip->addFile($name, $data);
    }

    function done() {
        $this->zip->finish();
    }
}