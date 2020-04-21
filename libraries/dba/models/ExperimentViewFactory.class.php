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

class ExperimentViewFactory extends AbstractModelFactory {
  function getModelName() {
    return "ExperimentView";
  }
  
  function getModelTable() {
    return "ExperimentView";
  }
  
  function isCachable() {
    return false;
  }
  
  function getCacheValidTime() {
    return -1;
  }

  /**
   * @return ExperimentView
   */
  function getNullObject() {
    $o = new ExperimentView(-1, null, null, null, null, null, null, null, null, null, null, null, null, null);
    return $o;
  }

  /**
   * @param string $pk
   * @param array $dict
   * @return ExperimentView
   */
  function createObjectFromDict($pk, $dict) {
    $o = new ExperimentView($dict['experimentId'], $dict['name'], $dict['userId'], $dict['description'], $dict['systemId'], $dict['phases'], $dict['status'], $dict['created'], $dict['projectId'], $dict['postData'], $dict['internalId'], $dict['isArchived'], $dict['resultId'], $dict['projectUserId']);
    return $o;
  }

  /**
   * @param array $options
   * @param bool $single
   * @return ExperimentView|ExperimentView[]
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
      return Util::cast(parent::filter($options, $single), ExperimentView::class);
    }
    $objects = parent::filter($options, $single);
    if($join){
      return $objects;
    }
    $models = [];
    foreach($objects as $object){
      $models[] = Util::cast($object, ExperimentView::class);
    }
    return $models;
  }

  /**
   * @param string $pk
   * @return ExperimentView
   */
  function get($pk) {
    return Util::cast(parent::get($pk), ExperimentView::class);
  }

  /**
   * @param ExperimentView $model
   * @return ExperimentView
   */
  function save($model) {
    return Util::cast(parent::save($model), ExperimentView::class);
  }
}