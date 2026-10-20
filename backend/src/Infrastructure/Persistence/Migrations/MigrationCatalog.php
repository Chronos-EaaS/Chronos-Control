<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

/** Release schema versions are independent of display versions and Git build IDs. */
final class MigrationCatalog
{
    public const BASELINE = '0';

    /** This method is inert and needs no configured database. */
    public static function latestVersion(): string
    {
        return ResourceEventTimelineIndexMigration::VERSION;
    }

    /** @return list<Migration> Only trusted, packaged code supplies executable migrations. */
    public static function migrations(): array
    {
        return [new UserEnabledMigration(), new LiveUpdatesMigration(), new EventTimelineIndexMigration(), new ScopedEventTimelineIndexesMigration(), new ResourceEventTimelineIndexMigration()];
    }
}
