<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Sein Coray
Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

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

$CONF = [];

// entities
$CONF['System'] = [
    'systemId',
    'name',
    'description',
    'userId',
    'vcsUrl',
    'vcsBranch',
    'vcsType',
    'vcsUser',
    'vcsPassword',
    'created',
    'lastEdit',
    'isArchived',
    'logalyzerKeywords'
];
$CONF['Project'] = [
    'projectId',
    'name',
    'description',
    'userId',
    'systemId',
    'isFinished',
    'environment',
    'isArchived'
];
$CONF['Experiment'] = [
    'experimentId',
    'name',
    'userId',
    'description',
    'systemId',
    'phases',
    'status',
    'created',
    'projectId',
    'postData',
    'internalId',
    'isArchived',
    'resultId'
];
$CONF['Evaluation'] = [
    'evaluationId',
    'name',
    'description',
    'systemId',
    'experimentId',
    'internalId',
    'isArchived',
    'isStarred'
];
$CONF['Job'] = [
    'jobId',
    'userId',
    'description',
    'systemId',
    'environment',
    'phases',
    'configuration',
    'status',
    'progress',
    'result',
    'created',
    'started',
    'finished',
    'evaluationId',
    'internalId',
    'configurationIdentifier',
    'logalyzerWarnings',
    'logalyzerErrors',
    'logalyzerHash'
];
$CONF['Result'] = [
    'resultId',
    'data'
];
$CONF['Event'] = [
    'eventId',
    'title',
    'time',
    'eventText',
    'eventType',
    'relatedId',
    'userId'
];
$CONF['User'] = [
    'userId',
    'username',
    'password',
    'email',
    'lastname',
    'firstname',
    'gender',
    'role',
    'alive',
    'activated',
    'created',
    'lastEdit',
    'lastLogin'
];
$CONF['Setting'] = [
    'settingId',
    'section',
    'item',
    'value',
    'systemId'
];
$CONF['Session'] = [
    'sessionId',
    'selector',
    'token',
    'userId',
    'created',
    'expires'
];
$CONF['ProjectUser'] = [
    'projectUserId',
    'userId',
    'projectId'
];

// views

$CONF['ExperimentView'] = [
    'experimentId',
    'name',
    'userId',
    'description',
    'systemId',
    'phases',
    'status',
    'created',
    'projectId',
    'postData',
    'internalId',
    'isArchived',
    'resultId',
    'projectUserId'
];
$CONF['EvaluationView'] = [
    'evaluationId',
    'name',
    'description',
    'systemId',
    'experimentId',
    'internalId',
    'isArchived',
    'isStarred',
    'projectUserId'
];
$CONF['EvaluationRunningView'] = [
    'evaluationId',
    'name',
    'description',
    'systemId',
    'experimentId',
    'internalId',
    'isArchived',
    'projectUserId'
];
$CONF['JobView'] = [
    'jobId',
    'userId',
    'description',
    'systemId',
    'environment',
    'phases',
    'configuration',
    'status',
    'progress',
    'result',
    'created',
    'started',
    'finished',
    'evaluationId',
    'internalId',
    'configurationIdentifier',
    'projectUserId'
];

foreach ($CONF as $NAME => $COLUMNS) {
    $class = file_get_contents(dirname(__FILE__) . "/AbstractModel.template.txt");
    $class = str_replace("__MODEL_NAME__", $NAME, $class);
    $vars = [];
    $init = [];
    $keyVal = [];
    $class = str_replace("__MODEL_PK__", $COLUMNS[0], $class);
    $functions = [];
    $params = [];
    $variables = [];
    foreach ($COLUMNS as $col) {
        if (sizeof($vars) > 0) {
            $getter = "function get" . strtoupper($col[0]) . substr($col, 1) . "(){\n    return \$this->$col;\n  }";
            $setter = "function set" . strtoupper($col[0]) . substr($col, 1) . "(\$$col){\n    \$this->$col = \$$col;\n  }";
            $functions[] = $getter;
            $functions[] = $setter;
        }
        $params[] = "\$$col";
        $vars[] = "private \$$col;";
        $init[] = "\$this->$col = \$$col;";
        $keyVal[] = "\$dict['$col'] = \$this->$col;";
        $variables[] = "const " . makeConstant($col) . " = \"$col\";";

    }
    $class = str_replace("__MODEL_PARAMS__", implode(", ", $params), $class);
    $class = str_replace("__MODEL_VARS__", implode("\n  ", $vars), $class);
    $class = str_replace("__MODEL_PARAMS_INIT__", implode("\n    ", $init), $class);
    $class = str_replace("__MODEL_KEY_VAL__", implode("\n    ", $keyVal), $class);
    $class = str_replace("__MODEL_GETTER_SETTER__", implode("\n  \n  ", $functions), $class);
    $class = str_replace("__MODEL_VARIABLE_NAMES__", implode("\n  ", $variables), $class);

    if (true || !file_exists(dirname(__FILE__) . "/" . $NAME . ".class.php")) {
        file_put_contents(dirname(__FILE__) . "/" . $NAME . ".class.php", $class);
    }

    $class = file_get_contents(dirname(__FILE__) . "/AbstractModelFactory.template.txt");
    $class = str_replace("__MODEL_NAME__", $NAME, $class);
    $dict = [];
    $dict2 = [];
    foreach ($COLUMNS as $col) {
        if (sizeof($dict) == 0) {
            $dict[] = "-1";
            $dict2[] = "\$dict['$col']";
        } else {
            $dict[] = "null";
            $dict2[] = "\$dict['$col']";
        }
    }
    $class = str_replace("__MODEL_DICT__", implode(", ", $dict), $class);
    $class = str_replace("__MODEL__DICT2__", implode(", ", $dict2), $class);

    if (true || !file_exists(dirname(__FILE__) . "/" . $NAME . "Factory.class.php")) {
        file_put_contents(dirname(__FILE__) . "/" . $NAME . "Factory.class.php", $class);
    }
}

$class = file_get_contents(dirname(__FILE__) . "/Factory.template.txt");
$static = [];
$functions = [];
foreach ($CONF as $NAME => $COLUMNS) {
    $lowerName = strtolower($NAME[0]) . substr($NAME, 1);
    $static[] = "private static \$" . $lowerName . "Factory = null;";
    $functions[] = "public static function get" . $NAME . "Factory() {\n    if (self::\$" . $lowerName . "Factory == null) {\n      \$f = new " . $NAME . "Factory();\n      self::\$" . $lowerName . "Factory = \$f;\n      return \$f;\n    } else {\n      return self::\$" . $lowerName . "Factory;\n    }\n  }";
}
$class = str_replace("__MODEL_STATIC__", implode("\n  ", $static), $class);
$class = str_replace("__MODEL_FUNCTIONS__", implode("\n  \n  ", $functions), $class);

file_put_contents(dirname(__FILE__) . "/../Factory.class.php", $class);


function makeConstant($name) {
    $output = "";
    for ($i = 0; $i < strlen($name); $i++) {
        if ($name[$i] == strtoupper($name[$i]) && $i < strlen($name) - 1) {
            $output .= "_";
        }
        $output .= strtoupper($name[$i]);
    }
    return $output;
}

