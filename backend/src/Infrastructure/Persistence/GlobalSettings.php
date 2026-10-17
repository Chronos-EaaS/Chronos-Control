<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence;

final class GlobalSettings
{
    public function __construct(private readonly Store $db)
    {
    }

    public function get(string $section, string $key, string $fallback = ''): string
    {
        $row = $this->db->row('SELECT value FROM `Setting` WHERE systemId=0 AND section=? AND item=? ORDER BY settingId LIMIT 1', [$section, $key]);
        return $row === null ? $fallback : (string) $row['value'];
    }

    public function put(string $section, string $key, string $value): void
    {
        $existing = $this->db->row('SELECT settingId FROM `Setting` WHERE systemId=0 AND section=? AND item=? FOR UPDATE', [$section, $key]);
        if ($existing !== null) {
            $this->db->update('Setting', 'settingId', (int) $existing['settingId'], ['value' => $value]);
        } else {
            $this->db->insert('Setting', ['systemId' => 0, 'section' => $section, 'item' => $key, 'value' => $value]);
        }
    }
}
