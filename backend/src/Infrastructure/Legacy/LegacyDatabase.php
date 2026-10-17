<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Legacy;

/** Bridge to the existing connection and agent CDL model factories. */
final class LegacyDatabase
{
    public static function connection(): \PDO
    {
        return \DBA\Factory::getUserFactory()->getDB();
    }

    public static function jobCdl(int $id): string
    {
        return \Util::jobToCDL(\DBA\Factory::getJobFactory()->get($id));
    }
}
