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

class ComparisonFilter extends Filter {
  private $key1;
  private $key2;
  private $operator;
  
  /**
   * @var AbstractModelFactory
   */
  private $overrideFactory;
  
  function __construct($key1, $key2, $operator, $overrideFactory = null) {
    $this->key1 = $key1;
    $this->key2 = $key2;
    $this->operator = $operator;
    $this->overrideFactory = $overrideFactory;
  }
  
  function getQueryString($table = "") {
    if ($table != "") {
      $table = $table . ".";
    }
    if ($this->overrideFactory != null) {
      $table = $this->overrideFactory->getModelTable() . ".";
    }
    
    return $table . $this->key1 . $this->operator . $table . $this->key2;
  }
  
  function getValue() {
    return null;
  }
  
  function getHasValue() {
    return false;
  }
}

