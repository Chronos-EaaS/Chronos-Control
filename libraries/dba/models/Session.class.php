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

class Session extends AbstractModel {
  private $sessionId;
  private $selector;
  private $token;
  private $userId;
  private $created;
  private $expires;
  
  function __construct($sessionId, $selector, $token, $userId, $created, $expires) {
    $this->sessionId = $sessionId;
    $this->selector = $selector;
    $this->token = $token;
    $this->userId = $userId;
    $this->created = $created;
    $this->expires = $expires;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['sessionId'] = $this->sessionId;
    $dict['selector'] = $this->selector;
    $dict['token'] = $this->token;
    $dict['userId'] = $this->userId;
    $dict['created'] = $this->created;
    $dict['expires'] = $this->expires;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "sessionId";
  }
  
  function getPrimaryKeyValue() {
    return $this->sessionId;
  }
  
  function getId() {
    return $this->sessionId;
  }
  
  function setId($id) {
    $this->sessionId = $id;
  }
  
  function getSelector(){
    return $this->selector;
  }
  
  function setSelector($selector){
    $this->selector = $selector;
  }
  
  function getToken(){
    return $this->token;
  }
  
  function setToken($token){
    $this->token = $token;
  }
  
  function getUserId(){
    return $this->userId;
  }
  
  function setUserId($userId){
    $this->userId = $userId;
  }
  
  function getCreated(){
    return $this->created;
  }
  
  function setCreated($created){
    $this->created = $created;
  }
  
  function getExpires(){
    return $this->expires;
  }
  
  function setExpires($expires){
    $this->expires = $expires;
  }

  const SESSION_ID = "sessionId";
  const SELECTOR = "selector";
  const TOKEN = "token";
  const USER_ID = "userId";
  const CREATED = "created";
  const EXPIRES = "expires";
}
