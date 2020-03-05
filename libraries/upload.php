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

class Upload_Library {

    /**
     * @param $file
     * @param null $identifier
     * @param bool $onlyImages
     * @param string $folder
     * @return null|string
     * @throws Exception
     */
    public function upload($file, $identifier = null, $onlyImages = true, $folder = '/') {
        if (empty($file)) {
            throw new Exception('missing parameter.');
        }

        if ($this->checkError($file['error'])) {
            $type = $file['type'];
            if ($onlyImages && !$this->getImageExtension($type)) {
                exit("Not an image!");
            }

            //$size = $file['size'];

            if (!empty($file['name'])) {
                //$file_name = $file['name'];
                $temp_name = $file['tmp_name'];

                $ext = $this->getImageExtension($type);

                if ($identifier == null) {
                    $identifier = md5(date("d-m-Y") . "-" . time()) . $ext;
                }

                if ($folder == '/') {
                    $target_path = UPLOADED_DATA_PATH . $identifier;
                } else {
                    if (substr($folder, 0, 1) == '/') {
                        $folder = substr($folder, 1);
                    }
                    if (substr($folder, -1) != '/') {
                        $folder = $folder . '/';
                    }
                    $target_path = UPLOADED_DATA_PATH . $folder;
                    if (is_dir($target_path)) {
                        $target_path = UPLOADED_DATA_PATH . $folder . $identifier;
                    } else {
                        exit("Directory does not exist!");
                    }
                }

                if (file_exists($target_path)) {
                    exit("File already exists");
                }

                if (move_uploaded_file($temp_name, $target_path)) {
                    return $identifier;
                } else {
                    exit("Error while uploading file to the server");
                }
            } else {
                throw new Exception(ERROR_TEXT);
            }
        }
        // This should never happen
        return null;
    }


    /**
     * @param $error
     * @return bool
     * @throws Exception
     */
    private function checkError($error) {
        if ($error == UPLOAD_ERR_NO_FILE || $error == UPLOAD_ERR_PARTIAL) {
            throw new Exception('Error while uploading file. Please try again.');
        } elseif ($error == UPLOAD_ERR_FORM_SIZE || $error == UPLOAD_ERR_INI_SIZE) {
            throw new Exception('The file is to big.');
        } else {
            return true;
        }
    }


    private function getImageExtension($imagetype) {
        if (empty($imagetype)) {
            return false;
        }
        switch ($imagetype) {
            case 'image/bmp':
                return '.bmp';
            case 'image/gif':
                return '.gif';
            case 'image/jpeg':
                return '.jpg';
            case 'image/png':
                return '.png';
            default:
                return false;
        }
    }

}