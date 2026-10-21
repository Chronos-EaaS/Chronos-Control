<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketMessage;
use Chronos\Infrastructure\Realtime\Server;
use Chronos\Infrastructure\Realtime\ServerConnection;
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

    public function testLegitimateControlBurstDoesNotCloseTheConnection(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger());
        $request = $this->request(); $response = $server->handleHandshake($request);
        $messages = [];
        for ($index = 0; $index < 128; ++$index) {
            $messages[] = WebsocketMessage::fromText(json_encode(['type' => 'unsubscribe', 'id' => 'view-' . $index], JSON_THROW_ON_ERROR));
        }
        $messages[] = null;
        $client = $this->createMock(WebsocketClient::class);
        $client->method('getId')->willReturn(1);
        $client->method('isClosed')->willReturn(false);
        $client->expects(self::exactly(count($messages)))->method('receive')->willReturn(...$messages);
        $client->expects(self::once())->method('sendText');
        $client->expects(self::never())->method('close');
        $server->handleClient($client, $request, $response);
    }

    public function testBuildRestartAnnouncesItsIdentityEvenWhenAnotherClientCannotReceiveIt(): void
    {
        $server = new Server(CHRONOS_BACKEND_ROOT, new NullLogger());
        $failed = $this->createStub(WebsocketClient::class);
        $failed->method('isClosed')->willReturn(false);
        $failed->method('sendText')->willThrowException(new \RuntimeException('Disconnected while announcing.'));
        $active = $this->createMock(WebsocketClient::class);
        $active->method('isClosed')->willReturn(false);
        $active->expects(self::once())->method('sendText')->with(self::callback(static function (string $bytes): bool {
            return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR) === [
                'type' => 'restart', 'reason' => 'build-changed', 'buildId' => str_repeat('b', 40), 'retryAfterMs' => 2500,
            ];
        }));
        (new \ReflectionProperty($server, 'connections'))->setValue($server, [new ServerConnection($failed, null), new ServerConnection($active, null)]);
        (new \ReflectionMethod($server, 'announceBuildChange'))->invoke($server, str_repeat('b', 40));
    }
}
