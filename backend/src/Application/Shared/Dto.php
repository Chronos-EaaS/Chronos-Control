<?php
declare(strict_types=1);

namespace Chronos\Application\Shared;

use Chronos\Application\Security\Policy;

final class Dto
{
    public static function text(string $value): string
    {
        // Existing UI controllers store HTML entities. DTOs are plain text;
        // callers render them as text, never as trusted markup.
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function storedText(string $value, string $field, int $max): string
    {
        $escaped = self::encodeStoredText($value);
        if (mb_strlen($escaped, 'UTF-8') > $max || ($max >= 65535 && strlen($escaped) > 65535)) {
            Input::invalid($field, 'This value is too long for legacy storage.');
        }
        return $escaped;
    }

    /** Keep HTML-backed legacy fields lossless on MySQL utf8mb3 as well as utf8mb4. */
    public static function encodeStoredText(string $value): string
    {
        return mb_encode_numericentity(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            [0x10000, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8', false);
    }

    public static function instant(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('Europe/Zurich'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date->format(DATE_ATOM) : null;
    }

    public static function user(array $row): array
    {
        $role = (int) $row['role'];
        return [
            'id' => (int) $row['userId'], 'username' => $row['username'],
            'firstName' => self::text($row['firstname']), 'lastName' => self::text($row['lastname']),
            'email' => $row['email'], 'gender' => (int) $row['gender'], 'role' => $role,
            'roleName' => [0 => 'user', 1 => 'admin', 2 => 'superadmin'][$role] ?? 'user',
            'enabled' => (bool) $row['enabled'], 'activated' => (bool) $row['activated'],
            'createdAt' => self::instant($row['created'] ?? null), 'lastLoginAt' => self::instant($row['lastLogin'] ?? null),
        ];
    }

    public static function member(array $row): array
    {
        return array_intersect_key(self::user($row), array_flip(['id', 'username', 'firstName', 'lastName', 'email']));
    }

    public static function project(array $row, array $actor): array
    {
        return [
            'id' => (int) $row['projectId'], 'name' => self::text($row['name']), 'description' => self::text($row['description']),
            'ownerId' => (int) $row['userId'], 'ownerName' => trim(self::text($row['ownerName'] ?? '')) ?: 'User #' . (int) $row['userId'], 'systemId' => (int) $row['systemId'],
            'systemName' => self::text($row['systemName'] ?? ''), 'archived' => (bool) $row['isArchived'],
            'finished' => (bool) $row['isFinished'], 'environment' => $row['environment'],
            'permissions' => [
                'canEdit' => true, 'canManageMembers' => Policy::manageProject($actor, $row),
                'canArchive' => Policy::manageProject($actor, $row), 'canCreateExperiments' => true,
            ],
        ];
    }

    public static function system(array $row, array $actor, string $root): array
    {
        $path = $root . '/webroot/systems/' . (int) $row['systemId'];
        $config = null;
        if (is_file($path . '/config.json') && filesize($path . '/config.json') <= 1048576) {
            try {
                $config = json_decode((string) file_get_contents($path . '/config.json'), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $config = null;
            }
        }
        return [
            'id' => (int) $row['systemId'], 'name' => self::text($row['name']), 'description' => self::text($row['description']),
            'ownerId' => (int) $row['userId'], 'ownerName' => trim(self::text($row['ownerName'] ?? '')) ?: 'User #' . (int) $row['userId'], 'archived' => (bool) $row['isArchived'], 'cem' => (bool) $row['cem'],
            'identifier' => is_array($config) && is_string($config['identifier'] ?? null) ? $config['identifier'] : null,
            'logoUrl' => '/api/ui/systems/' . (int) $row['systemId'] . '/logo',
            'createdAt' => self::instant($row['created']), 'updatedAt' => self::instant($row['lastEdit']),
            'hasLegacyResults' => !is_file($path . '/resultConfigurations.json') && (is_file($path . '/results.json') || is_file($path . '/resultsJob.json')),
            'permissions' => ['canEdit' => Policy::system($actor, $row), 'canChangeOwner' => Policy::admin($actor)],
        ];
    }

    public static function event(array $row): array
    {
        return [
            'id' => (int) $row['eventId'],
            'title' => strip_tags(self::text($row['title'])), 'text' => strip_tags(self::text($row['eventText'])),
            'at' => self::instant($row['time']), 'actorName' => trim(self::text($row['actorFirstName'] ?? '') . ' ' . self::text($row['actorLastName'] ?? '')),
            'resourceType' => $row['eventType'],
            'resourceId' => $row['eventType'] === 'node' ? ($row['nodeId'] ?? null) : (isset($row['relatedId']) ? (int) $row['relatedId'] : null),
        ];
    }
}
