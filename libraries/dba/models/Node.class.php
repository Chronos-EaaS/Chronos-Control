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

class Node extends AbstractModel {
  private $nodeId;
  private $environment;
  private $version;
  private $currentJob;
  private $cpu;
  private $memoryUsed;
  private $memoryTotal;
  private $hostname;
  private $ip;
  private $os;
  private $healthStatus;
  private $lastUpdate;
  
  function __construct($nodeId, $environment, $version, $currentJob, $cpu, $memoryUsed, $memoryTotal, $hostname, $ip, $os, $healthStatus, $lastUpdate) {
    $this->nodeId = $nodeId;
    $this->environment = $environment;
    $this->version = $version;
    $this->currentJob = $currentJob;
    $this->cpu = $cpu;
    $this->memoryUsed = $memoryUsed;
    $this->memoryTotal = $memoryTotal;
    $this->hostname = $hostname;
    $this->ip = $ip;
    $this->os = $os;
    $this->healthStatus = $healthStatus;
    $this->lastUpdate = $lastUpdate;
  }
  
  function getKeyValueDict() {
    $dict = [];
    $dict['nodeId'] = $this->nodeId;
    $dict['environment'] = $this->environment;
    $dict['version'] = $this->version;
    $dict['currentJob'] = $this->currentJob;
    $dict['cpu'] = $this->cpu;
    $dict['memoryUsed'] = $this->memoryUsed;
    $dict['memoryTotal'] = $this->memoryTotal;
    $dict['hostname'] = $this->hostname;
    $dict['ip'] = $this->ip;
    $dict['os'] = $this->os;
    $dict['healthStatus'] = $this->healthStatus;
    $dict['lastUpdate'] = $this->lastUpdate;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "nodeId";
  }
  
  function getPrimaryKeyValue() {
    return $this->nodeId;
  }
  
  function getId() {
    return $this->nodeId;
  }
  
  function setId($id) {
    $this->nodeId = $id;
  }
  
  function getEnvironment(){
    return $this->environment;
  }
  
  function setEnvironment($environment){
    $this->environment = $environment;
  }
  
  function getVersion(){
    return $this->version;
  }
  
  function setVersion($version){
    $this->version = $version;
  }
  
  function getCurrentJob(){
    return $this->currentJob;
  }
  
  function setCurrentJob($currentJob){
    $this->currentJob = $currentJob;
  }
  
  function getCpu(){
    return $this->cpu;
  }
  
  function setCpu($cpu){
    $this->cpu = $cpu;
  }
  
  function getMemoryUsed(){
    return $this->memoryUsed;
  }
  
  function setMemoryUsed($memoryUsed){
    $this->memoryUsed = $memoryUsed;
  }
  
  function getMemoryTotal(){
    return $this->memoryTotal;
  }
  
  function setMemoryTotal($memoryTotal){
    $this->memoryTotal = $memoryTotal;
  }
  
  function getHostname(){
    return $this->hostname;
  }
  
  function setHostname($hostname){
    $this->hostname = $hostname;
  }
  
  function getIp(){
    return $this->ip;
  }
  
  function setIp($ip){
    $this->ip = $ip;
  }
  
  function getOs(){
    return $this->os;
  }
  
  function setOs($os){
    $this->os = $os;
  }
  
  function getHealthStatus(){
    return $this->healthStatus;
  }
  
  function setHealthStatus($healthStatus){
    $this->healthStatus = $healthStatus;
  }
  
  function getLastUpdate(){
    return $this->lastUpdate;
  }
  
  function setLastUpdate($lastUpdate){
    $this->lastUpdate = $lastUpdate;
  }

  const NODE_ID = "nodeId";
  const ENVIRONMENT = "environment";
  const VERSION = "version";
  const CURRENT_JOB = "currentJob";
  const CPU = "cpu";
  const MEMORY_USED = "memoryUsed";
  const MEMORY_TOTAL = "memoryTotal";
  const HOSTNAME = "hostname";
  const IP = "ip";
  const OS = "os";
  const HEALTH_STATUS = "healthStatus";
  const LAST_UPDATE = "lastUpdate";
}
