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
namespace Chronos\Domain\Workflows;

final class JobStatus
{
    public const FAILED = -2;
    public const ABORTED = -1;
    public const SCHEDULED = 0;
    public const SETUP = 1;
    public const RUNNING = 2;
    public const FINISHED = 3;
    public const NAMES = [ "-2" => "FAILED", "-1" => "ABORTED", "0" => "SCHEDULED", "1" => "SETUP", "2" => "RUNNING", "3" => "FINISHED" ];

    public static function label($status) {
        switch ($status) {
            case JobStatus::SCHEDULED:
                return "scheduled";
            case JobStatus::SETUP:
                return "setup";
            case JobStatus::RUNNING:
                return "running";
            case JobStatus::FINISHED:
                return "finished";
            case JobStatus::ABORTED:
                return "aborted";
            case JobStatus::FAILED:
                return "failed";
            default:
                return "unknown";
        }
    }
}
