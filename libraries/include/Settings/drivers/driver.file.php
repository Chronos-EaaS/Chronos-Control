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

class SettingsDriver {
    
    const NEW_LINE  = "\n";
    const READ_SAFE = true;
    
    private $file;
    private $fileHandle;
    private $data;
    
    /**
     * SettingsDriver constructor.
     * @param $config
     * @throws Exception
     */
    public function __construct($config) {
        // If the setting file doesn't exist then lets create one
        if (!file_exists($config['file'])) {
            if (file_put_contents($config['file'], "") === false) { // If not created then throw an exception
                throw new Exception("Permission denied, file '{$config['file']}' not created");
            }
        }
        $this->file = $config['file'];
        $this->data = parse_ini_file($this->file, true, INI_SCANNER_RAW);
    }
    
    public function __destruct() {
        if (is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
    }
    
    public function readAll() {
        return $this->data;
    }
    
    public function readSection($section) {
        if (isset($this->data[$section])) {
            return $this->data[$section];
        }
        return null;
    }
    
    public function read($key, $section) {
        if (isset($this->data[$section][$key])) {
            return $this->data[$section][$key];
        }
        return null;
    }
    
    public function write($key, $value, $section) {
        $this->data[$section][$key] = $value;
    }
    
    public function flushBuffer() {
        if (empty($this->data) || !is_array($this->data)) { // If there is no data then go back
            return;
        }
        $store_section = true;
        $this->fileHandle = fopen($this->file, "r+");
        foreach ($this->data as $key => $value) {
            if (is_array($value)) {
                if ($store_section) {
                    if (ftell($this->fileHandle) == 0) {
                        if (self::READ_SAFE) {
                            fputs($this->fileHandle, ";<?php exit; ?>" . self::NEW_LINE);
                        }
                        fputs($this->fileHandle, "[{$key}]" . self::NEW_LINE);
                    } else {
                        fputs($this->fileHandle, self::NEW_LINE . "[{$key}]" . self::NEW_LINE);
                    }
                }
                foreach ($value as $subkey => $subvalue) {
                    fputs($this->fileHandle, trim($subkey) . "=" . trim($subvalue) . self::NEW_LINE);
                }
                continue;
            }
            fputs($this->fileHandle, trim($key) . "=" . trim($value) . self::NEW_LINE);
        }
        fclose($this->fileHandle);
        $this->data = parse_ini_file($this->file, true, INI_SCANNER_RAW);
    }
    
}
