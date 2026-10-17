<?php
declare(strict_types=1);

namespace Chronos\Application\Administration;

use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Persistence\GlobalSettings;

final class SettingsService
{
    private readonly Store $db;
    private readonly array $actor;
    private readonly string $root;
    private readonly GlobalSettings $settings;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->db;
        $this->actor = $context->actor;
        $this->root = $context->root;
        $this->settings = new GlobalSettings($this->db);
    }

    private function secret(string $section, string $key): bool
    {
        return (bool) preg_match('/password|secret|token|private.?key|api.?key/i', $key)
            || ($section === 'maas' && in_array($key, ['key', 'consumer_key'], true));
    }

    private function revision(array $rows): string
    {
        return hash('sha256', serialize(array_map(static fn(array $row): array => [
            (int) $row['settingId'], (string) $row['item'], (string) $row['value'],
        ], $rows)));
    }

    private function sectionRows(string $section, bool $lock = false): array
    {
        return $this->db->rows('SELECT settingId,section,item,value FROM `Setting` WHERE systemId=0 AND section=? AND NOT(section=\'cem\' AND item=\'environments\') ORDER BY item,settingId' . ($lock ? ' FOR UPDATE' : ''), [$section]);
    }

    private function sectionDto(string $section, array $rows): array
    {
        return ['name' => $section, 'revision' => $this->revision($rows), 'settings' => array_map(function (array $row) use ($section): array {
            $secret = $this->secret($section, $row['item']);
            return ['key' => $row['item'], 'value' => $secret ? null : (string) $row['value'], 'secret' => $secret, 'configured' => $row['value'] !== ''];
        }, $rows)];
    }

    public function sections(): array
    {
        $names = $this->db->rows('SELECT DISTINCT section FROM `Setting` WHERE systemId=0 AND section<>\'vcs\' ORDER BY section');
        $sections = [];
        foreach ($names as $row) {
            $rows = $this->sectionRows($row['section']);
            if ($rows !== []) {
                $sections[] = $this->sectionDto($row['section'], $rows);
            }
        }
        return $sections;
    }

    public function saveSection(string $section, array $body): array
    {
        $section = Input::text($section, 'section', 50);
        if ($section === 'vcs') {
            throw new HttpError(403, 'dedicated_resource', 'Application update settings are managed through maintenance.');
        }
        Input::fields($body, ['revision', 'values']);
        $revision = Input::text($body['revision'] ?? null, 'revision', 64);
        if (!is_array($body['values'] ?? null) || array_is_list($body['values'])) {
            Input::invalid('values', 'Submit an object of setting names and values.');
        }
        $values = $body['values'];
        if ($values === []) {
            Input::invalid('values', 'Change at least one setting.');
        }
        return $this->db->transaction(function () use ($section, $revision, $values): array {
            $rows = $this->sectionRows($section, true);
            if ($rows === []) {
                throw new HttpError(404, 'not_found', 'This settings section does not exist.');
            }
            if (!hash_equals($this->revision($rows), $revision)) {
                throw new HttpError(409, 'settings_changed', 'These settings changed in another session. Reload them before saving.');
            }
            $keys = array_column($rows, 'item');
            foreach ($values as $key => $value) {
                if (!in_array($key, $keys, true)) {
                    Input::invalid('values.' . $key, 'This setting is not available in this section.');
                }
                $value = Input::text($value, 'values.' . $key, 4096, false);
                $this->validateSetting($section, $key, $value);
                $this->settings->put($section, $key, $value);
            }
            $this->context->event('user', $this->actor['id'], 'Global settings updated', 'Settings in section ' . $section . ' were updated.');
            return $this->sectionDto($section, $this->sectionRows($section));
        });
    }

    private function validateSetting(string $section, string $key, string $value): void
    {
        $field = 'values.' . $key;
        if ($section === 'other' && $key === 'rowsPerPage') {
            Input::integer($value, $field, 1, 100);
        } elseif ($section === 'other' && $key === 'maxJobsPerEvaluation') {
            Input::integer($value, $field, 1, 1000000);
        } elseif ($section === 'cem' && $key === 'nodeMissingTimeout') {
            Input::integer($value, $field, 1, 31536000);
        } elseif ($section === 'ftp' && $key === 'ftpPort') {
            Input::integer($value, $field, 1, 65535);
        } elseif ($section === 'ftp' && $key === 'useFtpUploadForLocalClients' && !in_array($value, ['0', '1'], true)) {
            Input::invalid($field, 'Use 0 or 1.');
        }
    }
}
