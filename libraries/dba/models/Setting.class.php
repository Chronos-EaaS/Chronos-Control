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

class Setting extends AbstractModel {
  private $settingId;
  private $section;
  private $item;
  private $value;
  private $systemId;
  
  function __construct($settingId, $section, $item, $value, $systemId) {
    $this->settingId = $settingId;
    $this->section = $section;
    $this->item = $item;
    $this->value = $value;
    $this->systemId = $systemId;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['settingId'] = $this->settingId;
    $dict['section'] = $this->section;
    $dict['item'] = $this->item;
    $dict['value'] = $this->value;
    $dict['systemId'] = $this->systemId;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "settingId";
  }
  
  function getPrimaryKeyValue() {
    return $this->settingId;
  }
  
  function getId() {
    return $this->settingId;
  }
  
  function setId($id) {
    $this->settingId = $id;
  }
  
  function getSection(){
    return $this->section;
  }
  
  function setSection($section){
    $this->section = $section;
  }
  
  function getItem(){
    return $this->item;
  }
  
  function setItem($item){
    $this->item = $item;
  }
  
  function getValue(){
    return $this->value;
  }
  
  function setValue($value){
    $this->value = $value;
  }
  
  function getSystemId(){
    return $this->systemId;
  }
  
  function setSystemId($systemId){
    $this->systemId = $systemId;
  }

  const SETTING_ID = "settingId";
  const SECTION = "section";
  const ITEM = "item";
  const VALUE = "value";
  const SYSTEM_ID = "systemId";
}
