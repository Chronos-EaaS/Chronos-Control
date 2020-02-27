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
class View {

    /**
     * Render as binary output (no design or view at all)
     * @var bool
     */
    protected $binaryOutput = false;

    // Holds variables assigned to the view
    protected $data = [];

    protected $redirect = '';
    protected $assets = [];

    protected $error;

    protected $includeJSFilesHead = [];
    protected $includeJSFilesBody = [];
    protected $includeCSSFiles = [];

    // JS which is included as inline script at the end of the page (after the js-files)
    protected $includeInlineJS = [];

    // CSS which is included at the end of the header
    protected $includeInlineCSS = [];

    // Holds path to view.
    static $file;
    static $folder;

    /**
     * Render API output
     * @var bool
     */
    protected $api = false;

    // Which design should be used
    protected $design = DEFAULT_DESIGN;

    // Which template should be used
    protected $template = DEFAULT_TEMPLATE;

    // the view type
    protected $type;


    /**
     * Creates a View
     *
     * @param string $name Name of the view in the format controller/action
     * @param string $type Which kind of view to use (default are the ones under /views/; api are those in the
     *                     directory /api/)
     */
    public function __construct($name, $type = 'default') {
        $this->type = $type;
        if ($type != 'default') {
            die('Wrong View! Expected default, view type is : ' . $type);
        }
        $folder = SERVER_ROOT . '/views/';
        $file = $name . '.php';
        if (file_exists($folder . $file)) {
            self::$file = $file;
            self::$folder = $folder;
        } else {
            die('View not found: ' . $folder . $file);
        }
    }


    /**
     * Get view type
     */
    public function getType() {
        return $this->type;
    }


    /**
     * Set binary output mode. Is ignored if in API mode.
     * @param bool $mode
     */
    public function setBinaryOutputMode($mode) {
        if (!$this->api) {
            $this->binaryOutput = $mode;
        }
    }


    /**
     * Receives assignments from controller and stores in local data array
     *
     * @param string $key
     * @param mixed $value
     */
    public function assign($key, $value) {
        $this->data[$key] = $value;
    }


    /**
     * Redirect the visitor to the specified url.
     * @param string $url
     */
    public function redirect($url) {
        $this->redirect = $url;
    }


    /**
     * Redirect the visitor to the specified page.
     *
     * @param       $controller
     * @param       $action
     * @param array $params
     */
    public function internalRedirect($controller, $action, array $params) {
        $url = 'http' . (($_SERVER['SERVER_PORT'] == 443) ? 's://' : '://') . $_SERVER['HTTP_HOST'] . '/' . $controller . '/' . $action . '/';
        foreach ($params as $key => $value) {
            $url .= $key . '=' . $value . '/';
        }
        $this->redirect = $url;
    }


    /**
     * Sets the design to use. null means no design (blank page)
     *
     * @param string $design Name of the design (in folder webroot/design/).
     */
    public function setDesign($design) {
        if ($design === null || is_dir(SERVER_ROOT . '/webroot/design/' . $design)) {
            $this->design = $design;
        } else {
            die('Design not found: ' . $design);
        }
    }


    /**
     * Sets the template to use. null means no template
     *
     * @param string $template Name of the template (in folder webroot/template/).
     */
    public function setTemplate($template) {
        if ($template === null || is_dir(SERVER_ROOT . '/webroot/template/' . $template)) {
            $this->template = $template;
        } else {
            die('Template not found: ' . $template);
        }
    }


    public function setError($error) {
        $this->error = $error;
        $log = Logger_Library::getInstance();
        $log->error('Set error: ' . $error);
    }


    /**
     * Add link to JS file to output.
     *
     * @param string $file Path to the JS file to add
     * @param string $position Either 'body' or 'head'. Default is 'body'.
     */
    public function includeJS($file, $position = 'body') {
        if ($position == 'head') {
            $this->includeJSFilesHead[] = $file;
        } else {
            $this->includeJSFilesBody[] = $file;
        }
    }


    /**
     * Add style definitions as inline JS to the output
     *
     * @param string $code The JS code
     */
    public function includeInlineJS($code) {
        $this->includeInlineJS[] = $code;
    }


