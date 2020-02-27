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

class Lock_Library {

    public static function lock($lockName) {
        $lock_file = LOCK_DIRECTORY . $lockName . LOCK_SUFFIX;

        if (file_exists($lock_file)) {
            return false;
        }

        file_put_contents($lock_file, getmypid());
        Logger_Library::getInstance()->notice($lockName . ': Lock acquired, processing the job...');
        return true;
    }


    public static function unlock($lockName) {
        $lock_file = LOCK_DIRECTORY . $lockName . LOCK_SUFFIX;

        if (file_exists($lock_file)) {
            unlink($lock_file);
            Logger_Library::getInstance()->notice($lockName . ': Releasing lock...');
        } else {
            Logger_Library::getInstance()->error('Unknown lock file: ' . $lockName);
        }

        return true;
    }

}

?>