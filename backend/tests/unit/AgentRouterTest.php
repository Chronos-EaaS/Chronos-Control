<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;


final class AgentRouterTest extends TestCase
{
    public function testAgentPathParametersRetainEncodedValuesAndFormDecoding(): void
    {
        $route = \Chronos\Http\Api\V1\AgentRouter::route('/api/v1/cem/uniqueId=node%2FZ%C3%BCrich/version=1.2/environment=local+test/jobId=42/', 'GET');
        self::assertTrue($route['allowed']);
        self::assertSame(['uniqueId' => 'node/Zürich', 'version' => '1.2', 'environment' => 'local test', 'jobId' => '42'], $route['variables']);
        self::assertSame('cem', $route['endpoint']);
    }

    public function testOnlyExistingAgentMethodsCanBeDispatched(): void
    {
        self::assertTrue(\Chronos\Http\Api\V1\AgentRouter::route('/api/v1/job/id=next/supports=1%2C2', 'GET')['allowed']);
        self::assertTrue(\Chronos\Http\Api\V1\AgentRouter::route('/api/v1/job/id=42', 'PATCH')['allowed']);
        self::assertTrue(\Chronos\Http\Api\V1\AgentRouter::route('/api/v1/cem', 'POST')['allowed']);
        self::assertFalse(\Chronos\Http\Api\V1\AgentRouter::route('/api/v1/cem', 'PATCH')['allowed']);
        self::assertFalse(\Chronos\Http\Api\V1\AgentRouter::route('/api/v1/job/id=42', 'TRACE')['allowed']);
    }

    public function testBrowserAndUnregisteredSystemCodeCannotEnterAgentDispatch(): void
    {
        foreach (['/api/v1/../../controllers/admin', '/api/ui/job', '/api/system/1/data', '/api/v1/cem.php', '/api/v1/unknown', '/user/login'] as $path) self::assertNull(\Chronos\Http\Api\V1\AgentRouter::route($path, 'GET'));
    }
}
