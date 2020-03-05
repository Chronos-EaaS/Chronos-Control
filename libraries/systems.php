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

use DBA\Factory;

abstract class Systems_Library {

    /**
     * @return System[]
     */
    public static function getSystems() {
        $result = [];
        $systems = Factory::getSystemFactory()->filter([]);
        foreach ($systems as $system) {
            try {
                $result[] = new System($system->getId());
            } catch (Exception $e) {
                // TODO: maybe not fail silently here, but we don't want to block all systems just because one of them fails to load
            }
        }
        return $result;
    }

    public static function getSystem($name) {
        $systems = self::getSystems();
        foreach ($systems as $system) {
            if ($system->getIdentifier() == $name) {
                return $system;
            }
        }
        return null;
    }

    /**
     * removes all spaces and ' from str for security reasons
     * @param string $str The string to escape
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
     * @param $str
     * @return array
     * @throws Exception
     */
    public static function getArrayFromString($str) {
        $result = [];
        $systems = explode(",", $str);
        foreach ($systems as $systemName) {
            $system = static::getSystem($systemName);
            if ($system === null) {
                throw new Exception("Unknown system: " . $system);
            }
            $result[] = $system->getModel()->getId();
        }
        return $result;
    }

    /**
     * @param $id
     * @return string
     * @throws Exception
     */
    public static function update($id) {
        $system = Factory::getSystemFactory()->get($id);
        Logger_Library::getInstance()->notice("Executing update of system " . $system->getName() . ". Current (old) revision: " . static::getRevision($system->getId()));
        $path = SERVER_ROOT . "/webroot/systems/" . $system->getId();

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
        $system = Factory::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . $system->getId();
        // clone repo
        $result = VCS_Library::cloneSystem($path, $system);
        return $result;
    }

    /**
     * @param $id
     * @return string
     * @throws Exception
     */
    public static function getRevision($id) {
        $system = Factory::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . $system->getId();
        return VCS_Library::getRevision($path, $system->getVcsType());
    }

    /**
     * @param $id
     * @return array
     * @throws Exception
     */
    public static function getBranches($id) {
        $system = Factory::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . $system->getId();
        $branches = VCS_Library::getBranches($path, $system->getVcsType());
        foreach ($branches as $k => &$branch) {
            if (strlen($branch) == 0) {
                unset($branches[$k]);
                continue;
            }
            $branch = trim($branch);
        }
        return $branches;
    }


    /**
     * @param $id
     * @return array
     * @throws Exception
     */
    public static function getHistory($id) {
        $system = Factory::getSystemFactory()->get($id);
        $path = SERVER_ROOT . "/webroot/systems/" . $system->getId();
        return VCS_Library::getHistory($path, $system->getVcsType());
    }


    /**
     * @param $system \DBA\System
     * @return string
     */
    public static function initSystem($system) {
        // create local system folders
        $folder = SERVER_ROOT . "/webroot/systems/" . $system->getId();
        mkdir($folder);
        $json = ["name" => $system->getName(), "identifier" => uniqid()];
        file_put_contents($folder . "/config.json", json_encode($json));
        copy(dirname(__FILE__) . "/../webroot/images/active.png", $folder . "/logo.png");
        $result = "OK";
        if (`which git`) {
            $result = shell_exec("cd '$folder' && git init");
            $result .= shell_exec("cd '$folder' && git add . && git commit -m 'initial system creation'");
        }
        return $result;
    }
}