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

class User extends AbstractModel {
  private $userId;
  private $username;
  private $password;
  private $email;
  private $lastname;
  private $firstname;
  private $gender;
  private $role;
  private $alive;
  private $activated;
  private $created;
  private $lastEdit;
  private $lastLogin;
  
  function __construct($userId, $username, $password, $email, $lastname, $firstname, $gender, $role, $alive, $activated, $created, $lastEdit, $lastLogin) {
    $this->userId = $userId;
    $this->username = $username;
    $this->password = $password;
    $this->email = $email;
    $this->lastname = $lastname;
    $this->firstname = $firstname;
    $this->gender = $gender;
    $this->role = $role;
    $this->alive = $alive;
    $this->activated = $activated;
    $this->created = $created;
    $this->lastEdit = $lastEdit;
    $this->lastLogin = $lastLogin;
  }
  
  function getKeyValueDict() {
    $dict = array();
    $dict['userId'] = $this->userId;
    $dict['username'] = $this->username;
    $dict['password'] = $this->password;
    $dict['email'] = $this->email;
    $dict['lastname'] = $this->lastname;
    $dict['firstname'] = $this->firstname;
    $dict['gender'] = $this->gender;
    $dict['role'] = $this->role;
    $dict['alive'] = $this->alive;
    $dict['activated'] = $this->activated;
    $dict['created'] = $this->created;
    $dict['lastEdit'] = $this->lastEdit;
    $dict['lastLogin'] = $this->lastLogin;
    
    return $dict;
  }
  
  function getPrimaryKey() {
    return "userId";
  }
  
  function getPrimaryKeyValue() {
    return $this->userId;
  }
  
  function getId() {
    return $this->userId;
  }
  
  function setId($id) {
    $this->userId = $id;
  }
  
  function getUsername(){
    return $this->username;
  }
  
  function setUsername($username){
    $this->username = $username;
  }
  
  function getPassword(){
    return $this->password;
  }
  
  function setPassword($password){
    $this->password = $password;
  }
  
  function getEmail(){
    return $this->email;
  }
  
  function setEmail($email){
    $this->email = $email;
  }
  
  function getLastname(){
    return $this->lastname;
  }
  
  function setLastname($lastname){
    $this->lastname = $lastname;
  }
  
  function getFirstname(){
    return $this->firstname;
  }
  
  function setFirstname($firstname){
    $this->firstname = $firstname;
  }
  
  function getGender(){
    return $this->gender;
  }
  
  function setGender($gender){
    $this->gender = $gender;
  }
  
  function getRole(){
    return $this->role;
  }
  
  function setRole($role){
    $this->role = $role;
  }
  
  function getAlive(){
    return $this->alive;
  }
  
  function setAlive($alive){
    $this->alive = $alive;
  }
  
  function getActivated(){
    return $this->activated;
  }
  
  function setActivated($activated){
    $this->activated = $activated;
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
  
  function getLastLogin(){
    return $this->lastLogin;
  }
  
  function setLastLogin($lastLogin){
    $this->lastLogin = $lastLogin;
  }

  const USER_ID = "userId";
  const USERNAME = "username";
  const PASSWORD = "password";
  const EMAIL = "email";
  const LASTNAME = "lastname";
  const FIRSTNAME = "firstname";
  const GENDER = "gender";
  const ROLE = "role";
  const ALIVE = "alive";
  const ACTIVATED = "activated";
  const CREATED = "created";
  const LAST_EDIT = "lastEdit";
  const LAST_LOGIN = "lastLogin";
}
