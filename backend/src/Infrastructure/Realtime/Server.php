<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use function Amp\async;
use function Amp\Future\awaitAll;

/** A bounded RFC6455 transport; projections and authentication execute in PHP workers. */
final class Server implements WebsocketAcceptor, WebsocketClientHandler
{
    public const HEARTBEAT_SECONDS = 15;
    // Replacing a sorted view sends an unsubscribe and a subscribe together.
    private const INCOMING_FRAMES_PER_SECOND = 128;
    private array $connections = [];
    private ?ContextWorkerPool $workers = null;
    private readonly SocketHttpServer $http;
    private readonly string $buildId;
    private mixed $notify = null;
    private mixed $lock = null;
    private array $watchers = [];
    private ?string $flushWatcher = null;
    private bool $draining = false;
    private bool $drainAgain = false;
    private bool $stopping = false;
    private bool $changingBuild = false;

    public function __construct(private readonly string $root, private readonly LoggerInterface $logger,
        private readonly string $address = '127.0.0.1:9081', private readonly ?string $publicOrigin = null, private readonly int $workerCount = 2)
    {
        if ($workerCount < 1 || $workerCount > 8) throw new RuntimeException('Live workers must be between 1 and 8.');
        $this->buildId = $this->currentBuild();
        $this->http = SocketHttpServer::createForDirectAccess($logger, enableCompression: false,
            connectionLimit: 256, connectionLimitPerIp: 32, concurrencyLimit: 64, allowedMethods: ['GET']);
    }

    public function run(): void
    {
        $this->bindNotifications();
        $websocket = new Websocket($this->http, $this->logger, $this, $this, clientFactory: new Rfc6455ClientFactory(
            heartbeatQueue: null, rateLimit: new ConstantRateLimit(65_536, self::INCOMING_FRAMES_PER_SECOND),
            parserFactory: new Rfc6455ParserFactory(textOnly: true, messageSizeLimit: 16_384, frameSizeLimit: 16_384), closePeriod: 1));
        $handler = new ClosureRequestHandler(function (Request $request) use ($websocket): Response {
            if ($this->stopping || $this->maintenance()) return $this->json(503, ['ready' => false]);
            if ($request->getUri()->getPath() === '/api/ui/live/health') {
                return $this->json(200, ['ready' => true, 'buildId' => $this->buildId, 'heartbeatMs' => self::HEARTBEAT_SECONDS * 1000]);
            }
            if ($request->getUri()->getPath() !== '/api/ui/live') return $this->json(404, ['error' => 'Not found.']);
            if (strtolower($request->getHeader('upgrade') ?? '') !== 'websocket') {
                return new Response(426, ['upgrade' => 'websocket', 'cache-control' => 'no-store'], 'A WebSocket connection is required.');
            }
            return $websocket->handleRequest($request);
        });
        $this->http->expose(new \Amp\Socket\InternetAddress(...$this->parseAddress()));
        $this->http->start($handler, new DefaultErrorHandler());
        $this->watchers[] = EventLoop::repeat(self::HEARTBEAT_SECONDS, function (): void {
            foreach ($this->connections as $connection) {
                async(function () use ($connection): void {
                    try { $this->heartbeat($connection); $this->validate($connection); }
                    catch (Throwable $error) { $this->logger->warning('Live heartbeat failed: ' . $error::class); }
                })->ignore();
                // Reconcile elapsed CEM health and legacy filesystem/FTP writes too.
                // Only changed projections produce frames; there is no browser polling.
                foreach ($connection->subscriptions as $id => $subscription) $this->queueSnapshot($connection, $id);
            }
            // Durable records recover a lost datagram; browsers never poll for changes.
            $this->scheduleDrain();
        });
        $this->watchers[] = EventLoop::repeat(1, function (): void {
            if ($this->maintenance()) { $this->stop(); return; }
            if ($this->currentBuild() !== $this->buildId && !$this->changingBuild) {
                $this->changingBuild = true;
                async(function (): void {
                    try {
                        $updates = [];
                        foreach ($this->connections as $connection) foreach ($connection->subscriptions as $id => $subscription) {
                            if (in_array($subscription->path, ['/meta', '/api/ui/meta'], true)) $updates[] = async(fn () => $this->snapshot($connection, $id));
                        }
                        [$errors] = awaitAll($updates);
                        if ($errors !== []) throw reset($errors);
                        foreach ($this->connections as $connection) $this->send($connection, ['type' => 'restart', 'reason' => 'build-changed', 'retryAfterMs' => 2500]);
                    } catch (Throwable $error) { $this->logger->warning('Live build notification failed: ' . $error::class); }
                    finally { $this->stop(); }
                })->ignore();
            }
        });
        if (extension_loaded('pcntl')) {
            foreach ([SIGTERM, SIGINT] as $signal) $this->watchers[] = EventLoop::onSignal($signal, fn () => $this->stop());
        }
        $this->scheduleDrain();
        try { EventLoop::run(); }
        finally { $this->cleanup(); }
    }

