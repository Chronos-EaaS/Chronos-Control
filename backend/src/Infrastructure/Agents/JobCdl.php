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

declare(strict_types=1);
namespace Chronos\Infrastructure\Agents;

use Chronos\Application\Agents\JobCdlAssembler;
use Chronos\Infrastructure\Configuration\SystemSettings;
use Chronos\Infrastructure\Persistence\Model\Job;
use Chronos\Infrastructure\Persistence\RepositoryRegistry;

/** Resolves persisted agent inputs before the pure CDL document assembly. */
final class JobCdl
{
    public static function forId(int $id): string
    {
        return self::forJob(RepositoryRegistry::getJobRepository()->get($id));
    }

    public static function forJob(Job $job): string
    {
        $systemId = $job->getSystemId();
        $settings = SystemSettings::getInstance($systemId);
        $setup = RepositoryRegistry::getSystemRepository()->get($systemId)->getCem()
            ? $settings->getSection('setup') : [];
        $configuration = json_decode($job->getConfiguration(), true);
        return JobCdlAssembler::build($systemId, $configuration['parameters'], $settings->getSection('general'), $setup);
    }
}
