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

class CDL_Library {

    private $system;

    private $dom;
    private $root;

    private $setup;
    private $data;
    private $evaluation;

    public function __construct($system) {
        $this->system = $system;
        $this->dom = new DOMDocument('1.0', 'utf-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = true;
        $this->root = $this->dom->createElement('chronos');
        $this->dom->appendChild($this->root);
        $this->getSetup();
        $this->getEvaluation();
    }
    
    public function __clone() {
        $dom = $this->root->cloneNode(true);
        $this->dom = new DOMDocument('1.0', 'utf-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = true;
        $this->dom->appendChild($this->dom->importNode($dom, true));
        $this->root = $this->dom->getElementsByTagName('chronos')->item(0);
        $this->evaluation = $this->dom->getElementsByTagName('evaluation')->item(0);
        $this->setup = $this->dom->getElementsByTagName('setup')->item(0);
    }
  
    public function getSetup() {
        if(empty($this->setup)) {
            $this->root->appendChild($this->setup = $this->dom->createElement('setup'));
        }
        return $this->setup;
    }

    public function getEvaluation() {
        if(empty($this->evaluation)) {
            $this->root->appendChild($this->evaluation = $this->dom->createElement('evaluation'));
            $this->evaluation->setAttribute('system', $this->system);
        }
        return $this->evaluation;
    }

    public function getData() {
        if(empty($this->data)) {
            $this->root->appendChild($this->data = $this->dom->createElement('generator'));
            //$this->data->setAttribute('generator', $this->system->uniqueName);
            $this->data->setAttribute('system', $this->system);
        }
        return $this->data;
    }

    public function createElement($name, $value = null) {
        return $this->dom->createElement($name, $value);
    }

    public function toXML() {
        return str_replace("&#13;", "", $this->dom->saveXML());
    }

}
	