<?php
declare(strict_types=1);

namespace Chronos\Application\Cem;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Persistence\GlobalSettings;
use Chronos\Application\Shared\EventProjection;

final class NodeService
{
    private readonly Store $db;
    private readonly GlobalSettings $settings;
    private readonly NodeHealth $health;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->db;
        $this->settings = new GlobalSettings($this->db);
        $this->health = new NodeHealth($this->settings);
    }

    public function nodes(array $query): array
    {
        $includeMissing = Input::queryBoolean($query['includeMissing'] ?? 'false', 'includeMissing');
        unset($query['includeMissing']);
        $options = $this->context->options($query, ['id' => 'n.nodeId', 'hostname' => 'n.hostname', 'environment' => 'n.environment', 'updated' => 'n.lastUpdate'], 'hostname');
        $where = ['1=1'];
        $args = [];
        if (!$includeMissing) {
            $where[] = 'n.lastUpdate>?';
            $args[] = $this->health->cutoff();
        }
        if ($options['search'] !== '') {
            $pattern = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $options['search']) . '%';
            $where[] = '(CONVERT(n.nodeId USING utf8mb4) LIKE ? ESCAPE \'=\' OR CONVERT(n.hostname USING utf8mb4) LIKE ? ESCAPE \'=\' OR CONVERT(n.environment USING utf8mb4) LIKE ? ESCAPE \'=\')';
            array_push($args, $pattern, $pattern, $pattern);
        }
        $sql = implode(' AND ', $where);
        $count = $this->db->row('SELECT COUNT(*) AS count FROM `Node` n WHERE ' . $sql, $args);
        $offset = ($options['page'] - 1) * $options['pageSize'];
        $rows = $this->db->rows('SELECT n.* FROM `Node` n WHERE ' . $sql . ' ORDER BY ' . $options['order'] . ',n.nodeId ASC LIMIT ' . $options['pageSize'] . ' OFFSET ' . $offset, $args);
        return ['items' => array_map(fn(array $row): array => $this->nodeDto($row), $rows), 'total' => (int) $count['count'], 'page' => $options['page'], 'pageSize' => $options['pageSize'], 'missingAfterSeconds' => $this->health->missingAfter()];
    }

    private function nodeDto(array $row): array
    {
        $cpu = is_numeric($row['cpu']) ? max(0.0, min(100.0, (float) $row['cpu'])) : null;
        $used = is_numeric($row['memoryUsed']) && (float) $row['memoryUsed'] >= 0 ? (int) $row['memoryUsed'] : null;
        $total = is_numeric($row['memoryTotal']) && (float) $row['memoryTotal'] > 0 ? (int) $row['memoryTotal'] : null;
        $memory = $used !== null && $total !== null ? max(0.0, min(100.0, 100 * $used / $total)) : null;
        return [
            'id' => $row['nodeId'], 'hostname' => $row['hostname'] ?: $row['nodeId'], 'environment' => $row['environment'],
            'version' => $row['version'], 'os' => $row['os'] ?? '', 'ip' => $row['ip'] ?? '',
            'currentJobId' => $row['currentJob'] === null ? null : (int) $row['currentJob'],
            'cpuPercent' => $cpu, 'memoryUsedBytes' => $used, 'memoryTotalBytes' => $total, 'memoryPercent' => $memory,
            'healthStatus' => Dto::text($row['healthStatus'] ?? ''), 'warning' => trim($row['healthStatus'] ?? '') !== '',
            'lastSeenAt' => Dto::instant($row['lastUpdate']), 'missing' => $row['lastUpdate'] === null || $row['lastUpdate'] <= $this->health->cutoff(),
        ];
    }

    public function nodeDetail(string $id): array
    {
        $id = Input::text($id, 'nodeId', 64);
        $this->db->assertText('Node', 'nodeId', $id, 'nodeId');
        $row = $this->db->row('SELECT * FROM `Node` WHERE nodeId=?', [$id])
            ?? throw new HttpError(404, 'not_found', 'This node does not exist.');
        $rows = $this->db->rows('SELECT e.*,u.firstname AS actorFirstName,u.lastname AS actorLastName FROM (SELECT e.* FROM `Event` e WHERE e.nodeId=? ORDER BY e.time DESC,e.eventId DESC LIMIT 50) e LEFT JOIN `User` u ON u.userId=e.userId ORDER BY e.time DESC,e.eventId DESC', [$id]);
        $events = (new EventProjection($this->context))->events($rows);
        foreach ($events as $index => &$event) {
            $event['jobId'] = $rows[$index]['relatedId'] === null ? null : (int) $rows[$index]['relatedId'];
        }
        unset($event);
        return ['node' => $this->nodeDto($row), 'events' => $events];
    }
}
