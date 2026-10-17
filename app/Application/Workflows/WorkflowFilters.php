<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\TextSearch;

/** Application operations independent of HTTP requests and routing. */
final class WorkflowFilters
{

    public static function append(array $options, string $alias, string &$where, array &$args): void
    {
        if ($options['archived'] !== 'all') {
            $where .= ' AND ' . $alias . '.isArchived=?'; $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            [$clause, $patterns] = TextSearch::condition($options['search'], [$alias . '.name', $alias . '.description']);
            $where .= ' AND ' . $clause; array_push($args, ...$patterns);
        }
    }
}
