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

class Evaluation extends AbstractModel {
  private $evaluationId;
  private $name;
  private $description;
  private $systemId;
  private $experimentId;
  private $internalId;
  
  function __construct($evaluationId, $name, $description, $systemId, $experimentId, $internalId) {
    $this->evaluationId = $evaluationId;
    $this->name = $name;
    $this->description = $description;
    $this->systemId = $systemId;
    $this->experimentId = $experimentId;
    $this->internalId = $internalId;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['evaluationId'] = $this->evaluationId;
    $dict['name'] = $this->name;
    $dict['description'] = $this->description;
    $dict['systemId'] = $this->systemId;
    $dict['experimentId'] = $this->experimentId;
    $dict['internalId'] = $this->internalId;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "evaluationId";
  }
  
  function getPrimaryKeyValue() {
    return $this->evaluationId;
  }
  
  function getId() {
    return $this->evaluationId;
  }
  
  function setId($id) {
    $this->evaluationId = $id;
  }
  
  function getName(){
    return $this->name;
  }
  
  function setName($name){
    $this->name = $name;
  }
  
  function getDescription(){
    return $this->description;
  }
  
  function setDescription($description){
    $this->description = $description;
  }
  
  function getSystemId(){
    return $this->systemId;
  }
  
  function setSystemId($systemId){
    $this->systemId = $systemId;
  }
  
  function getExperimentId(){
    return $this->experimentId;
  }
  
  function setExperimentId($experimentId){
    $this->experimentId = $experimentId;
  }
  
  function getInternalId(){
    return $this->internalId;
  }
  
  function setInternalId($internalId){
    $this->internalId = $internalId;
  }

  const EVALUATION_ID = "evaluationId";
  const NAME = "name";
  const DESCRIPTION = "description";
  const SYSTEM_ID = "systemId";
  const EXPERIMENT_ID = "experimentId";
  const INTERNAL_ID = "internalId";
}
