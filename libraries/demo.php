<?php

/*
The MIT License (MIT)

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

class Demo_Library {
    /**
     * @throws Exception
     */
    public static function reset() {
        global $FACTORIES;

        // delete all system folders
        exec("rm -rf " . SERVER_ROOT . "/webroot/systems/*");

        // truncate all tables
        $DB = $FACTORIES::getExperimentFactory()->getDB();
        $res = $DB->query("SHOW TABLES");
        $tables = $res->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $DB->query("TRUNCATE TABLE " . $table);
        }

        // load demo.sql and fill tables
        $DB->query(file_get_contents(dirname(__FILE__) . "/demo/demo.sql"));

        // reset history
        $_SESSION['history_last'] = null;
        $_SESSION['history_current'] = null;

        // load systems and clone / copy them
        $systems = $FACTORIES::getSystemFactory()->filter(array());
        foreach ($systems as $system) {
            if (strlen($system->getVcsUrl()) > 0) {
                Systems_Library::cloneRepository($system->getId());
            } else {
                Builder_Library::initSystem($system);
            }
        }
    }
}