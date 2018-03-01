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

class Job extends AbstractModel {
  private $jobId;
  private $userId;
  private $description;
  private $type;
  private $systemId;
  private $environment;
  private $phases;
  private $cdl;
  private $status;
  private $progress;
  private $result;
  private $created;
  private $started;
  private $finished;
  private $evaluationId;
  private $internalId;
  
  function __construct($jobId, $userId, $description, $type, $systemId, $environment, $phases, $cdl, $status, $progress, $result, $created, $started, $finished, $evaluationId, $internalId) {
    $this->jobId = $jobId;
    $this->userId = $userId;
    $this->description = $description;
    $this->type = $type;
    $this->systemId = $systemId;
    $this->environment = $environment;
    $this->phases = $phases;
    $this->cdl = $cdl;
    $this->status = $status;
    $this->progress = $progress;
    $this->result = $result;
    $this->created = $created;
    $this->started = $started;
    $this->finished = $finished;
    $this->evaluationId = $evaluationId;
    $this->internalId = $internalId;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['jobId'] = $this->jobId;
    $dict['userId'] = $this->userId;
    $dict['description'] = $this->description;
    $dict['type'] = $this->type;
    $dict['systemId'] = $this->systemId;
    $dict['environment'] = $this->environment;
    $dict['phases'] = $this->phases;
    $dict['cdl'] = $this->cdl;
    $dict['status'] = $this->status;
    $dict['progress'] = $this->progress;
    $dict['result'] = $this->result;
    $dict['created'] = $this->created;
    $dict['started'] = $this->started;
    $dict['finished'] = $this->finished;
    $dict['evaluationId'] = $this->evaluationId;
    $dict['internalId'] = $this->internalId;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "jobId";
  }
  
  function getPrimaryKeyValue() {
    return $this->jobId;
  }
  
  function getId() {
    return $this->jobId;
  }
  
  function setId($id) {
    $this->jobId = $id;
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
  
  function getType(){
    return $this->type;
  }
  
  function setType($type){
    $this->type = $type;
  }
  
  function getSystemId(){
    return $this->systemId;
  }
  
  function setSystemId($systemId){
    $this->systemId = $systemId;
  }
  
  function getEnvironment(){
    return $this->environment;
  }
  
  function setEnvironment($environment){
    $this->environment = $environment;
  }
  
  function getPhases(){
    return $this->phases;
  }
  
  function setPhases($phases){
    $this->phases = $phases;
  }
  
  function getCdl(){
    return $this->cdl;
  }
  
  function setCdl($cdl){
    $this->cdl = $cdl;
  }
  
  function getStatus(){
    return $this->status;
  }
  
  function setStatus($status){
    $this->status = $status;
  }
  
  function getProgress(){
    return $this->progress;
  }
  
  function setProgress($progress){
    $this->progress = $progress;
  }
  
  function getResult(){
    return $this->result;
  }
  
  function setResult($result){
    $this->result = $result;
  }
  
  function getCreated(){
    return $this->created;
  }
  
  function setCreated($created){
    $this->created = $created;
  }
  
  function getStarted(){
    return $this->started;
  }
  
  function setStarted($started){
    $this->started = $started;
  }
  
  function getFinished(){
    return $this->finished;
  }
  
  function setFinished($finished){
    $this->finished = $finished;
  }
  
  function getEvaluationId(){
    return $this->evaluationId;
  }
  
  function setEvaluationId($evaluationId){
    $this->evaluationId = $evaluationId;
  }
  
  function getInternalId(){
    return $this->internalId;
  }
  
  function setInternalId($internalId){
    $this->internalId = $internalId;
  }

  const JOB_ID = "jobId";
  const USER_ID = "userId";
  const DESCRIPTION = "description";
  const TYPE = "type";
  const SYSTEM_ID = "systemId";
  const ENVIRONMENT = "environment";
  const PHASES = "phases";
  const CDL = "cdl";
  const STATUS = "status";
  const PROGRESS = "progress";
  const RESULT = "result";
  const CREATED = "created";
  const STARTED = "started";
  const FINISHED = "finished";
  const EVALUATION_ID = "evaluationId";
  const INTERNAL_ID = "internalId";
}
