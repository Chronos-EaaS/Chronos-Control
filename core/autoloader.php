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
 * Automatically includes files containing classes that are called.
 *
 * @param $className
 */
function loader($className) {
    $coreClasses = [
        "View",
        "Controller",
        "System",
        "SystemView",
        "Element",
        "Plot",
        "API",
        "Util",
        "Define",
        "ProcessException"
    ];

    if (in_array($className, $coreClasses)) {
        $filename = $className;
        $folder = '/core/';
    } else {
        //parse out filename where class should be located
        if (strpos($className, '_') === false) {
            die("Invalid class name for autoload");
        }
        list($filename, $suffix) = preg_split('/_/', $className);

        //select the folder where class should be located based on suffix
        switch (strtolower($suffix)) {
            case 'library':
                $folder = '/libraries/';
                break;

            case 'system':
                $folder = '/webroot/systems/' . strtolower($filename) . '/';
                break;

            default:
                die("No valid suffix for autoload: " . $suffix . " from " . $className);
                break;
        }
    }

    //compose file name
    $file = SERVER_ROOT . $folder . strtolower($filename) . '.php';

    //fetch file
    if (file_exists($file)) {
        include_once($file);
        // Check if there is a 'static constructor' to call
        if (method_exists($className, '__initStatic')) {
            $className::__initStatic();
        }
    } else {
        //file does not exist!
        if (DEBUGMODE) {
            die("File '$filename' containing class '$className' not found in '$folder'.");
        }
        die(ERROR_TEXT);
    }
}

spl_autoload_register("loader");