    /**
     * Add a CSS file to output.
     *
     * @param string $file Path to the CSS file to add
     */
    public function includeCSS($file) {
        $this->includeCSSFiles[] = $file;
    }


    /**
     * Add style definitions as inline CSS to the output
     *
     * @param string $code The CSS style definitions as String
     */
    public function includeInlineCSS($code) {
        $this->includeInlineCSS[] = $code;
    }


    /**
     * Add asset to the output
     *
     * @param string $asset Name of the asset to add
     *
     * @throws Exception
     */
    public function includeAsset($asset) {
        $assetPath = SERVER_ROOT . '/webroot/assets/' . $asset . '/asset.php';
        if (file_exists($assetPath)) {
            if (!isset($this->assets[$asset])) {
                include($assetPath);
                $this->assets[$asset] = true;
            }
        } else {
            throw new Exception('Asset not found: ' . $asset);
        }
    }


    /**
     * Get the URL of the current page
     * @return string Path of the current page
     */
    public function getCurrentPage() {
        return History_Library::currentPage();
    }


    /**
     * Get the correct Settings Lib (overwritten in systemView)
     * @throws Exception
     */
    public function getSettingsLibrary() {
        return Settings_Library::getInstance(0);
    }


    /**
     * Set a different view folder. The system excepted it to have the same structure than the default view folder
     * view/.
     *
     * @param string $folder Path to the view folder
     */
    static function setViewFolder($folder) {
        $folder = SERVER_ROOT . $folder;
        if (file_exists($folder . self::$file)) {
            self::$folder = $folder;
        } else {
            die('View not found: ' . $folder . self::$file);
        }
    }


    /**
     * Destructor: Renders the output
     * @throws Exception
     */
    public function __destruct() {
        /** @noinspection PhpUnusedLocalVariableInspection */
        $auth = Auth_Library::getInstance();

        /** @noinspection PhpUnusedLocalVariableInspection */
        $settings = $this->getSettingsLibrary();

        // binary Outputs for Files or API
        if ($this->binaryOutput) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            $data = $this->data;
            /** @noinspection PhpUnusedLocalVariableInspection */
            $error = $this->error;
            include(self::$folder . self::$file);
            die();
        }

        // Used for system api
        if ($this->api) {
            if (!empty($this->error)) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                $error = $this->error;
                include(SERVER_ROOT . '/api/home/error.php');
            } else {
                echo json_encode($this->data);
            }
            die();
        }

        // header redirect
        if ($this->redirect != '' && empty($this->error)) {
            header('Location: ' . $this->redirect, true, 303);
        }

        if ($this->design !== null) {
            // read design required assets
            include(SERVER_ROOT . '/webroot/design/' . $this->design . '/assets.php');
        }

        if ($this->template !== null) {
            // read design required assets
            include(SERVER_ROOT . '/webroot/template/' . $this->template . '/assets.php');
        }

        // set Path to current page
        /** @noinspection PhpUnusedLocalVariableInspection */
        $currentPage = $this->getCurrentPage();

        // parse data variables into local variables, so that they render to the view
        /** @noinspection PhpUnusedLocalVariableInspection */
        $data = $this->data;

        // first render the view an write the output into buffer, because we may have defined required libs in the view which must be included in the header
        ob_start();
        if (!empty($this->error)) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            $error = $this->error;
            include(SERVER_ROOT . '/views/home/error.php');
        } else if ($this->redirect != '') {
            /** @noinspection PhpUnusedLocalVariableInspection */
            $target = $this->redirect;
            include(SERVER_ROOT . '/views/home/redirect.php');
        } else {
            include(self::$folder . self::$file);
        }
        $buffer = ob_get_contents();
        ob_end_clean(); // delete buffer

        // render the final page
        if ($this->design !== null) {
            include(SERVER_ROOT . '/webroot/design/' . $this->design . '/header.php');
        }
        if ($this->template !== null && empty($this->error)) {
            include(SERVER_ROOT . '/webroot/template/' . $this->template . '/template.php');
        } else {
            echo $buffer;
        }
        if ($this->design !== null) {
            include(SERVER_ROOT . '/webroot/design/' . $this->design . '/footer.php');
        }
    }
}
