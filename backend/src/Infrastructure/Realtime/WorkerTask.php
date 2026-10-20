<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Chronos\Infrastructure\Deployment\ApplicationLock;
use Chronos\Infrastructure\Persistence\Connection;
use RuntimeException;

/** Database and result processing always run outside the socket event loop. */
final class WorkerTask implements Task
{
    public function __construct(private readonly string $root, private readonly string $operation, private readonly array $arguments = []) {}

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $cancellation->throwIfRequested();
        if (!ApplicationLock::enterRequest($this->root)) {
            throw new RuntimeException('The live worker is restarting for maintenance.');
        }
        try {
            if (!defined('SERVER_ROOT')) {
                define('SERVER_ROOT', $this->root);
                require SERVER_ROOT . '/constants.php';
                require SERVER_ROOT . '/config.php';
                date_default_timezone_set('Europe/Zurich');
            }
            if (SERVER_ROOT !== $this->root) throw new RuntimeException('The live worker application root changed.');
            Connection::configureFromConstants();
            return match ($this->operation) {
                'consume' => (new LiveTickets($this->root))->consume($this->arguments['ticket']),
                'validate' => (new LiveTickets($this->root))->validate($this->arguments['lease']),
                'release' => (new LiveTickets($this->root))->release($this->arguments['lease']),
                'read' => (new LiveProjection($this->root))->read($this->arguments['lease'], $this->arguments['path'], $this->arguments['query']),
                'drain' => (new ChangeJournal(Connection::get(), $this->root))->drain(100),
                'acknowledge' => (new ChangeJournal(Connection::get(), $this->root))->acknowledge($this->arguments['ids']),
                default => throw new RuntimeException('Unknown live worker task.'),
            };
        } finally {
            ApplicationLock::leaveRequest();
            $_SESSION = [];
        }
    }
}
