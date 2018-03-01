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

class History_Library {
    
    public static function add($controller, $function, $params) {
        $current = array(
            'controller' => $controller,
            'function' => $function,
            'params' => $params
        );
        
        if (!empty($_SESSION['history_current'])) {
            $last = $_SESSION['history_current'];
        } else {
            $last = null;
        }
        
        $_SESSION['history_last'] = $last;
        $_SESSION['history_current'] = $current;
        
        if (!empty($_SESSION['history_lastDifferent'])) {
            if ($current['controller'] != $last['controller'] || $current['function'] != $last['function'] || self::compareParams($current['params'], $last['params'])) {
                $_SESSION['history_lastDifferent'] = $last;
            }
        } else {
            $_SESSION['history_lastDifferent'] = $last;
        }
    }
    
    public static function currentPage() {
        if (!isset($_SESSION['history_current'])) {
            return '/' . DEFAULT_CONTROLLER . '/' . DEFAULT_ACTION;
        } else {
            return self::getURL($_SESSION['history_current']);
        }
    }
    
    public static function last() {
        return self::getURL($_SESSION['history_last']);
    }
    
    public static function lastDifferent() {
        return self::getURL($_SESSION['history_lastDifferent']);
    }
    
    private static function getURL($last) {
        $params = '';
        if (!empty($last['params'])) {
            foreach ($last['params'] as $p) {
                $params = $params . '/' . $p;
            }
        }
        return '/' . $last['controller'] . '/' . $last['function'] . $params;
    }
    
    // returns true if params-Arrays are diffrent (or empty)
    private static function compareParams($a, $b) {
        if (count($a) == 0 && count($b) == 0) {
            return false;
        }
        
        if ($a == $b) {
            return false;
        } else {
            return true;
        }
    }
}
