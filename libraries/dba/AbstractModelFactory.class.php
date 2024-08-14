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

use MassUpdateSet;
use PDO, PDOStatement, PDOException;

/**
 * Abstraction of all ModelFactories.
 * A ModelFactory is used to get all
 * models from Database. It handles the DB calling and caching of objects.
 */
abstract class AbstractModelFactory {
  /**
   * @var PDO
   */
  private $dbh = null;

  /**
   * Return the Models name
   *
   * @return string The name of the model associated with this factory
   */
  abstract function getModelName();

  /**
   * Return the Models associated table
   *
   * This function defines table associated with this model and will be
   * used by the database abstraction to save your model in.
   *
   * @return string The name of the table associated with this factory
   */
  abstract function getModelTable();

  /**
   * Returns wether the associated model is able to be cached or not
   *
   * @return boolean True, if the object might be cached, False if not
   */
  abstract function isCachable();

  /**
   * Returns wether the models valid time on cache.
   *
   * Returns the time in seconds a object might life on the cache.
   * If the model should not be cachable -1 shall be returned
   *
   * @return int valid time in seconds, -1 if model shouldn't be cached
   */
  abstract function getCacheValidTime();

  /**
   * Returns an empty instance of the associated object
   *
   * This empty object is used to get all the object properties for
   * different queries such as the get queries, where no actual object
   * is given
   *
   * @return AbstractModel
   */
  abstract function getNullObject();

  /**
   * This function inits, an objects values from a dict and returns it;
   *
   * This function is used to get objects from a certain type from db resourcebundle_get_error_message
   *
   * @param $pk string primary key
   * @param $dict array dict of values and keys
   * @return AbstractModel An object of the factories type
   */
  abstract function createObjectFromDict($pk, $dict);

  /**
   * Saves the passed model in database, and returns it with the real id
   * in the database.
   *
   * The function saves the passed model in the database and updates the
   * cache, if the model shall be cached. The primary key of this object
   * MUST be -1
   *
   * The Function returns null if the object could not be placed into the
   * database
   * @param $model AbstractModel model to save
   * @return AbstractModel
   */
  public function save($model) {
    $dict = $model->getKeyValueDict();

    $query = "INSERT INTO " . $this->getModelTable();
    $keys = array_keys($dict);
    $vals = array_values($dict);

    $placeHolder = "(";
    $query .= " (";
    for ($i = 0; $i < count($keys); $i++) {
      if ($i != count($keys) - 1) {
        $query = $query . $keys[$i] . ",";
        $placeHolder = $placeHolder . "?,";
      }
      else {
        $query = $query . $keys[$i];
        $placeHolder = $placeHolder . "?";
      }
    }
    $query = $query . ")";
    $placeHolder = $placeHolder . ")";

    $query = $query . " VALUES " . $placeHolder;

    $dbh = $this->getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);

