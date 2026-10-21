<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Domain\Configuration\CdlDocument;

final class SystemSettings
{
    public function __construct(private readonly Context $context, private readonly string $root) {}

    public function read(int $id): array
    {
        $system = $this->context->system($id);
        $result = Dto::system($system, $this->context->actor, $this->root);
        $result['settings'] = $this->settingDocument($id);
        $result['environments'] = array_column($this->context->db->rows("SELECT item FROM `Setting` WHERE systemId=? AND section='environments' ORDER BY item", [$id]), 'item');
        $result['repository'] = ['url' => preg_replace('#(https?://)[^/]*@#i', '$1[redacted]@', $system['vcsUrl']), 'branch' => $system['vcsBranch'], 'type' => $system['vcsType'], 'username' => $system['vcsUser'], 'hasPassword' => $system['vcsPassword'] !== '', 'remoteEnabled' => RepositoryService::remoteEnabled(), 'canEdit' => RepositoryService::remoteEnabled() && Policy::admin($this->context->actor)];
        $result['logoUrl'] = '/api/ui/systems/' . $id . '/logo';
        return $result;
    }

    public function update(int $id, array $body): array
    {
        $system = $this->context->system($id);

        Input::fields($body, ['name', 'description', 'ownerId', 'archived', 'cem', 'repository']);
        $changes = [];
        foreach (['name' => 256, 'description' => 65535] as $field => $max) if (array_key_exists($field, $body)) $changes[$field] = Dto::storedText(Input::text($body[$field], $field, $max, $field === 'name'), $field, $max);
        foreach (['archived' => 'isArchived', 'cem' => 'cem'] as $field => $column) if (array_key_exists($field, $body)) $changes[$column] = Input::boolean($body[$field], $field) ? 1 : 0;
        if (array_key_exists('ownerId', $body)) {
            Policy::require(Policy::manageSystemMaintainers($this->context->actor, $system));
            $changes['userId'] = Input::id($body['ownerId'], 'ownerId');
        }
        if (array_key_exists('repository', $body)) {
            Policy::require(RepositoryService::remoteEnabled() && Policy::admin($this->context->actor));
            $changes += RepositorySettings::changes($body['repository']);
        }
        if ($changes === []) Input::invalid('body', 'Submit at least one field.');
        $this->context->db->transaction(function () use ($id, $changes): void {
            $system = $this->context->system($id, true);
            if (array_key_exists('userId', $changes)) {
                Policy::require(Policy::manageSystemMaintainers($this->context->actor, $system));
                $owner = $this->context->eligibleUser($changes['userId'], 'ownerId', true);
                if ($this->context->db->row('SELECT userId FROM `SystemMaintainer` WHERE systemId=? AND userId=? FOR UPDATE', [$id, (int) $owner['userId']]) === null) {
                    Input::invalid('ownerId', 'Choose an existing eligible system maintainer as owner.');
                }
            }
            if (($changes['isArchived'] ?? 0) === 1 && $this->context->db->row('SELECT projectId FROM `Project` WHERE systemId=? AND isArchived=0 LIMIT 1 FOR UPDATE', [$id])) throw new HttpError(409, 'system_in_use', 'Archive current projects before archiving this system.');
            $this->context->db->update('System', 'systemId', $id, $changes + ['lastEdit' => date('Y-m-d H:i:s')]);
            $this->context->event('system', $id, 'System updated', 'System settings were updated.');
        });

        return $this->read($id);
    }

    private function settingDocument(int $id): array
    {
        $out = ['general' => [], 'setup' => [], 'defaultValues' => []];
        foreach ($this->context->db->rows("SELECT section,item,value FROM `Setting` WHERE systemId=? AND section IN ('general','setup','defaultValues') ORDER BY settingId", [$id]) as $row) $out[$row['section']][$row['item']] = (string) $row['value'];
        return array_map(static fn(array $values): object => (object) $values, $out);
    }

    public function settings(int $id): array
    {
        $this->context->system($id);
        return $this->settingDocument($id);
    }

    public function replaceSettings(int $id, array $body): array
    {
        $this->context->system($id);

        $body = DefinitionValues::arrays($body); Input::fields($body, ['general', 'setup', 'defaultValues']);
        $values = [];
        foreach ($body as $section => $entries) {
            if (!is_array($entries) || count($entries) > 1000) Input::invalid($section, 'Submit a settings object.');
            $values[$section] = [];
            foreach ($entries as $key => $value) {
                Input::text((string) $key, $section, 50);
                if (mb_strlen((string) $key, 'UTF-8') > 50) Input::invalid($section . '.' . $key, 'Use a setting name of at most 50 characters.');
                if ($section !== 'defaultValues') {
                    // These exact keys become CDL element names. No namespace
                    // bindings exist in that agent document, so colons are unsafe.
                    if (!CdlDocument::acceptsName((string) $key)) Input::invalid($section . '.' . $key, 'Use a valid XML element name without spaces, a leading digit or a namespace prefix.');
                }
                if (!is_string($value) && !is_int($value) && !is_bool($value) && !is_float($value)) Input::invalid($section . '.' . $key, 'Use text, a number or a boolean.');
                $values[$section][$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                if (strlen($values[$section][$key]) > 65535 || !mb_check_encoding($values[$section][$key], 'UTF-8')) Input::invalid($section . '.' . $key, 'This setting is too long or invalid.');
                if ($section !== 'defaultValues' && !CdlDocument::acceptsText($values[$section][$key])) {
                    Input::invalid($section . '.' . $key, 'Use text that can be represented in the agent XML configuration.');
                }
            }
        }
        $this->context->db->transaction(function () use ($id, $values): void {
            $this->context->system($id, true);
            foreach ($values as $section => $entries) {
                $this->context->db->execute('DELETE FROM `Setting` WHERE systemId=? AND section=?', [$id, $section]);
                foreach ($entries as $key => $value) $this->context->db->insert('Setting', ['systemId' => $id, 'section' => $section, 'item' => $key, 'value' => $value]);
            }
            $this->context->event('system', $id, 'System settings updated', 'Evaluation, setup or default settings were updated.');
        });

        return $this->settingDocument($id);
    }

    public function environments(int $id): array
    {
        $this->context->system($id);
        return ['environments' => array_column($this->context->db->rows("SELECT item FROM `Setting` WHERE systemId=? AND section='environments' ORDER BY settingId", [$id]), 'item')];
    }

    public function replaceEnvironments(int $id, array $body): array
    {
        $this->context->system($id);

        Input::fields($body, ['environments']);
        if (!is_array($body['environments'] ?? null) || !array_is_list($body['environments']) || count($body['environments']) > 1000) Input::invalid('environments', 'Submit an environment array.');
        $environments = array_map(static fn(mixed $value): string => Input::text($value, 'environments', 50), $body['environments']);
        if (count($environments) !== count(array_unique($environments))) Input::invalid('environments', 'Environment names must be unique.');
        $this->context->db->transaction(function () use ($id, $environments): void {
            $this->context->system($id, true);
            $this->context->db->execute("DELETE FROM `Setting` WHERE systemId=? AND section='environments'", [$id]);
            foreach ($environments as $name) $this->context->db->insert('Setting', ['systemId' => $id, 'section' => 'environments', 'item' => $name, 'value' => 'unused value']);
            $this->context->event('system', $id, 'Environments updated', 'Available system environments were updated.');
        });

        return $this->environments($id);
    }
}
