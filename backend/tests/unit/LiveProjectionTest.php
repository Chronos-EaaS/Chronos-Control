<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Realtime\LiveProjection;
use PHPUnit\Framework\TestCase;
final class LiveProjectionTest extends TestCase
{
    public function testOnlyReadOnlyJsonResourcesAreSubscribable(): void
    {
        foreach (['/meta', '/session', '/dashboard', '/jobs/3', '/jobs/3/log', '/jobs/3/results', '/evaluations/2/jobs', '/evaluations/2/results', '/cem/nodes/synthetic-node'] as $path) {
            self::assertTrue(LiveProjection::allowed($path), $path);
        }
        foreach (['/jobs/3/download', '/jobs/3/log/download', '/jobs/3/results.csv', '/evaluations/2/actions', '/systems/1/export', '/systems/1/repository/update', '/session/impersonation', '/admin/operations', '/live/ticket', 'https://elsewhere.test/meta', '/jobs/../session'] as $path) {
            self::assertFalse(LiveProjection::allowed($path), $path);
        }
    }
}
