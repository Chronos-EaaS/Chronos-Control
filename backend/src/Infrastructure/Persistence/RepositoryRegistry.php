<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence;

use Chronos\Infrastructure\Persistence\Repository\EvaluationRepository;
use Chronos\Infrastructure\Persistence\Repository\EventRepository;
use Chronos\Infrastructure\Persistence\Repository\ExperimentRepository;
use Chronos\Infrastructure\Persistence\Repository\JobRepository;
use Chronos\Infrastructure\Persistence\Repository\NodeRepository;
use Chronos\Infrastructure\Persistence\Repository\SessionRepository;
use Chronos\Infrastructure\Persistence\Repository\SettingRepository;
use Chronos\Infrastructure\Persistence\Repository\SystemRepository;
use Chronos\Infrastructure\Persistence\Repository\UserRepository;

/** Shared repository instances use the explicitly configured request connection. */
final class RepositoryRegistry
{
    public const FILTER = 'filter';
    public const ORDER = 'order';
    public const UPDATE = 'update';

    private static array $repositories = [];

    public static function getEvaluationRepository(): EvaluationRepository { return self::$repositories[EvaluationRepository::class] ??= new EvaluationRepository(); }
    public static function getEventRepository(): EventRepository { return self::$repositories[EventRepository::class] ??= new EventRepository(); }
    public static function getExperimentRepository(): ExperimentRepository { return self::$repositories[ExperimentRepository::class] ??= new ExperimentRepository(); }
    public static function getJobRepository(): JobRepository { return self::$repositories[JobRepository::class] ??= new JobRepository(); }
    public static function getNodeRepository(): NodeRepository { return self::$repositories[NodeRepository::class] ??= new NodeRepository(); }
    public static function getSessionRepository(): SessionRepository { return self::$repositories[SessionRepository::class] ??= new SessionRepository(); }
    public static function getSettingRepository(): SettingRepository { return self::$repositories[SettingRepository::class] ??= new SettingRepository(); }
    public static function getSystemRepository(): SystemRepository { return self::$repositories[SystemRepository::class] ??= new SystemRepository(); }
    public static function getUserRepository(): UserRepository { return self::$repositories[UserRepository::class] ??= new UserRepository(); }
}
