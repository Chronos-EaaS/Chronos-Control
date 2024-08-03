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

class Experiment extends AbstractModel {
  private $experimentId;
  private $name;
  private $userId;
  private $description;
  private $systemId;
  private $phases;
  private $status;
  private $created;
  private $projectId;
  private $postData;
  private $internalId;
  private $isArchived;
  private $resultId;
  private $defaultEnvironment;
  
  function __construct($experimentId, $name, $userId, $description, $systemId, $phases, $status, $created, $projectId, $postData, $internalId, $isArchived, $resultId, $defaultEnvironment) {
    $this->experimentId = $experimentId;
    $this->name = $name;
    $this->userId = $userId;
    $this->description = $description;
    $this->systemId = $systemId;
    $this->phases = $phases;
    $this->status = $status;
    $this->created = $created;
    $this->projectId = $projectId;
    $this->postData = $postData;
    $this->internalId = $internalId;
    $this->isArchived = $isArchived;
    $this->resultId = $resultId;
    $this->defaultEnvironment = $defaultEnvironment;
  }
  
  function getKeyValueDict() {
    $dict = [];
    $dict['experimentId'] = $this->experimentId;
    $dict['name'] = $this->name;
    $dict['userId'] = $this->userId;
    $dict['description'] = $this->description;
    $dict['systemId'] = $this->systemId;
    $dict['phases'] = $this->phases;
    $dict['status'] = $this->status;
    $dict['created'] = $this->created;
    $dict['projectId'] = $this->projectId;
    $dict['postData'] = $this->postData;
    $dict['internalId'] = $this->internalId;
    $dict['isArchived'] = $this->isArchived;
    $dict['resultId'] = $this->resultId;
    $dict['defaultEnvironment'] = $this->defaultEnvironment;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "experimentId";
  }
  
  function getPrimaryKeyValue() {
    return $this->experimentId;
  }
  
  function getId() {
    return $this->experimentId;
  }
  
  function setId($id) {
    $this->experimentId = $id;
  }
  
  function getName(){
    return $this->name;
  }
  
  function setName($name){
    $this->name = $name;
  }
  
  function getUserId(){
    return $this->userId;
  }
  
  function setUserId($userId){
    $this->userId = $userId;
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
  
  function getPhases(){
    return $this->phases;
  }
  
  function setPhases($phases){
    $this->phases = $phases;
  }
  
  function getStatus(){
    return $this->status;
  }
  
  function setStatus($status){
    $this->status = $status;
  }
  
  function getCreated(){
    return $this->created;
  }
  
  function setCreated($created){
    $this->created = $created;
  }
  
  function getProjectId(){
    return $this->projectId;
  }
  
  function setProjectId($projectId){
    $this->projectId = $projectId;
  }
  
  function getPostData(){
    return $this->postData;
  }
  
  function setPostData($postData){
    $this->postData = $postData;
  }
  
  function getInternalId(){
    return $this->internalId;
  }
  
  function setInternalId($internalId){
    $this->internalId = $internalId;
  }
  
  function getIsArchived(){
    return $this->isArchived;
  }
  
  function setIsArchived($isArchived){
    $this->isArchived = $isArchived;
  }
  
  function getResultId(){
    return $this->resultId;
  }
  
  function setResultId($resultId){
    $this->resultId = $resultId;
  }
  
  function getDefaultEnvironment(){
    return $this->defaultEnvironment;
  }
  
  function setDefaultEnvironment($defaultEnvironment){
    $this->defaultEnvironment = $defaultEnvironment;
  }

  const EXPERIMENT_ID = "experimentId";
  const NAME = "name";
  const USER_ID = "userId";
  const DESCRIPTION = "description";
  const SYSTEM_ID = "systemId";
  const PHASES = "phases";
  const STATUS = "status";
  const CREATED = "created";
  const PROJECT_ID = "projectId";
  const POST_DATA = "postData";
  const INTERNAL_ID = "internalId";
  const IS_ARCHIVED = "isArchived";
  const RESULT_ID = "resultId";
  const DEFAULT_ENVIRONMENT = "defaultEnvironment";
}
