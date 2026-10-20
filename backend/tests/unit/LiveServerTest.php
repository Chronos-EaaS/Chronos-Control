<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Chronos\Infrastructure\Realtime\Server;
use League\Uri\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The native RFC implementation still applies Chronos origin and credential rules. */
final class LiveServerTest extends TestCase
{
    private function request(array $headers = [], string $suffix = ''): Request
    {
        return new Request($this->createStub(Client::class), 'GET', Http::new('http://127.0.0.1:4200/api/ui/live' . $suffix), $headers + [
            'host' => '127.0.0.1:4200', 'origin' => 'http://127.0.0.1:4200',
            'upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-key' => base64_encode(str_repeat('a', 16)),
            'sec-websocket-version' => '13', 'sec-websocket-protocol' => 'chronos-ui-v1',
        ]);
    }

    public function testAnonymousSameOriginHandshakeNegotiatesOnlyThePublicProtocol(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger());
        $request = $this->request(); $response = $server->handleHandshake($request);
        self::assertSame(101, $response->getStatus());
        self::assertSame('chronos-ui-v1', $response->getHeader('sec-websocket-protocol'));
        self::assertNull($request->getAttribute(Server::class));
    }

    public function testForeignAndOpaqueOriginsAreRejectedEvenForAnonymousMetadata(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger());
        foreach (['https://foreign.example', 'null', 'http://127.0.0.1:4200.attacker.example'] as $origin) {
            self::assertSame(403, $server->handleHandshake($this->request(['origin' => $origin]))->getStatus());
        }
    }

    public function testConfiguredPublicOriginDoesNotTrustAClientSuppliedForwardedOrigin(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger(), publicOrigin: 'https://chronos.example.org');
        self::assertSame(403, $server->handleHandshake($this->request())->getStatus());
        self::assertSame(101, $server->handleHandshake($this->request(['origin' => 'https://chronos.example.org']))->getStatus());
    }

    public function testQueryCredentialsAndUnrecognizedSubprotocolsAreRejectedBeforeWorkerAuthentication(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger());
        self::assertSame(400, $server->handleHandshake($this->request([], '?ticket=secret'))->getStatus());
        self::assertSame(400, $server->handleHandshake($this->request(['sec-websocket-protocol' => 'other-protocol']))->getStatus());
        self::assertSame(401, $server->handleHandshake($this->request(['sec-websocket-protocol' => 'chronos-ui-v1, chronos-ticket.not-opaque']))->getStatus());
    }

    public function testMissingRfcUpgradeHeadersCannotNegotiateAConnection(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger());
        self::assertSame(426, $server->handleHandshake($this->request(['upgrade' => 'http']))->getStatus());
        self::assertSame(400, $server->handleHandshake($this->request(['sec-websocket-version' => '12']))->getStatus());
    }
}
