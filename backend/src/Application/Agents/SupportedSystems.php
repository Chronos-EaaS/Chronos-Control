<?php
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

namespace Chronos\Application\Agents;

use Exception;
use Chronos\Infrastructure\Configuration\SystemDefinition;
use Chronos\Infrastructure\Persistence\RepositoryRegistry;
use Chronos\Infrastructure\Persistence\Store;

final class SupportedSystems
{
    private static function getSystems() {
        $result = [];
        $systems = RepositoryRegistry::getSystemRepository()->filter([]);
        foreach ($systems as $system) {
            try {
                $result[] = new SystemDefinition($system->getId());
            } catch (Exception $e) {
                // TODO: maybe not fail silently here, but we don't want to block all systems just because one of them fails to load
            }
        }
        return $result;
    }

    /** Return live system IDs for jobs without a snapshot; also recognize retired names in frozen legacy plans. */
    public static function resolveIdentifiers(array $identifiers, Store $db): array {
        $result = [];
        $unknown = array_fill_keys($identifiers, true);
        foreach (self::getSystems() as $system) {
            $name = $system->getIdentifier();
            if (in_array($name, $identifiers, true)) {
                $result[] = $system->getModel()->getId();
                unset($unknown[$name]);
            }
        }
        if ($unknown !== []) {
            $marks = implode(',', array_fill(0, count($unknown), '?'));
            $frozen = $db->rows(
                'SELECT DISTINCT systemIdentifier FROM JobSpecification WHERE adapter IS NULL AND systemIdentifier IN (' . $marks . ')',
                array_keys($unknown)
            );
            foreach ($frozen as $row) unset($unknown[$row['systemIdentifier']]);
            if ($unknown !== []) throw new Exception('Unknown system: ' . array_key_first($unknown));
        }
        return $result;
    }
}