    public function handleHandshake(Request $request): Response
    {
        if (!$this->sameOrigin($request)) return $this->json(403, ['error' => 'The live origin is not permitted.']);
        if ($request->getUri()->getQuery() !== '') return $this->json(400, ['error' => 'Live credentials must not appear in URLs.']);
        $response = (new Rfc6455Acceptor())->handleHandshake($request);
        if ($response->getStatus() !== 101) return $response;
        $protocols = array_map('trim', explode(',', $request->getHeader('sec-websocket-protocol') ?? ''));
        if (!in_array('chronos-ui-v1', $protocols, true) || count($protocols) > 2) {
            return $this->json(400, ['error' => 'The Chronos live protocol is required.']);
        }
        $lease = null;
        foreach ($protocols as $protocol) {
            if ($protocol === 'chronos-ui-v1') continue;
            if (!preg_match('/^chronos-ticket\.([a-f0-9]{64})$/D', $protocol, $match)) return $this->json(401, ['error' => 'The live ticket is invalid.']);
            try { $lease = $this->work('consume', ['ticket' => $match[1]]); }
            catch (Throwable) { return $this->json(503, ['error' => 'Live authentication is temporarily unavailable.']); }
            if ($lease === null) return $this->json(401, ['error' => 'The live ticket has expired.']);
        }
        $request->setAttribute(self::class, $lease);
        $response->setHeader('sec-websocket-protocol', 'chronos-ui-v1');
        $response->setHeader('cache-control', 'no-store');
        return $response;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $connection = new ServerConnection($client, $request->getAttribute(self::class));
        $this->connections[$client->getId()] = $connection;
        try {
            $this->heartbeat($connection);
            while ($message = $client->receive()) {
                if ($connection->window !== time()) { $connection->window = time(); $connection->received = 0; }
                if (++$connection->received > self::INCOMING_FRAMES_PER_SECOND) { $client->close(1008, 'Too many live requests.'); break; }
                try { $frame = json_decode($message->buffer(), true, 16, JSON_THROW_ON_ERROR); }
                catch (Throwable) { $client->close(1007, 'A JSON live message is required.'); break; }
                if (!is_array($frame) || !is_string($frame['type'] ?? null) || !is_string($frame['id'] ?? null)
                    || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $frame['id'])) { $client->close(1008, 'Invalid live request.'); break; }
                $id = $frame['id'];
                if ($frame['type'] === 'unsubscribe') { unset($connection->subscriptions[$id]); continue; }
                if ($frame['type'] === 'refresh') {
                    if (isset($connection->subscriptions[$id])) $this->queueSnapshot($connection, $id);
                    continue;
                }
                if ($frame['type'] !== 'subscribe' || !is_string($frame['path'] ?? null) || strlen($frame['path']) > 250
                    || !is_array($frame['query'] ?? null) || count($frame['query']) > 20 || count($connection->subscriptions) >= 32 && !isset($connection->subscriptions[$id])) {
                    $client->close(1008, 'Invalid live subscription.'); break;
                }
                foreach ($frame['query'] as $key => $value) {
                    if (!is_string($key) || strlen($key) > 80 || !is_scalar($value) && $value !== null || is_string($value) && strlen($value) > 2000) {
                        $client->close(1008, 'Invalid live query.'); return;
                    }
                }
                $path = str_starts_with($frame['path'], '/api/ui/') ? substr($frame['path'], 7) : $frame['path'];
                $connection->subscriptions[$id] = new ServerSubscription($path, $frame['query']);
                $this->queueSnapshot($connection, $id);
            }
        } catch (WebsocketClosedException) {
            // A disconnected browser owns no subscriptions or pending output.
        } finally {
            unset($this->connections[$client->getId()]);
            if ($connection->lease !== null && !$this->stopping) async(function () use ($connection): void {
                try { $this->work('release', ['lease' => $connection->lease]); } catch (Throwable) {}
            })->ignore();
        }
    }

    private function queueSnapshot(ServerConnection $connection, string $id): void
    {
        $subscription = $connection->subscriptions[$id] ?? null;
        if ($subscription === null) return;
        if ($subscription->reading || $subscription->queued) { $subscription->pending = true; return; }
        $subscription->queued = true;
        async(function () use ($connection, $id, $subscription): void {
            $subscription->queued = false;
            if (($connection->subscriptions[$id] ?? null) !== $subscription) return;
            try { $this->snapshot($connection, $id); }
            catch (Throwable $error) {
                $this->logger->warning('Live projection failed: ' . $error::class);
                if (!$connection->client->isClosed()) $this->send($connection, ['type' => 'error', 'id' => $id, 'status' => 503,
                    'error' => ['code' => 'live_unavailable', 'message' => 'Live data is temporarily unavailable.']]);
            }
        })->ignore();
    }

    private function snapshot(ServerConnection $connection, string $id): void
    {
        $subscription = $connection->subscriptions[$id] ?? null;
        if ($subscription === null || $connection->client->isClosed()) return;
        if ($subscription->reading) {
            $subscription->pending = true;
            $subscription->completion?->getFuture()->await();
            return;
        }
        $subscription->reading = true;
        $subscription->completion = new \Amp\DeferredFuture();
        try {
            do {
                $subscription->pending = false;
                $result = $this->work('read', ['lease' => $connection->lease, 'path' => $subscription->path, 'query' => $subscription->query]);
                if (($connection->subscriptions[$id] ?? null) !== $subscription || $connection->client->isClosed()) return;
                $hash = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                if ($hash !== $subscription->hash) {
                    if ($result['ok'] ?? false) $this->send($connection, ['type' => 'data', 'id' => $id, 'revision' => ++$subscription->revision, 'data' => $result['data']]);
                    else $this->send($connection, ['type' => 'error', 'id' => $id, 'status' => $result['status'] ?? 500, 'error' => $result['error']]);
                    $subscription->hash = $hash;
                }
                if (($result['status'] ?? null) === 401) { $this->expire($connection); return; }
                if (($result['ok'] ?? false) && preg_match('#^/jobs/[1-9][0-9]*/log$#D', $subscription->path)) {
                    $subscription->query['offset'] = $result['data']['nextOffset'];
                    $subscription->query['epoch'] = $result['data']['epoch'];
                    if (($result['data']['truncated'] ?? false) && $result['data']['nextOffset'] > $result['data']['offset']) $subscription->pending = true;
                }
            } while ($subscription->pending);
        } finally {
            $subscription->reading = false;
            $subscription->completion->complete();
            $subscription->completion = null;
        }
    }

    private function scheduleDrain(): void
    {
        if ($this->stopping) return;
        if ($this->draining) { $this->drainAgain = true; return; }
        if ($this->flushWatcher !== null) return;
        $this->flushWatcher = EventLoop::delay(0.1, function (): void {
            $this->flushWatcher = null;
            async(function (): void {
                $this->draining = true;
                try {
                    do {
                        $this->drainAgain = false;
                        $rows = $this->work('drain');
                        if ($rows === []) continue;
                        $futures = [];
                        foreach ($this->connections as $connection) foreach ($connection->subscriptions as $id => $subscription) {
                            $futures[] = async(fn () => $this->snapshot($connection, $id));
                        }
                        [$errors] = awaitAll($futures);
                        if ($errors !== []) throw reset($errors);
                        $this->work('acknowledge', ['ids' => array_column($rows, 'id')]);
                        if (count($rows) === 100) $this->drainAgain = true;
                    } while ($this->drainAgain && !$this->stopping);
                } catch (Throwable $error) { $this->logger->warning('Live changes remain pending: ' . $error::class); }
                finally { $this->draining = false; }
            })->ignore();
        });
    }

    private function heartbeat(ServerConnection $connection): void
    {
        $this->send($connection, ['type' => 'heartbeat', 'at' => gmdate('c'), 'buildId' => $this->buildId, 'intervalMs' => self::HEARTBEAT_SECONDS * 1000]);
    }

    private function validate(ServerConnection $connection): void
    {
        if ($connection->lease === null || $connection->validating || $connection->client->isClosed()) return;
        $connection->validating = true;
        try { if ($this->work('validate', ['lease' => $connection->lease]) === null) $this->expire($connection); }
        finally { $connection->validating = false; }
    }

    private function expire(ServerConnection $connection): void
    {
        $this->send($connection, ['type' => 'session-expired']);
        $connection->client->close(1008, 'The signed-in session has expired.');
    }

    private function work(string $operation, array $arguments = []): mixed
    {
        // Processes, rather than optional native threads, isolate request state.
        $this->workers ??= new ContextWorkerPool($this->workerCount, new ContextWorkerFactory(
            $this->root . '/vendor/autoload.php', new ProcessContextFactory(binary: PHP_BINARY)));
        $deadline = new \Amp\TimeoutCancellation(30, 'The live projection worker did not finish.');
        $future = async(fn () => $this->workers->submit(new WorkerTask($this->root, $operation, $arguments), $deadline)->await($deadline));
        $future->ignore();
        try { return $future->await($deadline); }
        catch (\Amp\CancelledException $error) {
            // Killing the pool also interrupts synchronous PDO/CPU work that cannot
            // cooperatively process a cancellation. The supervisor starts a clean daemon.
            $this->stop();
            throw $error;
        }
    }

    private function send(ServerConnection $connection, array $frame): void
    {
        if (!$connection->client->isClosed()) $connection->client->sendText(json_encode($frame, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function sameOrigin(Request $request): bool
    {
        $origin = $request->getHeader('origin');
        if ($origin === null || $origin === 'null') return false;
        if ($this->publicOrigin !== null) return hash_equals($this->publicOrigin, $origin);
        $host = $request->getHeader('host') ?? '';
        $scheme = $request->getHeader('x-forwarded-proto') ?? $request->getUri()->getScheme();
        if (!in_array($scheme, ['http', 'https'], true) || !preg_match('/^(?:[a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])(?::[0-9]{1,5})?$/D', $host)) return false;
        return hash_equals($scheme . '://' . $host, $origin);
    }

    private function bindNotifications(): void
    {
        $directory = $this->root . '/locks';
        if (!is_dir($directory) && !mkdir($directory, 0700)) throw new RuntimeException('Live lock storage is unavailable.');
        if (is_link($directory) || (fileperms($directory) & 0002)) throw new RuntimeException('Live lock storage is unsafe.');
        $mask = umask(0077);
        try {
            $lockPath = $directory . '/.live-server.lock';
            if (is_link($lockPath)) throw new RuntimeException('Live lock storage is unsafe.');
            $this->lock = fopen($lockPath, 'c+b');
            if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another live server is already running.');
            $path = $directory . '/live-notify.sock';
            if (file_exists($path) || is_link($path)) {
                if (is_link($path) || filetype($path) !== 'socket') throw new RuntimeException('Live notification storage is unsafe.');
                if (!unlink($path)) throw new RuntimeException('The previous live notification socket could not be removed.');
            }
            $this->notify = stream_socket_server('udg://' . $path, $error, $message, STREAM_SERVER_BIND);
            if ($this->notify === false) throw new RuntimeException('The live notification socket could not be opened.');
            stream_set_blocking($this->notify, false);
            $this->watchers[] = EventLoop::onReadable($this->notify, function (): void {
                while (@stream_socket_recvfrom($this->notify, 1024) !== false) {}
                $this->scheduleDrain();
            });
        } finally { umask($mask); }
    }

    private function parseAddress(): array
    {
        if (!preg_match('/^(127\.0\.0\.1|0\.0\.0\.0):([0-9]{1,5})$/D', $this->address, $match) || (int) $match[2] < 1024 || (int) $match[2] > 65535) {
            throw new RuntimeException('The live bind address must be an explicit IPv4 address and unprivileged port.');
        }
        return [$match[1], (int) $match[2]];
    }

    private function currentBuild(): string
    {
        $bytes = @file_get_contents($this->root . '/webroot/ui/build.json');
        $build = $bytes === false ? null : json_decode($bytes, true);
        return is_string($build['id'] ?? null) ? $build['id'] : 'unavailable';
    }

    private function maintenance(): bool
    {
        clearstatcache(true, $this->root . '/.chronos-maintenance.json');
        return file_exists($this->root . '/.chronos-maintenance.json') || is_link($this->root . '/.chronos-maintenance.json');
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json', 'cache-control' => 'no-store'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function stop(): void
    {
        if ($this->stopping) return;
        $this->stopping = true;
        async(function (): void {
            foreach ($this->connections as $connection) async(fn () => $connection->client->close(1012, 'Chronos live is restarting.'))->ignore();
            try { $this->http->stop(); }
            finally { $this->cleanup(); }
        })->ignore();
    }

    private function cleanup(): void
    {
        foreach ($this->watchers as $watcher) EventLoop::cancel($watcher);
        $this->watchers = [];
        if ($this->flushWatcher !== null) { EventLoop::cancel($this->flushWatcher); $this->flushWatcher = null; }
        $this->workers?->kill();
        if (is_resource($this->notify)) {
            fclose($this->notify); $this->notify = null;
            @unlink($this->root . '/locks/live-notify.sock');
        }
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); $this->lock = null; }
    }
}
