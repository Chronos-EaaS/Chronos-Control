<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Sein Coray

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

namespace DBA;

class ContainFilter extends Filter {
  private $key;
  private $values;
  /**
   * @var AbstractModelFactory
   */
  private $factory;
  private $inverse;
  
  function __construct($key, $values, $factory = null, $inverse = false) {
    $this->key = $key;
    $this->values = $values;
    $this->factory = $factory;
    $this->inverse = $inverse;
  }
  
  function getQueryString($table = "") {
    if ($table != "") {
      $table = $table . ".";
    }
    if ($this->factory != null) {
      $table = $this->factory->getModelTable() . ".";
    }
    
    $app = [];
    for ($x = 0; $x < sizeof($this->values); $x++) {
      $app[] = "?";
    }
    if (sizeof($app) == 0) {
      if ($this->inverse) {
        return "TRUE";
      }
      return "FALSE";
    }
    return $table . $this->key . (($this->inverse) ? " NOT" : "") . " IN (" . implode(",", $app) . ")";
  }
  
  function getValue() {
    return $this->values;
  }
  
  function getHasValue() {
    return true;
  }
}
