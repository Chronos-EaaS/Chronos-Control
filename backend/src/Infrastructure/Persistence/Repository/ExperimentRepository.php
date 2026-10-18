<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Sein Coray

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

namespace Chronos\Infrastructure\Persistence\Repository;

use Chronos\Infrastructure\Persistence\Model\Experiment;

final class ExperimentRepository extends AbstractRepository
{
    protected const TABLE = 'Experiment';
    protected const PRIMARY_KEY = 'experimentId';
    protected const COLUMNS = ['experimentId', 'name', 'userId', 'description', 'systemId', 'phases', 'status', 'created', 'projectId', 'postData', 'internalId', 'isArchived', 'resultId', 'defaultEnvironment'];

    protected function hydrate(array $row): Experiment
    {
        return new Experiment($row['experimentId'], $row['name'], $row['userId'], $row['description'], $row['systemId'], $row['phases'], $row['status'], $row['created'], $row['projectId'], $row['postData'], $row['internalId'], $row['isArchived'], $row['resultId'], $row['defaultEnvironment']);
    }
}
