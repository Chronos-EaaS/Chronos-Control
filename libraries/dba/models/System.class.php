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

class System extends AbstractModel {
  private $systemId;
  private $name;
  private $description;
  private $userId;
  private $vcsUrl;
  private $vcsBranch;
  private $vcsType;
  private $vcsUser;
  private $vcsPassword;
  private $created;
  private $lastEdit;
  private $builderJson;
  
  function __construct($systemId, $name, $description, $userId, $vcsUrl, $vcsBranch, $vcsType, $vcsUser, $vcsPassword, $created, $lastEdit, $builderJson) {
    $this->systemId = $systemId;
    $this->name = $name;
    $this->description = $description;
    $this->userId = $userId;
    $this->vcsUrl = $vcsUrl;
    $this->vcsBranch = $vcsBranch;
    $this->vcsType = $vcsType;
    $this->vcsUser = $vcsUser;
    $this->vcsPassword = $vcsPassword;
    $this->created = $created;
    $this->lastEdit = $lastEdit;
    $this->builderJson = $builderJson;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['systemId'] = $this->systemId;
    $dict['name'] = $this->name;
    $dict['description'] = $this->description;
    $dict['userId'] = $this->userId;
    $dict['vcsUrl'] = $this->vcsUrl;
    $dict['vcsBranch'] = $this->vcsBranch;
    $dict['vcsType'] = $this->vcsType;
    $dict['vcsUser'] = $this->vcsUser;
    $dict['vcsPassword'] = $this->vcsPassword;
    $dict['created'] = $this->created;
    $dict['lastEdit'] = $this->lastEdit;
    $dict['builderJson'] = $this->builderJson;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "systemId";
  }
  
  function getPrimaryKeyValue() {
    return $this->systemId;
  }
  
  function getId() {
    return $this->systemId;
  }
  
  function setId($id) {
    $this->systemId = $id;
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
  
  function getVcsUrl(){
    return $this->vcsUrl;
  }
  
  function setVcsUrl($vcsUrl){
    $this->vcsUrl = $vcsUrl;
  }
  
  function getVcsBranch(){
    return $this->vcsBranch;
  }
  
  function setVcsBranch($vcsBranch){
    $this->vcsBranch = $vcsBranch;
  }
  
  function getVcsType(){
    return $this->vcsType;
  }
  
  function setVcsType($vcsType){
    $this->vcsType = $vcsType;
  }
  
  function getVcsUser(){
    return $this->vcsUser;
  }
  
  function setVcsUser($vcsUser){
    $this->vcsUser = $vcsUser;
  }
  
  function getVcsPassword(){
    return $this->vcsPassword;
  }
  
  function setVcsPassword($vcsPassword){
    $this->vcsPassword = $vcsPassword;
  }
  
  function getCreated(){
    return $this->created;
  }
  
  function setCreated($created){
    $this->created = $created;
  }
  
  function getLastEdit(){
    return $this->lastEdit;
  }
  
  function setLastEdit($lastEdit){
    $this->lastEdit = $lastEdit;
  }
  
  function getBuilderJson(){
    return $this->builderJson;
  }
  
  function setBuilderJson($builderJson){
    $this->builderJson = $builderJson;
  }

  const SYSTEM_ID = "systemId";
  const NAME = "name";
  const DESCRIPTION = "description";
  const USER_ID = "userId";
  const VCS_URL = "vcsUrl";
  const VCS_BRANCH = "vcsBranch";
  const VCS_TYPE = "vcsType";
  const VCS_USER = "vcsUser";
  const VCS_PASSWORD = "vcsPassword";
  const CREATED = "created";
  const LAST_EDIT = "lastEdit";
  const BUILDER_JSON = "builderJson";
}
