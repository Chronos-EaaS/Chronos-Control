<?php
declare(strict_types=1);

namespace Chronos\Application\Cem;

use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Persistence\GlobalSettings;

final class EnvironmentService
{
    private readonly Store $db;
    private readonly array $actor;
    private readonly string $root;
    private readonly GlobalSettings $settings;
    private readonly NodeHealth $health;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->db;
        $this->actor = $context->actor;
        $this->root = $context->root;
        $this->settings = new GlobalSettings($this->db);
        $this->health = new NodeHealth($this->settings);
    }

    private function environmentNames(bool $lock = false): array
    {
        $row = $this->db->row('SELECT value FROM `Setting` WHERE systemId=0 AND section=\'cem\' AND item=\'environments\'' . ($lock ? ' FOR UPDATE' : ''));
        if ($row === null || $row['value'] === '') {
            return [];
        }
        try {
            $names = json_decode((string) $row['value'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(409, 'invalid_configuration', 'The saved CEM environment list needs repair.');
        }
        if (!is_array($names) || !array_is_list($names) || count(array_filter($names, 'is_string')) !== count($names)) {
            throw new HttpError(409, 'invalid_configuration', 'The saved CEM environment list needs repair.');
        }
        return array_values(array_unique($names));
    }

    public function environments(): array
    {
        $items = [];
        foreach ($this->environmentNames() as $name) {
            $column = preg_match('/[\x{10000}-\x{10FFFF}]/u', $name) ? 'CONVERT(environment USING utf8mb4)' : 'environment';
            $row = $this->db->row('SELECT COUNT(*) AS count FROM `Node` WHERE ' . $column . '=? AND lastUpdate>?', [$name, $this->health->cutoff()]);
            $items[] = ['name' => $name, 'nodeCount' => (int) $row['count']];
        }
        return $items;
    }

    public function addEnvironment(array $body): array
    {
        Input::fields($body, ['name']);
        $name = Input::text($body['name'] ?? null, 'name', 64);
        if (strpbrk($name, "/\\?#%") !== false) {
            Input::invalid('name', 'Use an environment name without URL separators.');
        }
        $this->db->assertText('Node', 'environment', $name, 'name');
        $this->db->assertText('Job', 'environment', 'cem-' . $name, 'name');
        $this->db->assertText('Experiment', 'defaultEnvironment', 'cem-' . $name, 'name');
        $this->db->transaction(function () use ($name): void {
            $names = $this->environmentNames(true);
            if (in_array($name, $names, true)) {
                throw new HttpError(409, 'environment_exists', 'An environment with this name already exists.', ['name' => 'Choose another name.']);
            }
            $names[] = $name;
            $this->settings->put('cem', 'environments', json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->context->event('user', $this->actor['id'], 'CEM environment added', 'A CEM environment was added.');
        });
        return ['items' => $this->environments()];
    }

    public function deleteEnvironment(string $name): array
    {
        $name = Input::text($name, 'name', 64);
        $this->db->transaction(function () use ($name): void {
            $names = $this->environmentNames(true);
            if (!in_array($name, $names, true)) {
                throw new HttpError(404, 'not_found', 'This environment does not exist.');
            }
            // Historical BLOB-backed lists can contain names that an utf8mb3
            // node/job column cannot store. They must remain inspectable/removable.
            $column = preg_match('/[\x{10000}-\x{10FFFF}]/u', $name) ? 'CONVERT(environment USING utf8mb4)' : 'environment';
            $jobs = $this->db->row('SELECT COUNT(*) AS count FROM `Job` WHERE ' . $column . '=? AND status IN (-2,0,1,2)', ['cem-' . $name]);
            $nodes = $this->db->row('SELECT COUNT(*) AS count FROM `Node` WHERE ' . $column . '=? AND lastUpdate>?', [$name, $this->health->cutoff()]);
            if ((int) $jobs['count'] > 0 || (int) $nodes['count'] > 0) {
                throw new HttpError(409, 'environment_in_use', 'This environment still has reporting nodes or active jobs.');
            }
            $names = array_values(array_filter($names, static fn(string $item): bool => $item !== $name));
            $this->settings->put('cem', 'environments', json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->context->event('user', $this->actor['id'], 'CEM environment removed', 'An unused CEM environment was removed.');
        });
        return ['items' => $this->environments()];
    }
}
