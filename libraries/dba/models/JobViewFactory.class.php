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

class JobViewFactory extends AbstractModelFactory {
  function getModelName() {
    return "JobView";
  }
  
  function getModelTable() {
    return "JobView";
  }
  
  function isCachable() {
    return false;
  }
  
  function getCacheValidTime() {
    return -1;
  }

  /**
   * @return JobView
   */
  function getNullObject() {
    $o = new JobView(-1, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null);
    return $o;
  }

  /**
   * @param string $pk
   * @param array $dict
   * @return JobView
   */
  function createObjectFromDict($pk, $dict) {
    $o = new JobView($dict['jobId'], $dict['userId'], $dict['description'], $dict['systemId'], $dict['environment'], $dict['phases'], $dict['configuration'], $dict['status'], $dict['progress'], $dict['result'], $dict['created'], $dict['started'], $dict['finished'], $dict['evaluationId'], $dict['internalId'], $dict['configurationIdentifier'], $dict['projectUserId']);
    return $o;
  }

  /**
   * @param array $options
   * @param bool $single
   * @return JobView|JobView[]
   */
  function filter($options, $single = false) {
    $join = false;
    if (array_key_exists('join', $options)) {
      $join = true;
    }
    if($single){
      if($join){
        return parent::filter($options, $single);
      }
      return Util::cast(parent::filter($options, $single), JobView::class);
    }
    $objects = parent::filter($options, $single);
    if($join){
      return $objects;
    }
    $models = array();
    foreach($objects as $object){
      $models[] = Util::cast($object, JobView::class);
    }
    return $models;
  }

  /**
   * @param string $pk
   * @return JobView
   */
  function get($pk) {
    return Util::cast(parent::get($pk), JobView::class);
  }

  /**
   * @param JobView $model
   * @return JobView
   */
  function save($model) {
    return Util::cast(parent::save($model), JobView::class);
  }
}