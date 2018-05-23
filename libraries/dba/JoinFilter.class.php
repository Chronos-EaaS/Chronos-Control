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

class JoinFilter extends Join  {
  /**
   * @var AbstractModelFactory
   */
  private $otherFactory;
  
  /**
   * @var string
   */
  private $match1;
  
  /**
   * @var string
   */
  private $match2;
  
  /**
   * @var string
   */
  private $otherTableName;
  
  /**
   * @var AbstractModelFactory
   */
  private $overrideOwnFactory;
  
  /**
   * JoinFilter constructor.
   * @param $otherFactory AbstractModelFactory
   * @param $matching1 string
   * @param $matching2 string
   * @param $overrideOwnFactory AbstractModelFactory
   */
  function __construct($otherFactory, $matching1, $matching2, $overrideOwnFactory = null) {
    $this->otherFactory = $otherFactory;
    $this->match1 = $matching1;
    $this->match2 = $matching2;
    
    $this->otherTableName = $this->otherFactory->getModelTable();
    $this->overrideOwnFactory = $overrideOwnFactory;
  }
  
  /**
   * @return AbstractModelFactory
   */
  function getOtherFactory() {
    return $this->otherFactory;
  }
  
  function getMatch1() {
    return $this->match1;
  }
  
  function getMatch2() {
    return $this->match2;
  }
  
  function getOtherTableName() {
    return $this->otherTableName;
  }
  
  /**
   * @return AbstractModelFactory
   */
  function getOverrideOwnFactory(){
      return $this->overrideOwnFactory;
  }
}


