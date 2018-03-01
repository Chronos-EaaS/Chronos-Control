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

class Event extends AbstractModel {
  private $eventId;
  private $title;
  private $time;
  private $eventText;
  private $eventType;
  private $relatedId;
  private $userId;
  
  function __construct($eventId, $title, $time, $eventText, $eventType, $relatedId, $userId) {
    $this->eventId = $eventId;
    $this->title = $title;
    $this->time = $time;
    $this->eventText = $eventText;
    $this->eventType = $eventType;
    $this->relatedId = $relatedId;
    $this->userId = $userId;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['eventId'] = $this->eventId;
    $dict['title'] = $this->title;
    $dict['time'] = $this->time;
    $dict['eventText'] = $this->eventText;
    $dict['eventType'] = $this->eventType;
    $dict['relatedId'] = $this->relatedId;
    $dict['userId'] = $this->userId;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "eventId";
  }
  
  function getPrimaryKeyValue() {
    return $this->eventId;
  }
  
  function getId() {
    return $this->eventId;
  }
  
  function setId($id) {
    $this->eventId = $id;
  }
  
  function getTitle(){
    return $this->title;
  }
  
  function setTitle($title){
    $this->title = $title;
  }
  
  function getTime(){
    return $this->time;
  }
  
  function setTime($time){
    $this->time = $time;
  }
  
  function getEventText(){
    return $this->eventText;
  }
  
  function setEventText($eventText){
    $this->eventText = $eventText;
  }
  
  function getEventType(){
    return $this->eventType;
  }
  
  function setEventType($eventType){
    $this->eventType = $eventType;
  }
  
  function getRelatedId(){
    return $this->relatedId;
  }
  
  function setRelatedId($relatedId){
    $this->relatedId = $relatedId;
  }
  
  function getUserId(){
    return $this->userId;
  }
  
  function setUserId($userId){
    $this->userId = $userId;
  }

  const EVENT_ID = "eventId";
  const TITLE = "title";
  const TIME = "time";
  const EVENT_TEXT = "eventText";
  const EVENT_TYPE = "eventType";
  const RELATED_ID = "relatedId";
  const USER_ID = "userId";
}
