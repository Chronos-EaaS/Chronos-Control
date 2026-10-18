<?php

namespace Chronos\Infrastructure\Configuration;

use Exception;


/*
The MIT License (MIT)

Copyright (c) 2018 The Chronos Project

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

use Chronos\Infrastructure\Persistence\RepositoryRegistry;

class SystemDefinition {
    private $path;
    private $model;
    private $config;

    const CONFIG = "config.json";

    /**
     * System constructor.
     * @param $systemId
     * @throws Exception
     */
    public function __construct($systemId) {
        $this->model = RepositoryRegistry::getSystemRepository()->get($systemId);
        if ($this->model == null) {
            throw new Exception("Failed to load system with ID $systemId!");
        }
        $this->path = SERVER_ROOT . "/webroot/systems/" . $this->model->getId() . "/";
        $content = @file_get_contents($this->path . SystemDefinition::CONFIG);
        if ($content === false) {
            throw new Exception("Failed to load config for system with ID " . $this->model->getId());
        }
        $this->config = json_decode($content, true);
    }

    public function getName() {
        return $this->config['name'];
    }

    public function getIdentifier() {
        return $this->config['identifier'];
    }

    public function getModel() {
        return $this->model;
    }
}
