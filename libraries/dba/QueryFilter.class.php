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

class QueryFilter extends Filter {
  private $key;
  private $value;
  private $operator;
  /**
   * @var AbstractModelFactory
   */
  private $factory;
  
  function __construct($key, $value, $operator, $factory = null) {
    $this->key = $key;
    $this->value = $value;
    $this->operator = $operator;
    $this->factory = $factory;
  }
  
  function getQueryString($table = "") {
    if ($table != "") {
      $table = $table . ".";
    }
    if ($this->factory != null) {
      $table = $this->factory->getModelTable() . ".";
    }
    
    if ($this->value === null) {
      if ($this->operator == '<>') {
        return $table . $this->key . " IS NOT NULL ";
      }
      return $table . $this->key . " IS NULL ";
    }
    return $table . $this->key . $this->operator . "?";
  }
  
  function getValue() {
    if ($this->value === null) {
      return null;
    }
    return $this->value;
  }
  
  function getHasValue() {
    if ($this->value === null) {
      return false;
    }
    return true;
  }
}
