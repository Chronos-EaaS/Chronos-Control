<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Context;

/** Application operations independent of HTTP requests and routing. */
final class WorkflowFilters
{

    public static function append(array $options, string $alias, string &$where, array &$args): void
    {
        if ($options['archived'] !== 'all') {
            $where .= ' AND ' . $alias . '.isArchived=?'; $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            $where .= ' AND (' . $alias . ".name LIKE ? ESCAPE '=' OR " . $alias . ".description LIKE ? ESCAPE '=')";
            array_push($args, Context::search($options['search']), Context::search($options['search']));
        }
    }
}
