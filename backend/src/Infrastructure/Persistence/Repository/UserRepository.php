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

use Chronos\Infrastructure\Persistence\Model\AbstractModel;
use Chronos\Infrastructure\Persistence\Model\User;

final class UserRepository extends AbstractRepository
{
    // Keep the v2 physical schema compatible with existing installations and rollback.
    public const ENABLED_COLUMN = 'alive';

    protected const TABLE = 'User';
    protected const PRIMARY_KEY = 'userId';
    protected const COLUMNS = ['userId', 'username', 'password', 'email', 'lastname', 'firstname', 'gender', 'role', self::ENABLED_COLUMN, 'activated', 'created', 'lastEdit', 'lastLogin'];

    /** Normalize a stored row without exposing the historical column to application code. */
    public static function applicationValues(array $row): array
    {
        $values = [];
        foreach ($row as $column => $value) $values[$column === self::ENABLED_COLUMN ? 'enabled' : $column] = $value;
        return $values;
    }

    /** Map application writes while preserving the existing physical database schema. */
    public static function storageValues(array $values): array
    {
        $row = [];
        foreach ($values as $column => $value) $row[$column === 'enabled' ? self::ENABLED_COLUMN : $column] = $value;
        return $row;
    }

    protected function persistenceValues(AbstractModel $model): array
    {
        return self::storageValues($model->getKeyValueDict());
    }

    protected function hydrate(array $row): User
    {
        return new User($row['userId'], $row['username'], $row['password'], $row['email'], $row['lastname'], $row['firstname'], $row['gender'], $row['role'], $row[self::ENABLED_COLUMN], $row['activated'], $row['created'], $row['lastEdit'], $row['lastLogin']);
    }
}
