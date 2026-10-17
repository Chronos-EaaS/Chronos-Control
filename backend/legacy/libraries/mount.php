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

class Mount_Library {

    const LOCK_NAME = "MountDataDirectory";

    /**
     * @return true if the data directory is mounted and false if not.
     * @throws Exception if there is an error
     */
    public function checkIfDataDirectoryIsMounted() {
        // Check if mounted by comparing the device number of the potential mount point with the device number of the parent directory.
        $deviceNumberMountPoint = shell_exec('stat -fc%t:%T ' . UPLOADED_DATA_PATH . ' 2>&1');
        $deviceNumberParent = shell_exec('stat -fc%t:%T ' . UPLOADED_DATA_PATH . '/../ 2>&1');

        if ($deviceNumberMountPoint === false || $deviceNumberParent === false) {
            throw new Exception('Error while getting device number. Mount status is unknown!');
        }

        if ($deviceNumberMountPoint == $deviceNumberParent) {
            // not mounted
            return false;
        } else {
            // mounted
            return true;
        }

    }

    /**
     * @return string
     * @throws Exception
     */
    public function mountDataDirectory() {
        if (Lock_Library::lock(self::LOCK_NAME)) {
            try {
                if ($this::checkIfDataDirectoryIsMounted() === false) {
                    Logger_Library::getInstance()->notice("Mounting data directory: " . substr(UPLOADED_DATA_PATH, 0, -1));
                    return shell_exec('sudo /bin/mount ' . substr(UPLOADED_DATA_PATH, 0, -1) . ' 2>&1');
                } else {
                    throw new Exception("Data directory is already mounted!");
                }
            } finally {
                Lock_Library::unlock(self::LOCK_NAME);
            }
        } else {
            Logger_Library::getInstance()->error('Mount is currently executed in another thread!');
            sleep(5); // Wait until the mount in the other process is finished
            return "Mount is currently executed in another thread!";
        }
        return "";
    }


}