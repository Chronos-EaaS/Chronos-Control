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

abstract class Systems_Library {

    /**
     * @param string $type
     * @return array
     * @throws Exception
     */
    public static function getSystems($type = 'all') {
        global $FACTORIES;

        $result = array();
        $systems = $FACTORIES::getSystemFactory()->filter(array());
        foreach ($systems as $system) {
            $className = $system->getName() . "_System";
            $obj = new stdClass();
            $obj->id = $system->getId();
            $obj->uniqueName = $system->getName();
            $obj->displayName = $system->getName();
            $obj->type = self::getSystemType($className);
            $obj->className = $className;

            if ($type == 'all' || $type == $obj->type) {
                array_push($result, $obj);
            }
        }
        return $result;
    }

    /**
     * @param $name
     * @return bool|mixed
     * @throws Exception
     */
    public static function getSystem($name) {
        $systems = static::getSystems('all');
        foreach ($systems as $system) {
            if (strtolower($system->uniqueName) == strtolower($name)) {
                return $system;
            }
        }
        return false;
    }

    /**
     * removes all spaces and ' from str for security reasons
     * @param  string $str The string to escape
     * @return string           The escaped string
     */
    public static function escapeCMD($str) {
        $str = escapeshellcmd($str);
        $str = preg_replace('/\s+/', '', $str);
        $str = str_replace("'", '', $str);
        $str = str_replace('"', '', $str);
        return $str;
    }


    /**
     * @param $uniqueName
     * @return bool
     * @throws Exception
     */
    public static function getClassName($uniqueName) {
        $systems = self::getSystems();
        foreach ($systems as $system) {
            if ($system->uniqueName == $uniqueName) {
                return $system->className;
            }
        }
        return false;
    }


    /**
     * @param $str
     * @return array
     * @throws Exception
     */
    public static function getArrayFromString($str) {
        $result = array();
        $systems = explode(",", $str);
        foreach ($systems as $systemName) {
            $system = static::getSystem($systemName);
            if ($system === false) {
                throw new Exception("Unknown system: " . $system);
            }
            $result[] = $system->id;
        }
        return $result;
    }


    public static function getSystemType($className) {
        $reflection = new \ReflectionMethod($className, 'createData');
        if ($reflection && $reflection->getDeclaringClass()->getName() !== 'System') {
            return 'generator';
        }
        return 'dbms';
    }


    public static function hasShowResultsMethod($systemUniqueName) {
        $className = $systemUniqueName . "_System";
        if (method_exists($className, 'evaluationResults')) {
            return true;
        }
        return false;
    }


    /**
     * @param $id
     * @return string
     * @throws Exception
     */
    public static function update($id) {
        global $FACTORIES;

        $system = $FACTORIES::getSystemFactory()->get($id);
        Logger_Library::getInstance()->notice("Executing update of system " . $system->getName() . ". Current (old) revision: " . static::getRevision($system->getId()));
        $path = SERVER_ROOT . "/webroot/systems/" . escapeshellcmd(strtolower($system->getName()));

        // pull and update repo
        $result = VCS_Library::update($path, $system->getVcsType(), $system->getVcsBranch(), $system->getVcsUrl(), $system->getVcsUser(), $system->getVcsPassword());

        Logger_Library::getInstance()->notice("Update of system " . $system->getName() . " completed. New revision: " . static::getRevision($system->getId()));
        return $result;
    }

    /**
     * @param $id
     * @return string
     * @throws Exception
     */
    public static function cloneRepository($id) {
        global $FACTORIES;

        $system = $FACTORIES::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . escapeshellcmd(strtolower($system->getName()));
        // clone repo

        $result = VCS_Library::cloneSystem($path, $system);
        return $result;
    }

    /**
     * @param $id
     * @return string
     * @throws Exception
     */
    public
    static function getRevision($id) {
        global $FACTORIES;

        $system = $FACTORIES::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . escapeshellcmd(strtolower($system->getName()));
        return VCS_Library::getRevision($path, $system->getVcsType());
    }

    /**
     * @param $id
     * @return array
     * @throws Exception
     */
    public
    static function getBranches($id) {
        global $FACTORIES;

        $system = $FACTORIES::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . escapeshellcmd(strtolower($system->getName()));
        $result = VCS_Library::getBranches($path, $system->getVcsType());
        $branches = explode("\n", $result);
        foreach ($branches as $k => &$branch) {
            if (strlen($branch) == 0) {
                unset($branches[$k]);
                continue;
            }
            $branch = trim($branch);
        }
        return $branches;
    }
}