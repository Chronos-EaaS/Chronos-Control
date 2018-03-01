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

class Define {
    const JOB_STATUS_FAILED = -2;
    const JOB_STATUS_ABORTED = -1;
    const JOB_STATUS_SCHEDULED = 1;
    const JOB_STATUS_RUNNING = 2;
    const JOB_STATUS_FINISHED = 3;

    const JOB_EXCLUDE_PHASE_PREPARE = 0b00001; // 1
    const JOB_EXCLUDE_PHASE_WARM_UP = 0b00010; // 2
    const JOB_EXCLUDE_PHASE_EXECUTE = 0b00100; // 4
    const JOB_EXCLUDE_PHASE_ANALYZE = 0b01000; // 8
    const JOB_EXCLUDE_PHASE_CLEAN = 0b10000; // 16

    const DEFAULT_ENVIRONMENT_NAME = 'default';

    const EVENT_EXPERIMENT = "experiment";
    const EVENT_PROJECT = "project";
    const EVENT_EVALUATION = "evaluation";
    const EVENT_JOB = "job";
}