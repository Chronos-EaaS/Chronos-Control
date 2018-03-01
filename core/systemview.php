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

/**
 * Handles the view functionality
 */
class SystemView extends View {

    private $system;
    /**
     * @var string
     */
    private $name;

    /**
     * Creates a View
     *
     * @param string $name Name of the view in the format controller/action
     * @param string $type Which kind of view to use (default are the ones under /views/; api are those in the
     *                     directory /api/)
     * @throws Exception
     */
    public function __construct($name, $type = 'default') {
        $this->type = $type;
        switch ($type) {
            case 'system':
                $folder = SERVER_ROOT . '/webroot/systems/';
                $file = $name . '.php';
                break;

            case 'system-api':
                $folder = null;
                $file = null;
                $this->api = true;
                break;

            default:
                die('View type not found: ' . $type);
        }

        if (file_exists($folder . $file) || $type == 'system-api') {
            self::$file = $file;
            self::$folder = $folder;
        } else {
            die('View not found: ' . $folder . $file);
        }

        if ($this->type == 'system') { // file and folder is not set for api
            $p = explode('/', static::$file);
            $uniquename = array_shift($p); // system uniquename is the first element
            $this->system = Systems_Library::getSystem($uniquename);
        }
    }


    /**
     * Add link to JS file to output. If it is not an asset, file path is treated relative.
     *
     * @param string $file Path to the JS file to add
     * @param string $position Either 'body' or 'head'. Default is 'body'.
     */
    public function includeJS($file, $position = 'body') {
        if ($position == 'head') {
            if (substr($file, 0, 8) != '/assets/') {
                $this->includeJSFilesHead[] = '/systems/' . strtolower($this->system->uniqueName) . '/' . $file;
            } else {
                $this->includeJSFilesHead[] = $file;
            }
        } else {
            if (substr($file, 0, 8) != '/assets/') {
                $this->includeJSFilesBody[] = '/systems/' . strtolower($this->system->uniqueName) . '/' . $file;
            } else {
                $this->includeJSFilesBody[] = $file;
            }
        }
    }

    /**
     * Add asset to the output
     *
     * @param string $asset Name of the asset to add
     *
     * @throws Exception
     */
    public function includeAsset($asset) {
        $systemPath = SERVER_ROOT . '/webroot/systems/' . strtolower($this->system->uniqueName) . '/system_assets/' . $asset . '/asset.php';
        $assetPath = SERVER_ROOT . '/webroot/assets/' . $asset . '/asset.php';
        if (file_exists($systemPath)) {
            include($systemPath);
        } else if (file_exists($assetPath)) {
            include($assetPath);
        } else {
            throw new Exception('Asset not found: ' . $asset);
        }
    }


    /**
     * Add a CSS file to output. If it is not an asset, file path is treated relative.
     *
     * @param string $file Path to the CSS file to add
     */
    public function includeCSS($file) {
        if (substr($file, 0, 8) != '/assets/') {
            $this->includeCSSFiles[] = '/systems/' . strtolower($this->system->uniqueName) . '/' . $file;
        } else {
            $this->includeCSSFiles[] = $file;
        }
    }


    /**
     * Get the correct Settings Lib
     * @throws Exception
     */
    public function getSettingsLibrary() {
        if ($this->type == 'system') { // system is not set for api
            return Settings_Library::getInstance(strtolower($this->system->uniqueName));
        }
        return null;
    }

}
