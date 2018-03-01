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
 * Class for interacting with RESTful APIs.
 * Adapted from https://gonzalo123.com/2010/01/09/building-a-simple-http-client-with-php-a-rest-client/
 */
class Rest_Library {

    const HTTP  = 'http';
    const HTTPS = 'https';

    const POST   = 'POST';
    const GET    = 'GET';
    const DELETE = 'DELETE';

    const HTTP_OK       = 200;
    const HTTP_CREATED  = 201;
    const HTTP_ACCEPTED = 202;

    private $_host     = null;
    private $_port     = null;
    private $_user     = null;
    private $_pass     = null;
    private $_protocol = null;

    private $_connMultiple = false;

    private $_silentMode = false;
    private $_append     = array();

    private $_headers  = array();
    private $_requests = array();


    /**
     * Factory of the class. Lazy connect
     *
     * @param string $host
     * @param integer $port The port to use; Default is 80
     * @param string $protocol Either Rest_Library::HTTP or Rest_Library::HTTPS; Default is HTTP
     *
     * @return Rest_Library
     */
    static public function connect($host, $port = 80, $protocol = self::HTTP) {
        return new self($host, $port, $protocol, false);
    }

    /**
     * @return Rest_Library
     */
    static public function multiConnect() {
        return new self(null, null, null, true);
    }

    public function add($http) {
        $this->_append[] = $http;
        return $this;
    }

    /**
     *
     * @param bool $mode
     *
     * @return Rest_Library
     */
    public function silentMode($mode = true) {
        $this->_silentMode = $mode;
        return $this;
    }

    protected function __construct($host, $port, $protocol, $connMultiple) {
        $this->_connMultiple = $connMultiple;
        $this->_host = $host;
        $this->_port = $port;
        $this->_protocol = $protocol;
    }

    public function setCredentials($user, $pass) {
        $this->_user = $user;
        $this->_pass = $pass;
    }

    /**
     * @param string $url
     * @param array $params
     *
     * @return Rest_Library
     */
    public function post($url, $params = array()) {
        $this->_requests[] = array(self::POST, $this->_url($url), $params);
        return $this;
    }

    /**
     * @param string $url
     * @param array $params
     *
     * @return Rest_Library
     */
    public function get($url, $params = array()) {
        $this->_requests[] = array(self::GET, $this->_url($url), $params);
        return $this;
    }

    /**
     * @param string $url
     * @param array $params
     *
     * @return Rest_Library
     */
    public function delete($url, $params = array()) {
        $this->_requests[] = array(self::DELETE, $this->_url($url), $params);
        return $this;
    }

    public function _getRequests() {
        return $this->_requests;
    }

    /**
     * POST request
     *
     * @param string $url
     * @param array $params
     *
     * @return string
     * @throws Http_Exception
     */
    public function doPost($url, $params = array()) {
        return $this->_exec(self::POST, $this->_url($url), $params);
    }

    /**
     * GET Request
     *
     * @param string $url
     * @param array $params
     *
     * @return string
     * @throws Http_Exception
     */
    public function doGet($url, $params = array()) {
        return $this->_exec(self::GET, $this->_url($url), $params);
    }

    /**
     * DELETE Request
     *
     * @param string $url
     * @param array $params
     *
     * @return string
     * @throws Http_Exception
     */
    public function doDelete($url, $params = array()) {
        return $this->_exec(self::DELETE, $this->_url($url), $params);
    }

    /**
     * setHeaders
     *
     * @param array $headers
     *
     * @return Rest_Library
     */
    public function setHeaders($headers) {
        $this->_headers = $headers;
        return $this;
    }

    /**
     * Builds absolute url
     *
     * @param string $url
     *
     * @return string
     */
    private function _url($url = null) {
        return "{$this->_protocol}://{$this->_host}:{$this->_port}/{$url}";
    }

