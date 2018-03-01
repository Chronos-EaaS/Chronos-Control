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

use DBA\QueryFilter;
use DBA\Setting;

/**
 * Class for storing and receiving settings
 */
class Settings_Library {

    /** @var DBA\System */
    private $system;

    /**
     * Holds instance of this class for singleton pattern
     */
    static private $instance = array();


    /**
     * Settings_Library constructor.
     * @param int $systemId
     * @throws Exception
     */
    public function __construct($systemId) {
        global $FACTORIES;

        if ($systemId == 0) {
            // this is used for the chronos settings
            $this->system = new \DBA\System(0, "Placeholder", "", 0, "", "", "", "", "", null, null, '');
            return;
        }
        $this->system = $FACTORIES::getSystemFactory()->get($systemId);
        if ($this->system == null) {
            throw new Exception("System ID ($systemId) not found!");
        }
    }


    /**
     * Singleton
     * @param $systemId
     * @return Settings_Library Instance of settings library for specified system
     */
    static public function getInstance($systemId) {
        if (empty(self::$instance[$systemId])) {
            self::$instance[$systemId] = new self($systemId);
        }
        return self::$instance[$systemId];
    }


    /**
     * Get the setting(s)
     * @param null|string $section The section (null for all sections)
     * @param null|string $key The key (null for all keys in the section)
     * @return Setting|Setting[]
     * @throws Exception
     */
    public function get($section = null, $key = null) {
        global $FACTORIES;

        if ($section && $key) {
            $qF1 = new QueryFilter(Setting::SYSTEM_ID, $this->system->getId(), "=");
            $qF2 = new QueryFilter(Setting::SECTION, $section, "=");
            $qF3 = new QueryFilter(Setting::ITEM, $key, "=");
            return $FACTORIES::getSettingFactory()->filter(array($FACTORIES::FILTER => array($qF1, $qF2, $qF3)), true);
        } else if ($section && !$key) {
            return $this->dumpAsArray($section);
        } else {
            return $this->dumpAsArray();
        }
    }


    /**
     * Set a setting
     * @param string $section The section
     * @param string $key The key
     * @param mixed $value The value
     * @throws Exception
     */
    public function set($section, $key, $value) {
        global $FACTORIES;

        $logger = Logger_Library::getInstance();
        $logger->debug("Saved new setting! system: " . $this->system->getId() . " | section: " . $section . " | key: " . $key . " | value: " . $value);
        if (is_bool($value)) {
            $value = $value ? "1" : "0";
        }
        if (empty($key) || $value == "" || empty($section)) {
            throw new Exception("Empty key or empty section or empty value!");
        }

        $setting = $this->get($section, $key);
        if ($setting == null) {
            $setting = new Setting(0, $section, $key, $value, $this->system->getId());
            $FACTORIES::getSettingFactory()->save($setting);
        } else {
            $setting->setValue($value);
            $FACTORIES::getSettingFactory()->update($setting);
        }
    }


    /**
     * Delete a setting
     * @param string $section The section
     * @param string $key The key
     * @throws Exception
     */
    public function delete($section, $key) {
        global $FACTORIES;

        $logger = Logger_Library::getInstance();
        $logger->debug("Deleted setting! system: " . $this->system->getId() . " | section: " . $section . " | key: " . $key);
        if (empty($key) || empty($section)) {
            throw new Exception("Empty key or empty section!");
        }
        $setting = $this->get($section, $key);
        $FACTORIES::getSettingFactory()->delete($setting);
    }


    /**
     * Dump all the settings to an Array
     * @param null|string $section The section (null for all sections)
     * @return Setting[]
     * @throws Exception
     */
    private function dumpAsArray($section = null) {
        global $FACTORIES;

        $qF1 = new QueryFilter(Setting::SYSTEM_ID, $this->system->getId(), "=");
        if ($section == null) {
            return $FACTORIES::getSettingFactory()->filter(array($FACTORIES::FILTER => $qF1));
        } else {
            $qF2 = new QueryFilter(Setting::SECTION, $section, "=");
            return $FACTORIES::getSettingFactory()->filter(array($FACTORIES::FILTER => array($qF1, $qF2)));
        }
    }


    /**
     * Get all settings in a section with the keys as array key
     * @param $section
     * @return array
     * @throws Exception
     */
    public function getSection($section) {
        $settings = array();
        $arr = $this->dumpAsArray($section);
        foreach ($arr as $setting) {
            $settings[$setting->getItem()] = $setting->getValue();
        }
        return $settings;
    }

}