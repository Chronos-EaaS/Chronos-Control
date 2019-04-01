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

class Factory {
  private static $systemFactory = null;
  private static $projectFactory = null;
  private static $experimentFactory = null;
  private static $evaluationFactory = null;
  private static $jobFactory = null;
  private static $resultFactory = null;
  private static $eventFactory = null;
  private static $userFactory = null;
  private static $settingFactory = null;
  private static $sessionFactory = null;
  private static $projectUserFactory = null;

  public static function getSystemFactory() {
    if (self::$systemFactory == null) {
      $f = new SystemFactory();
      self::$systemFactory = $f;
      return $f;
    } else {
      return self::$systemFactory;
    }
  }
  
  public static function getProjectFactory() {
    if (self::$projectFactory == null) {
      $f = new ProjectFactory();
      self::$projectFactory = $f;
      return $f;
    } else {
      return self::$projectFactory;
    }
  }
  
  public static function getExperimentFactory() {
    if (self::$experimentFactory == null) {
      $f = new ExperimentFactory();
      self::$experimentFactory = $f;
      return $f;
    } else {
      return self::$experimentFactory;
    }
  }
  
  public static function getEvaluationFactory() {
    if (self::$evaluationFactory == null) {
      $f = new EvaluationFactory();
      self::$evaluationFactory = $f;
      return $f;
    } else {
      return self::$evaluationFactory;
    }
  }
  
  public static function getJobFactory() {
    if (self::$jobFactory == null) {
      $f = new JobFactory();
      self::$jobFactory = $f;
      return $f;
    } else {
      return self::$jobFactory;
    }
  }
  
  public static function getResultFactory() {
    if (self::$resultFactory == null) {
      $f = new ResultFactory();
      self::$resultFactory = $f;
      return $f;
    } else {
      return self::$resultFactory;
    }
  }
  
  public static function getEventFactory() {
    if (self::$eventFactory == null) {
      $f = new EventFactory();
      self::$eventFactory = $f;
      return $f;
    } else {
      return self::$eventFactory;
    }
  }
  
  public static function getUserFactory() {
    if (self::$userFactory == null) {
      $f = new UserFactory();
      self::$userFactory = $f;
      return $f;
    } else {
      return self::$userFactory;
    }
  }
  
  public static function getSettingFactory() {
    if (self::$settingFactory == null) {
      $f = new SettingFactory();
      self::$settingFactory = $f;
      return $f;
    } else {
      return self::$settingFactory;
    }
  }
  
  public static function getSessionFactory() {
    if (self::$sessionFactory == null) {
      $f = new SessionFactory();
      self::$sessionFactory = $f;
      return $f;
    } else {
      return self::$sessionFactory;
    }
  }
  
  public static function getProjectUserFactory() {
    if (self::$projectUserFactory == null) {
      $f = new ProjectUserFactory();
      self::$projectUserFactory = $f;
      return $f;
    } else {
      return self::$projectUserFactory;
    }
  }

  const FILTER = "filter";
  const JOIN = "join";
  const ORDER = "order";
  const UPDATE = "update";
  const GROUP = "group";
}
