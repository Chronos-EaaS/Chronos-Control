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

/** @var $CONN array */
define("DBA_SERVER", DB_HOST);
define("DBA_DB",  DB_NAME);
define("DBA_USER", DB_USER);
define("DBA_PASS", DB_PASSWORD);
define("DBA_PORT", DB_PORT);

require_once(dirname(__FILE__) . "/AbstractModel.class.php");
require_once(dirname(__FILE__) . "/AbstractModelFactory.class.php");
require_once(dirname(__FILE__) . "/Filter.class.php");
require_once(dirname(__FILE__) . "/Order.class.php");
require_once(dirname(__FILE__) . "/Join.class.php");
require_once(dirname(__FILE__) . "/Group.class.php");
require_once(dirname(__FILE__) . "/ComparisonFilter.class.php");
require_once(dirname(__FILE__) . "/ContainFilter.class.php");
require_once(dirname(__FILE__) . "/JoinFilter.class.php");
require_once(dirname(__FILE__) . "/OrderFilter.class.php");
require_once(dirname(__FILE__) . "/QueryFilter.class.php");
require_once(dirname(__FILE__) . "/GroupFilter.class.php");
require_once(dirname(__FILE__) . "/Util.class.php");
require_once(dirname(__FILE__) . "/UpdateSet.class.php");
require_once(dirname(__FILE__) . "/MassUpdateSet.class.php");
require_once(dirname(__FILE__) . "/LikeFilter.class.php");
require_once(dirname(__FILE__) . "/LikeFilterInsensitive.class.php");
require_once(dirname(__FILE__) . "/QueryFilterNoCase.class.php");

require_once(dirname(__FILE__) . "/Dataset.class.php");

$entries = scandir(dirname(__FILE__) . "/models");
foreach ($entries as $entry) {
  if (strpos($entry, ".class.php") !== false) {
    require_once(dirname(__FILE__) . "/models/" . $entry);
  }
}

require_once(dirname(__FILE__) . "/Factory.class.php");
define("DBA_VERSION", "1.0.0");