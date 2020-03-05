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

class Project extends AbstractModel {
  private $projectId;
  private $name;
  private $description;
  private $userId;
  private $systemId;
  private $isFinished;
  private $environment;
  private $isArchived;
  
  function __construct($projectId, $name, $description, $userId, $systemId, $isFinished, $environment, $isArchived) {
    $this->projectId = $projectId;
    $this->name = $name;
    $this->description = $description;
    $this->userId = $userId;
    $this->systemId = $systemId;
    $this->isFinished = $isFinished;
    $this->environment = $environment;
    $this->isArchived = $isArchived;
  }
  
  function getKeyValueDict() {
    $dict = [];
    $dict['projectId'] = $this->projectId;
    $dict['name'] = $this->name;
    $dict['description'] = $this->description;
    $dict['userId'] = $this->userId;
    $dict['systemId'] = $this->systemId;
    $dict['isFinished'] = $this->isFinished;
    $dict['environment'] = $this->environment;
    $dict['isArchived'] = $this->isArchived;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "projectId";
  }
  
  function getPrimaryKeyValue() {
    return $this->projectId;
  }
  
  function getId() {
    return $this->projectId;
  }
  
  function setId($id) {
    $this->projectId = $id;
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
  
  function getUserId(){
    return $this->userId;
  }
  
  function setUserId($userId){
    $this->userId = $userId;
  }
  
  function getSystemId(){
    return $this->systemId;
  }
  
  function setSystemId($systemId){
    $this->systemId = $systemId;
  }
  
  function getIsFinished(){
    return $this->isFinished;
  }
  
  function setIsFinished($isFinished){
    $this->isFinished = $isFinished;
  }
  
  function getEnvironment(){
    return $this->environment;
  }
  
  function setEnvironment($environment){
    $this->environment = $environment;
  }
  
  function getIsArchived(){
    return $this->isArchived;
  }
  
  function setIsArchived($isArchived){
    $this->isArchived = $isArchived;
  }

  const PROJECT_ID = "projectId";
  const NAME = "name";
  const DESCRIPTION = "description";
  const USER_ID = "userId";
  const SYSTEM_ID = "systemId";
  const IS_FINISHED = "isFinished";
  const ENVIRONMENT = "environment";
  const IS_ARCHIVED = "isArchived";
}