    /**
     * Performing the real request
     *
     * @param string $type
     * @param string $url
     * @param array $params
     *
     * @return string
     * @throws Http_Exception
     */
    private function _exec($type, $url, $params = array()) {
        $headers = $this->_headers;
        $s = curl_init();

        if (!is_null($this->_user)) {
            curl_setopt($s, CURLOPT_USERPWD, $this->_user . ':' . $this->_pass);
        }

        switch ($type) {
            case self::DELETE :
                curl_setopt($s, CURLOPT_URL, $url . '?' . http_build_query($params));
                curl_setopt($s, CURLOPT_CUSTOMREQUEST, self::DELETE);
                break;
            case self::POST :
                curl_setopt($s, CURLOPT_URL, $url);
                curl_setopt($s, CURLOPT_POST, true);
                curl_setopt($s, CURLOPT_POSTFIELDS, $params);
                break;
            case self::GET :
                curl_setopt($s, CURLOPT_URL, $url . '?' . http_build_query($params));
                break;
        }

        curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($s, CURLOPT_HTTPHEADER, $headers);
        $_out = curl_exec($s);
        $status = curl_getinfo($s, CURLINFO_HTTP_CODE);
        curl_close($s);
        switch ($status) {
            case self::HTTP_OK :
            case self::HTTP_CREATED :
            case self::HTTP_ACCEPTED :
                $out = $_out;
                break;
            default :
                if (!$this->_silentMode) {
                    throw new Http_Exception("http error: {$status}", $status);
                }
        }
        return $out;
    }

    public function run() {
        if ($this->_connMultiple) {
            return $this->_runMultiple();
        } else {
            return $this->_run();
        }
    }

    private function _runMultiple() {
        $out = null;
        if (count($this->_append) > 0) {
            $arr = array();
            foreach ($this->_append as $_append) {
                $arr = array_merge($arr, $_append->_getRequests());
            }

            $this->_requests = $arr;
            $out = $this->_run();
        }
        return $out;
    }

    private function _run() {
        $headers = $this->_headers;
        $curly = $result = array();

        $mh = curl_multi_init();
        foreach ($this->_requests as $id => $reg) {
            $curly[$id] = curl_init();

            $type = $reg[0];
            $url = $reg[1];
            $params = $reg[2];

            if (!is_null($this->_user)) {
                curl_setopt($curly[$id], CURLOPT_USERPWD, $this->_user . ':' . $this->_pass);
            }

            switch ($type) {
                case self::DELETE :
                    curl_setopt($curly[$id], CURLOPT_URL, $url . '?' . http_build_query($params));
                    curl_setopt($curly[$id], CURLOPT_CUSTOMREQUEST, self::DELETE);
                    break;
                case self::POST :
                    curl_setopt($curly[$id], CURLOPT_URL, $url);
                    curl_setopt($curly[$id], CURLOPT_POST, true);
                    curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $params);
                    break;
                case self::GET :
                    curl_setopt($curly[$id], CURLOPT_URL, $url . '?' . http_build_query($params));
                    break;
            }
            curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curly[$id], CURLOPT_HTTPHEADER, $headers);

            curl_multi_add_handle($mh, $curly[$id]);
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            sleep(0.2);
        } while ($running > 0);

        foreach ($curly as $id => $c) {
            $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
            switch ($status) {
                case self::HTTP_OK :
                case self::HTTP_CREATED :
                case self::HTTP_ACCEPTED :
                    $result[$id] = curl_multi_getcontent($c);
                    break;
                default :
                    if (!$this->_silentMode) {
                        $result[$id] = new Http_Multiple_Error($status, $type, $url, $params);
                    }
            }
            curl_multi_remove_handle($mh, $c);
        }

        curl_multi_close($mh);
        return $result;
    }

}

class Http_Exception extends Exception {
    const NOT_MODIFIED        = 304;
    const BAD_REQUEST         = 400;
    const NOT_FOUND           = 404;
    const NOT_ALLOWED         = 405;
    const CONFLICT            = 409;
    const PRECONDITION_FAILED = 412;
    const INTERNAL_ERROR      = 500;
}

class Http_Multiple_Error {
    private $_status = null;
    private $_type   = null;
    private $_url    = null;
    private $_params = null;

    function __construct($status, $type, $url, $params) {
        $this->_status = $status;
        $this->_type = $type;
        $this->_url = $url;
        $this->_params = $params;
    }

    function getStatus() {
        return $this->_status;
    }

    function getType() {
        return $this->_type;
    }

    function getUrl() {
        return $this->_url;
    }

    function getParams() {
        return $this->_params;
    }
}
