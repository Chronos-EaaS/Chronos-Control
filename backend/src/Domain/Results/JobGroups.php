<?php
declare(strict_types=1);

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

namespace Chronos\Domain\Results;

/** Group in-memory result jobs without persistence or file-system access. */
final class JobGroups
{
    public static function getDifferentParameters(array $jobs): array
    {
        $jobsParams = [];
        $parameterNames = [];
        foreach ($jobs as $entry) {
            $job = is_array($entry) ? ($entry[0] ?? null) : $entry;
            if ($job === null) {
                continue;
            }
            // Existing job-generation callers rely on this method normalizing
            // configuration JSON on the in-memory model. No result or database
            // value is rewritten here.
            $configuration = $job->getConfiguration();
            if (is_string($configuration)) {
                $decoded = json_decode($configuration, true);
                if (is_array($decoded)) {
                    $job->setConfiguration($decoded);
                }
            }
            $parameters = self::getJobParameters($job);
            $jobsParams[$job->getId()] = $parameters;
            foreach (array_keys($parameters) as $parameter) {
                $parameterNames[$parameter] = true;
            }
        }
        $variations = [];
        foreach (array_keys($parameterNames) as $parameter) {
            $first = true;
            $referencePresent = false;
            $reference = null;
            foreach ($jobsParams as $parameters) {
                $present = array_key_exists($parameter, $parameters);
                $value = $parameters[$parameter] ?? null;
                if ($first) {
                    $referencePresent = $present;
                    $reference = $value;
                    $first = false;
                } else if ($present !== $referencePresent || $value != $reference) {
                    $variations[] = $parameter;
                    break;
                }
            }
        }
        return [$variations, $jobsParams];
    }

    public static function getJobParameters(object $job): array
    {
        $configuration = $job->getConfiguration();
        if (is_string($configuration)) {
            $configuration = json_decode($configuration, true);
        }
        $parameters = is_array($configuration) ? ($configuration['parameters'] ?? []) : [];
        return is_array($parameters) ? $parameters : [];
    }

    public static function mergeJobs(array $jobs, array $parameter): array
    {
        $parameter = array_values(array_unique($parameter));
        $rowIndices = [];
        $seriesIndices = [];
        $matrix = [];
        $labels = [];
        foreach ($jobs as $group) {
            if (count($group) === 0) {
                continue;
            }
            $parameters = self::getJobParameters($group[0]);
            $iteration = [];
            foreach ($parameter as $name) {
                $iteration[$name] = $parameters[$name] ?? null;
            }
            $row = array_diff_key($parameters, array_fill_keys(array_merge($parameter, ['run']), true));
            ksort($row);
            $rowKey = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);
            $seriesKey = json_encode($iteration, JSON_INVALID_UTF8_SUBSTITUTE);
            if (!array_key_exists($rowKey, $rowIndices)) {
                $rowIndices[$rowKey] = count($rowIndices);
            }
            if (!array_key_exists($seriesKey, $seriesIndices)) {
                $seriesIndices[$seriesKey] = count($seriesIndices);
                if (count($iteration) === 1) {
                    $labels[] = reset($iteration) ?? '(missing)';
                } else {
                    $parts = [];
                    foreach ($iteration as $name => $value) {
                        $parts[] = $name . ": " . ($value ?? '(missing)');
                    }
                    $labels[] = count($parts) > 0 ? implode(", ", $parts) : 'Results';
                }
            }
            $rowIndex = $rowIndices[$rowKey];
            $seriesIndex = $seriesIndices[$seriesKey];
            $matrix[$rowIndex][$seriesIndex] = array_merge($matrix[$rowIndex][$seriesIndex] ?? [], $group);
        }
        foreach ($matrix as &$row) {
            $row = array_replace(array_fill(0, count($seriesIndices), []), $row);
            ksort($row);
        }
        unset($row);
        return [$matrix, $labels];
    }
}
