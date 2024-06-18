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

abstract class API {
    
    const STATUS_NUM_OK    = 200;
    const STATUS_NUM_ERROR = 600;
    
    const STATUS_NUM_NO_JOB_IN_QUEUE    = 601;
    const STATUS_NUM_JOB_DOES_NOT_EXIST = 602;
    
    const STATUS_NUM_EVALUATION_DOES_NOT_EXIST = 611;
    const STATUS_NUM_EXPERIMENT_DOES_NOT_EXIST = 612;

    /**
     * Stores the data send with the get request
     * @var array
     */
    protected $get = [];
    
    public $request;
    
    private $data;
    private $error = '';
    
    public $statusCode;
    
    
    public function __construct($getVars) {
        $this->get = $getVars;
        parse_str(file_get_contents('php://input'), $this->request);
    }
    
    /**
     * @throws Exception
     */
    public function get() {
        throw new Exception('The action GET is not defined!');
    }

    /**
     * @throws Exception
     */
    public function set() {
        throw new Exception('The action SET is not defined!');
    }
    public function setData($rearrangeddata) {
        $this->data->content = $rearrangeddata;
    }
    public function getData() {
        return $this->data;
    }
    /**
     * @throws Exception
     */
    public function post() {
        throw new Exception('The action POST is not defined!');
    }
    
    /**
     * @throws Exception
     */
    public function head() {
        throw new Exception('The action HEAD is not defined!');
    }
    
    /**
     * @throws Exception
     */
    public function put() {
        throw new Exception('The action PUT is not defined!');
    }
    
    /**
     * @throws Exception
     */
    public function patch() {
        throw new Exception('The action PATCH is not defined!');
    }
    
    /**
     * @throws Exception
     */
    public function delete() {
        throw new Exception('The action DELETE is not defined!');
    }
    
    /**
     * @throws Exception
     */
    public function options() {
        throw new Exception('The action OPTIONS is not defined!');
    }
    
    /**
     * @throws Exception
     */
    public final function trace() {
        throw new Exception('The action TRACE is not defined!');
    }
    
    
    public function setError($error) {
        $this->error .= $error;
        $log = Logger_Library::getInstance();
        $log->info('Set error: ' . $error);
    }
    
    public function setStatusCode($num) {
        $this->statusCode = $num;
    }
    
    public function addData($key, $value) {
        if (is_array($this->data) || is_null($this->data)) {
            $this->data[$key] = $value;
        } else {
            $this->setError('data is not empty and not an array!');
        }
    }
    
    public function add($value) {
        if (!is_array($this->data) || is_null($this->data)) {
            $this->data = $value;
        } else {
            $this->setError('data is an array');
        }
    }
    
    /**
     * @return int  HTTP Status Code
     */
    private function getStatusCode() {
        if (!is_null($this->statusCode)) {
            return $this->statusCode;
        }
        if (empty($this->error) && is_null($this->statusCode)) {
            return static::STATUS_NUM_OK;
        }
        return static::STATUS_NUM_ERROR;
    }
    
    
    /**
     * @return string  Status Message
     */
    private function getStatusMessage() {
        if (empty($this->error)) {
            return 'success';
        } else {
            return $this->error;
        }
    }
    
    private function buildJsonEnvelope() {
        $json = new stdClass();
        $json->status = new stdClass();
        $json->status->code = $this->getStatusCode();
        $json->status->message = $this->getStatusMessage();
        if (!empty($this->data)) {
            $json->response = $this->data;
        }
        echo json_encode($json);
    }
    
    /**
     * Destructor: Renders the output
     * (Has the same function than a view for non api)
     */
    public function __destruct() {
        $this->buildJsonEnvelope();
    }
}