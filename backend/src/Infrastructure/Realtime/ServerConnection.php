<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Amp\Websocket\WebsocketClient;

/** Per-browser subscriptions are bounded and never shared between identities. */
final class ServerConnection
{
    public array $subscriptions = [];
    public bool $validating = false;
    public int $received = 0;
    public int $window = 0;

    public function __construct(public readonly WebsocketClient $client, public readonly ?string $lease) {}
}
