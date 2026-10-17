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

abstract class AbstractModel {
  /**
   * Returns a dict with all keys and associated values for this array
   * This is used for update queries.
   */
  abstract function getKeyValueDict();
  
  /**
   * This function should return the primary key of the used object.
   */
  abstract function getPrimaryKey();
  
  /**
   * This function should return the value of the primary key of the used object
   */
  abstract function getPrimaryKeyValue();
  
  /**
   * This function is used to set the id to the real database value
   * @param $id string
   * @return
   */
  abstract function setId($id);
  
  /**
   * this function returns the models id
   */
  abstract function getId();
}
