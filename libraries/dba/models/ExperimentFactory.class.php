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

class ExperimentFactory extends AbstractModelFactory {
  function getModelName() {
    return "Experiment";
  }
  
  function getModelTable() {
    return "Experiment";
  }
  
  function isCachable() {
    return false;
  }
  
  function getCacheValidTime() {
    return -1;
  }

  /**
   * @return Experiment
   */
  function getNullObject() {
    $o = new Experiment(-1, null, null, null, null, null, null, null, null, null, null, null);
    return $o;
  }

  /**
   * @param string $pk
   * @param array $dict
   * @return Experiment
   */
  function createObjectFromDict($pk, $dict) {
    $o = new Experiment($dict['experimentId'], $dict['name'], $dict['userId'], $dict['description'], $dict['type'], $dict['systemId'], $dict['phases'], $dict['status'], $dict['created'], $dict['projectId'], $dict['postData'], $dict['internalId']);
    return $o;
  }

  /**
   * @param array $options
   * @param bool $single
   * @return Experiment|Experiment[]
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
      return Util::cast(parent::filter($options, $single), Experiment::class);
    }
    $objects = parent::filter($options, $single);
    if($join){
      return $objects;
    }
    $models = array();
    foreach($objects as $object){
      $models[] = Util::cast($object, Experiment::class);
    }
    return $models;
  }

  /**
   * @param string $pk
   * @return Experiment
   */
  function get($pk) {
    return Util::cast(parent::get($pk), Experiment::class);
  }

  /**
   * @param Experiment $model
   * @return Experiment
   */
  function save($model) {
    return Util::cast(parent::save($model), Experiment::class);
  }
}