    $id = $dbh->lastInsertId();
    if ($id != 0) {
      $model->setId($id);
      return $model;
    }
    else if ($model->getId() != 0) {
      return $model;
    }
    else {
      return null;
    }
  }

  /**
   * @param $arr array
   * @return Filter[]
   */
  private function getFilters($arr) {
    if (!is_array($arr['filter'])) {
      $arr['filter'] = [$arr['filter']];
    }
    if (isset($arr['filter'])) {
      return $arr['filter'];
    }
    return [];
  }

  /**
   * @param $arr array
   * @return Order[]
   */
  private function getOrders($arr) {
    if (!is_array($arr['order'])) {
      $arr['order'] = [$arr['order']];
    }
    if (isset($arr['order'])) {
      return $arr['order'];
    }
    return [];
  }

  /**
   * @param $arr array
   * @return Group[]
   */
  private function getGroups($arr) {
    if (!is_array($arr['group'])) {
      $arr['group'] = [$arr['group']];
    }
    if (isset($arr['group'])) {
      return $arr['group'];
    }
    return [];
  }

  /**
   * @param $arr array
   * @return Join[]
   */
  private function getJoins($arr) {
    if (!is_array($arr['join'])) {
      $arr['join'] = [$arr['join']];
    }
    if (isset($arr['join'])) {
      return $arr['join'];
    }
    return [];
  }

  /**
   * Updates the database entry for the model
   *
   * This function updates the database entry for the given model
   * based on it's primary key.
   * Returns the return of PDO::execute()
   * @param $model AbstractModel model to update
   * @return PDOStatement
   */
  public function update($model) {
    $dict = $model->getKeyValueDict();

    $query = "UPDATE " . $this->getModelTable() . " SET ";

    $keys = array_keys($dict);
    $values = [];

    for ($i = 0; $i < count($keys); $i++) {
      if ($i != count($keys) - 1) {
        $query = $query . $keys[$i] . "=?,";
        array_push($values, $dict[$keys[$i]]);
      }
      else {
        $query = $query . $keys[$i] . "=?";
        array_push($values, $dict[$keys[$i]]);
      }
    }

    $query = $query . " WHERE " . $model->getPrimaryKey() . "=?";
    array_push($values, $model->getPrimaryKeyValue());

    $stmt = $this->getDB()->prepare($query);
    $stmt->execute($values);
    return $stmt;
  }

  /**
   * @param $models AbstractModel[]
   * @return bool|PDOStatement
   */
  public function massSave($models) {
    if (sizeof($models) == 0) {
      return false;
    }
    $dict = $models[0]->getKeyValueDict();

    $query = "INSERT INTO " . $this->getModelTable();
    $query .= "( ";
    $keys = array_keys($dict);

    $placeHolder = "(";
    for ($i = 0; $i < count($keys); $i++) {
      if ($i != count($keys) - 1) {
        $query = $query . $keys[$i] . ",";
        $placeHolder = $placeHolder . "?,";
      }
      else {
        $query = $query . $keys[$i];
        $placeHolder = $placeHolder . "?";
      }
    }
    $query = $query . ")";
    $placeHolder = $placeHolder . ")";

    $query = $query . " VALUES ";
    $vals = [];
    for ($x = 0; $x < sizeof($models); $x++) {
      $query .= $placeHolder;
      if ($x < sizeof($models) - 1) {
        $query .= ", ";
      }
      if ($models[$x]->getId() == 0) {
        $models[$x]->setId(null);
      }
      $dict = $models[$x]->getKeyValueDict();
      foreach (array_values($dict) as $val) {
        $vals[] = $val;
      }
    }

    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);
    return $stmt;
  }

  public function sumFilter($options, $sumColumn) {
    $query = "SELECT SUM($sumColumn) AS sum ";
    $query = $query . " FROM " . $this->getModelTable();

    $vals = [];

    if (array_key_exists("filter", $options)) {
      $query .= $this->applyFilters($vals, $options['filter']);
    }

    if (!array_key_exists("order", $options)) {
      // Add a asc order on the primary keys as a standard
      $oF = new OrderFilter($this->getNullObject()->getPrimaryKey(), "ASC");
      $orderOptions = [$oF];
      $options['order'] = $orderOptions;
    }
    if (count($options['order']) != 0) {
      $query .= $this->applyOrder($this->getOrders($options));
    }

    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['sum'];
  }

  public function countFilter($options) {
    $query = "SELECT COUNT(*) AS count ";
    $query = $query . " FROM " . $this->getModelTable();

    $vals = [];

    if (array_key_exists("filter", $options)) {
      $query .= $this->applyFilters($vals, $options['filter']);
    }

    if (!array_key_exists("order", $options)) {
      // Add a asc order on the primary keys as a standard
      $oF = new OrderFilter($this->getNullObject()->getPrimaryKey(), "ASC");
      $orderOptions = [$oF];
      $options['order'] = $orderOptions;
    }
    if (count($options['order']) != 0) {
      $query .= $this->applyOrder($options['order']);
    }

    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['count'];
  }

  /**
   * Get's a model from it's primary key.
   *
   * This function returns the model with the given primary key or null.
   * If the model is specified to be non-cached, this function will call
   * the getFromDB() function and return it's result. It's therefor recommended
   * to use this function
   *
   * @param $pk string primary key
   * @return AbstractModel the with pk associated model or Null
   *
   */
  public function get($pk) {
    if (!$this->isCachable()) {
      return $this->getFromDB($pk);
    }
    else {
      // TODO: Implement caching
      return $this->getFromDB($pk);
    }
  }

  /**
   * Get's a model by it's primary key directly going to the database
   *
   * This function returns the model with the given primary key or null.
   * This function will go to the database directly neglecting the cache.
   * If the model is set to be cachable, the cache will also be updated
   *
   * @param $pk string primary key
   * @return AbstractModel the with pk associated model or Null
   */
  public function getFromDB($pk) {
    $query = "SELECT ";

    $keys = array_keys($this->getNullObject()->getKeyValueDict());

    for ($i = 0; $i < count($keys); $i++) {
      if ($i != count($keys) - 1) {
        $query = $query . $keys[$i] . ",";
      }
      else {
        $query = $query . $keys[$i];
      }
    }
    $query = $query . " FROM " . $this->getModelTable();

    $query = $query . " WHERE " . $this->getNullObject()->getPrimaryKey() . "=?";

    $stmt = $this->getDB()->prepare($query);
    $stmt->execute([$pk]);
    if ($stmt->rowCount() != 0) {
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $this->createObjectFromDict($pk, $row);
    }
    else {
      return null;
    }
  }

  /**
   * Filters the database for a set of options
   *
   * This function filters the dataset (think of it as a select) for a set
   * of options.
   * The structure of the options array is a dictionary with the following
   * structure
   *
   * $options = array();
   * $options['filter'] is an array of QueryFilter options
   * $options['order'] is an array of OrderFilter options
   * $options['join'] is an array of JoinFilter options
   *
   * @param $options array containing option settings
   * @return AbstractModel[]|AbstractModel Returns a list of matching objects or Null
   */
  private function filterWithJoin($options) {
    $joins = $this->getJoins($options);
    if (!is_array($joins)) {
      $joins = [$joins];
    }
    $keys = array_keys($this->getNullObject()->getKeyValueDict());
    $prefixedKeys = [];
    $factories = [$this];
    foreach ($keys as $key) {
      $prefixedKeys[] = $this->getModelTable() . "." . $key;
      $tables[] = $this->getModelTable();
    }
    $query = "SELECT " . Util::createPrefixedString($this->getModelTable(), $this->getNullObject()->getKeyValueDict());
    foreach ($joins as $join) {
      $joinFactory = $join->getOtherFactory();
      $factories[] = $joinFactory;
      $query .= ", " . Util::createPrefixedString($joinFactory->getModelTable(), $joinFactory->getNullObject()->getKeyValueDict());
    }
    $query .= " FROM " . $this->getModelTable();

    foreach ($joins as $join) {
      $joinFactory = $join->getOtherFactory();
      $localFactory = $this;
      if ($join->getOverrideOwnFactory() != null) {
        $localFactory = $join->getOverrideOwnFactory();
      }
      $match1 = $join->getMatch1();
      $match2 = $join->getMatch2();
      $query .= " INNER JOIN " . $joinFactory->getModelTable() . " ON " . $localFactory->getModelTable() . "." . $match1 . "=" . $joinFactory->getModelTable() . "." . $match2 . " ";
    }

    // Apply all normal filter to this query
    $vals = [];
    if (array_key_exists("filter", $options)) {
      $query .= $this->applyFilters($vals, $options['filter']);
    }

    if (array_key_exists("group", $options)) {
      $query .= $this->applyGroups($this->getGroups($options));
    }

    // Apply order filter
    if (!array_key_exists("order", $options)) {
      // Add a asc order on the primary keys as a standard
      $oF = new OrderFilter($this->getNullObject()->getPrimaryKey(), "ASC");
      $orderOptions = [$oF];
      $options['order'] = $orderOptions;
    }
    $query .= $this->applyOrder($options['order']);


    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);

    $res = [];
    $values = [];
    foreach ($factories as $factory) {
      $res[$factory->getModelTable()] = [];
      $values[$factory->getModelTable()] = [];
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      foreach ($row as $k => $v) {
        foreach ($factories as $factory) {
          if (Util::startsWith($k, $factory->getModelTable())) {
            $column = str_replace($factory->getModelTable() . ".", "", $k);
            $values[$factory->getModelTable()][$column] = $v;
          }
        }
      }

      foreach ($factories as $factory) {
        $model = $factory->createObjectFromDict($values[$factory->getModelTable()][$factory->getNullObject()->getPrimaryKey()], $values[$factory->getModelTable()]);
        array_push($res[$factory->getModelTable()], $model);
      }
    }

    return $res;
  }

    public function idFilter($options) {
        $key = $this->getNullObject()->getPrimaryKey();
        $query = "SELECT " . $key . " FROM " . $this->getModelTable();

        $vals = [];
        if (array_key_exists("filter", $options)) {
            $query .= $this->applyFilters($vals, $options['filter']);
        }
        if (array_key_exists("group", $options)) {
            $query .= $this->applyGroups($this->getGroups($options));
        }
        if (!array_key_exists("order", $options)) {
            // Add a asc order on the primary keys as a standard
            $oF = new OrderFilter($this->getNullObject()->getPrimaryKey(), "ASC");
            $orderOptions = [$oF];
            $options['order'] = $orderOptions;
        }
        $query .= $this->applyOrder($options['order']);

        $dbh = self::getDB();
        $stmt = $dbh->prepare($query);
        $stmt->execute($vals);

        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            array_push($ids, $row[$key]);
        }
        return $ids;
    }

  public function filter($options, $single = false) {
    // Check if we need to join and if so pass on to internal Function
    if (array_key_exists('join', $options)) {
      return $this->filterWithJoin($options);
    }

    $keys = array_keys($this->getNullObject()->getKeyValueDict());
    $query = "SELECT " . implode(", ", $keys) . " FROM " . $this->getModelTable();
    $vals = [];

    if (array_key_exists("filter", $options)) {
      $query .= $this->applyFilters($vals, $options['filter']);
    }

    if (array_key_exists("group", $options)) {
      $query .= $this->applyGroups($this->getGroups($options));
    }

    if (!array_key_exists("order", $options)) {
      // Add a asc order on the primary keys as a standard
      $oF = new OrderFilter($this->getNullObject()->getPrimaryKey(), "ASC");
      $orderOptions = [$oF];
      $options['order'] = $orderOptions;
    }
    $query .= $this->applyOrder($options['order']);

    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);

    $objects = [];

    // Loop over all entries and create an object from dict for each
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $pkName = $this->getNullObject()->getPrimaryKey();

      $pk = $row[$pkName];
      $model = $this->createObjectFromDict($pk, $row);
      array_push($objects, $model);
    }

    if ($single) {
      if (sizeof($objects) == 0) {
        return null;
      }
      else {
        return $objects[0];
      }
    }

    return $objects;
  }

  private function applyFilters(&$vals, $filters) {
    $parts = [];
    if (!is_array($filters)) {
      $filters = [$filters];
    }

    foreach ($filters as $filter) {
      $parts[] = $filter->getQueryString();
      if (!$filter->getHasValue()) {
        continue;
      }
      $v = $filter->getValue();
      if (is_array($v)) {
        foreach ($v as $val) {
          array_push($vals, $val);
        }
      }
      else {
        array_push($vals, $v);
      }
    }
    return " WHERE " . implode(" AND ", $parts);
  }

  private function applyOrder($orders) {
    $orderQueries = [];
    if (!is_array($orders)) {
      $orders = [$orders];
    }
    foreach ($orders as $order) {
      $orderQueries[] = $order->getQueryString($this->getModelTable());
    }
    return " ORDER BY " . implode(", ", $orderQueries);
  }

  private function applyGroups($groups) {
    $groupsQueries = [];
    if (!is_array($groups)) {
      $groups = [$groups];
    }
    foreach ($groups as $group) {
      $groupsQueries[] = $group->getQueryString($this->getModelTable());
    }
    return " GROUP BY " . implode(", ", $groupsQueries);
  }

  /**
   * Deletes the given model
   *
   * This function deletes the given and also cleans the cache from it.
   * It returns the return of the execute query.
   * @param $model AbstractModel
   * @return bool
   */
  public function delete($model) {
    if ($model != null) {
      $query = "DELETE FROM " . $this->getModelTable() . " WHERE " . $model->getPrimaryKey() . " = ?";
      $stmt = $this->getDB()->prepare($query);
      return $stmt->execute([$model->getPrimaryKeyValue()]);
    }
    return false;
  }

  /**
   * @param $options array
   * @return PDOStatement
   */
  public function massDeletion($options) {
    $query = "DELETE FROM " . $this->getModelTable();

    $vals = [];

    if (array_key_exists("filter", $options)) {
      $query .= $this->applyFilters($vals, $this->getFilters($options));
    }

    $dbh = $this->getDB();
    $stmt = $dbh->prepare($query);
    $stmt->execute($vals);
    return $stmt;
  }

  /**
   * @param $matchingColumn
   * @param $updateColumn
   * @param $updates MassUpdateSet[]
   * @return null
   */
  public function massSingleUpdate($matchingColumn, $updateColumn, $updates) {
    $query = "UPDATE " . $this->getModelName();

    if (sizeof($updates) == 0) {
      return null;
    }
    $query .= " SET `$updateColumn` = ( CASE ";

    $vals = [];

    foreach ($updates as $update) {
      $query .= $update->getMassQuery($matchingColumn);
      array_push($vals, $update->getMatchValue());
      array_push($vals, $update->getUpdateValue());
    }

    $matchingArr = [];
    foreach ($updates as $update) {
      array_push($vals, $update->getMatchValue());
      $matchingArr[] = "?";
    }

    $query .= "END) WHERE $matchingColumn IN (" . implode(",", $matchingArr) . ")";
    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    return $stmt->execute($vals);
  }

  public function massUpdate($options) {
    $query = "UPDATE " . $this->getModelTable();

    $vals = [];

    if (array_key_exists("update", $options)) {
      $query = $query . " SET ";


      $updateOptions = $options['update'];
      if (!is_array($updateOptions)) {
        $updateOptions = [$updateOptions];
      }
      $vals = [];

      for ($i = 0; $i < count($updateOptions); $i++) {
        $option = $updateOptions[$i];
        array_push($vals, $option->getValue());

        if ($i != count($updateOptions) - 1) {
          $query = $query . $option->getQuery() . " , ";
        }
        else {
          $query = $query . $option->getQuery();
        }
      }
    }

    if (array_key_exists("filter", $options)) {
      $query .= $this->applyFilters($vals, $options['filter']);
    }

    $dbh = self::getDB();
    $stmt = $dbh->prepare($query);
    return $stmt->execute($vals);
  }

  /**
   * Returns the DB connection if possible
   * @param bool $test
   * @return PDO
   */
  public function getDB($test = false) {
    if (!$test) {
      $dsn = 'mysql:dbname=' . DBA_DB . ";host=" . DBA_SERVER . ";port=" . DBA_PORT;
      $user = DBA_USER;
      $password = DBA_PASS;
    }
    else {
      global $CONN;
      $dsn = 'mysql:dbname=' . $CONN['db'] . ";host=" . $CONN['server'] . ";port=" . $CONN['port'];
      $user = $CONN['user'];
      $password = $CONN['pass'];
    }

    if ($this->dbh !== null) {
      return $this->dbh;
    }

    try {
      $this->dbh = new PDO($dsn, $user, $password);
      $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      return $this->dbh;
    }
    catch (PDOException $e) {
      if ($test) {
        return null;
      }
      die("Fatal Error! Database connection failed. Message: " . $e->getMessage());
    }
  }

    /**
     * Creates two queries to identify the right value to increment
     * $stmt1 will search for the index of the array's entry
     * $incrementQuery will look in the specified :index of the array and replace the value of the key
     * @param $jobId
     * @param $pattern
     * @param $amount
     * @return void
     */
    public function incrementJobCountAtomically($jobId, $resultCollection)
    {
        $json = Factory::getJobFactory()->get($jobId)->getLogalyzerResults();
        file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', print_r($json, true), FILE_APPEND);
        $dbh = self::getDB();
        #$dbh->beginTransaction();
        try {
            foreach ($resultCollection as $pattern) {
                $stmt1 = $dbh->prepare("SELECT 
                    JSON_UNQUOTE(
                    REPLACE(JSON_EXTRACT(
                    JSON_SEARCH(logalyzerResults, 'one', :pattern), '$[0]'), '].pattern', '].count'))
                    INTO @index
                    FROM Job
                    WHERE jobId = :jobId;");
                $stmt1->bindParam(':pattern', $pattern['pattern'], PDO::PARAM_STR);
                $stmt1->bindParam(':jobId', $jobId, PDO::PARAM_INT);

                file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "\nExecuting Query 1: " . var_export($stmt1->queryString, true) . "\n", FILE_APPEND);
                file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "With Parameters: pattern=" . $pattern['pattern'] . ", jobId=" . $jobId . "\n", FILE_APPEND);

                if (!$stmt1->execute()) {
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "\nError in execute() for Query 1\n", FILE_APPEND);
                }
                $helper = $dbh->query("SELECT @index");
                $index = $helper->fetch(PDO::FETCH_ASSOC);
                file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "Index is ".$index['@index']."\n", FILE_APPEND);

                $getValueQuery = "SELECT * WHERE jobId";


                $stmt2 = $dbh->prepare("UPDATE Job 
                    SET logalyzerResults = JSON_SET(
                    logalyzerResults,
                    JSON_QUOTE(:index),
                    CAST(CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(logalyzerResults, JSON_QUOTE(:index))
                    ) AS UNSIGNED) + :amount AS CHAR))
                    WHERE jobId = :jobId AND JSON_SEARCH(logalyzerResults, 'one', :pattern) is not null;");
                if ($stmt2 !== false) {
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "\nExecuting Query 2: " . var_export($stmt2->queryString, true) . "\n", FILE_APPEND);
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "With Parameters: index=" . $index['@index'] . ", amount=" . $pattern['count'] . ", jobId=" . $jobId . ", pattern=" . $pattern['pattern'] . "\n", FILE_APPEND);
                    } else {
                    file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "\nError in prepare() for Query 2\n", FILE_APPEND);
                    }
                $stmt2->bindParam(':index', $index['@index'], PDO::PARAM_STR);
                $stmt2->bindParam(':pattern', $pattern['pattern'], PDO::PARAM_STR);
                $stmt2->bindParam(':amount', $pattern['count'], PDO::PARAM_INT);
                $stmt2->bindParam(':jobId', $jobId, PDO::PARAM_INT);
                if (!$stmt2->execute()) {
                        file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', "\nError in execute()\n", FILE_APPEND);
                }
            }
        }
           catch (PDOException $e) {
               $dbh->rollback();
               file_put_contents(UPLOADED_DATA_PATH . 'log/' . $jobId . '.log', $e->getMessage(), FILE_APPEND);
           }
      }

    /**
     * currently not in use
     * @param $jobId
     * @param $logLevel
     * @param $pattern
     * @param $regex
     * @param $type
     * @param $hash
     * @param $amount
     * @return bool
     */
    public function logalyzerAppendNewResult($jobId, $logLevel, $pattern, $regex, $type, $hash, $amount=0) {
      $dbh = self::getDB();
      $dbh->beginTransaction();
      $lockQuery = "SELECT logalyzerResults FROM Job WHERE jobId=? FOR UPDATE";
      $stmt = $dbh->prepare($lockQuery);
      $stmt->execute([$jobId]);

      $query = "UPDATE Job SET logalyzerResults = JSON_ARRAY_APPEND(logalyzerResults, '$.results', JSON_OBJECT('logLevel', ".$logLevel.", 'pattern', '".$pattern."', 'regex', '".$regex."', 'type', '".$type."', 'count', ".$amount.")) WHERE jobId=?";
      $stmt2 = $dbh->prepare($query);
      $result = $stmt2->execute([$jobId]);

      $hashUpdate = "UPDATE Job SET logalyzerResults = JSON_SET(logalyzerResults, '$.hash', ?) WHERE jobId=?";
      $stmt3 = $dbh->prepare($hashUpdate);
      $result = $stmt3->execute([$hash, $jobId]);
      $dbh->commit();
      return $result;
    }
    public function logalyzerUpdateHash($jobId, $hash) {
        $dbh = self::getDB();
        $dbh->beginTransaction();
        $lockQuery = "SELECT logalyzerResults FROM Job WHERE jobId = ? FOR UPDATE";
        $stmt = $dbh->prepare($lockQuery);
        $stmt->execute([$jobId]);

        $hashQuery = "UPDATE Job SET logalyzerResults = JSON_SET(logalyzerResults, '$.hash', ?) WHERE jobId=?";


        $stmt2 = $dbh->prepare($hashQuery);
        $result = $stmt2->execute([$hash, $jobId]);
        $dbh->commit();
        return $result;
    }
    /**
     * Goes over all results and aggregates the counts for keywords of the same logLevel
     * Returns the accumulated number of all patterns with the specified $logLevel
     * @param $job
     * @param $logLevel
     * @param $type
     * @return int
     */
  public function getJobCountForLogLevel($job, $logLevel, $type) {
      if($job->getLogalyzerResults() != null) {
          $json = json_decode($job->getLogalyzerResults(), true);
          $resultArray = $json['result'];
          $count = 0;
          foreach ($resultArray as $element) {
              if ($type === 'negative' && $element['type'] === 'negative' && $element['logLevel'] === $logLevel) {
                  $count += $element['count'];
              }
          }
          return $count;
      }
      else {
          return null;
      }
    }
    public function getJobHash($job) {
        $json = $job->getLogalyzerResults();
        if ($json != null) {
            $json = json_decode($json, true);
            //echo "JobHash: " . $json['jobHash'] . " returned.\n";
            return $json['hash'];
        }
        else {
            return "";
        }
    }

    /**
     * Returns true if all positive patterns are present, or if no positive pattern exists for this system
     * @param $job
     * @return bool
     */
    public function checkAllPositiveJobPatterns($job) {
        $json = $job->getLogalyzerResults();
        if ($json != null) {
            $data = json_decode($job->getLogalyzerResults(), true);
            foreach ($data['pattern'] as $element) {
                if($element['type'] === 'positive' && $element['count'] <= 0) {
                    return false;
                }
            }
            return true;
        }
        return true;
    }
}

