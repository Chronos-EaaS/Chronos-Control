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

namespace Chronos\Infrastructure\Persistence\Query;

use Chronos\Infrastructure\Persistence\Repository\AbstractRepository;

final class ContainFilter extends Filter
{
    public function __construct(private readonly string $key, private readonly array $values,
        private readonly ?AbstractRepository $repository = null, private readonly bool $inverse = false) {}

    public function getQueryString(string $table = ''): string
    {
        if ($this->values === []) return $this->inverse ? 'TRUE' : 'FALSE';
        $prefix = $this->repository?->getModelTable() ?? $table;
        return ($prefix === '' ? '' : $prefix . '.') . $this->key . ($this->inverse ? ' NOT' : '')
            . ' IN (' . implode(',', array_fill(0, count($this->values), '?')) . ')';
    }

    public function getValue(): array { return $this->values; }
    public function getHasValue(): bool { return true; }
}
