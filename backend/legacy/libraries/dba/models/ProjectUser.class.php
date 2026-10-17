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

class ProjectUser extends AbstractModel {
  private $projectUserId;
  private $userId;
  private $projectId;
  
  function __construct($projectUserId, $userId, $projectId) {
    $this->projectUserId = $projectUserId;
    $this->userId = $userId;
    $this->projectId = $projectId;
  }
  
  function getKeyValueDict() {
    $dict = [];
    $dict['projectUserId'] = $this->projectUserId;
    $dict['userId'] = $this->userId;
    $dict['projectId'] = $this->projectId;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "projectUserId";
  }
  
  function getPrimaryKeyValue() {
    return $this->projectUserId;
  }
  
  function getId() {
    return $this->projectUserId;
  }
  
  function setId($id) {
    $this->projectUserId = $id;
  }
  
  function getUserId(){
    return $this->userId;
  }
  
  function setUserId($userId){
    $this->userId = $userId;
  }
  
  function getProjectId(){
    return $this->projectId;
  }
  
  function setProjectId($projectId){
    $this->projectId = $projectId;
  }

  const PROJECT_USER_ID = "projectUserId";
  const USER_ID = "userId";
  const PROJECT_ID = "projectId";
}
