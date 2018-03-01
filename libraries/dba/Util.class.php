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

class Util {
  /**
   * Used to cast database objects into their corresponding type
   *
   * @param $obj
   * @param $to_class
   * @return mixed|null
   */
  public static function cast($obj, $to_class) {
    if($obj == null){
      return null;
    }
    else if (class_exists($to_class)) {
      $obj_in = serialize($obj);
      $obj_in_split = explode(":", $obj_in);
      unset($obj_in_split[0]);
      unset($obj_in_split[1]);
      unset($obj_in_split[2]);
      $obj_out = 'O:' . strlen($to_class) . ':"' . $to_class . '":' . implode(":", $obj_in_split);
      return unserialize($obj_out);
    }
    else {
      return null;
    }
  }
  
  /**
   * Used to create the full select string of a table query
   * @param $table string
   * @param $dict array
   * @return string
   */
  public static function createPrefixedString($table, $dict) {
    $arr = array();
    foreach ($dict as $key => $val) {
      $arr[] = "`" . $table . "`" . "." . "`" . $key . "`" . " AS `" . $table . "." . $key . "`";
    }
    return implode(", ", $arr);
  }
  
  /**
   * Checks if $search starts with $pattern. Shortcut for strpos==0
   * @param $search string
   * @param $pattern string
   * @return bool
   */
  public static function startsWith($search, $pattern) {
    if (strpos($search, $pattern) === 0) {
      return true;
    }
    return false;
  }
}