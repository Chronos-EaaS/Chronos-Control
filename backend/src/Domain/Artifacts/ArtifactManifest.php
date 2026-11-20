<?php
declare(strict_types=1);

namespace Chronos\Domain\Artifacts;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class ArtifactManifest
{
    public const VERSION = 1;
    public const MAX_BYTES = 60 * 1024;
    public const MAX_ARTIFACTS = 64;
    public const MAX_ARTIFACT_BYTES = 1024 ** 3;
    public const MAX_TOTAL_BYTES = 2 * 1024 ** 3;

    private function __construct(public readonly array $artifacts, private readonly array $provenance) {}

    public static function parse(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Artifact manifest exceeds 60 KiB.');
        }
        try {
            $document = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid artifact manifest JSON: ' . $error->getMessage(), 0, $error);
        }
        return self::fromDocument($document);
    }

    public static function fromDocument(mixed $document): self
    {
        if (!$document instanceof stdClass || ($document->schemaVersion ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Expected artifact manifest schemaVersion 1.');
        }
        if (array_diff(array_keys((array) $document), ['schemaVersion', 'artifacts', 'sourceRevision', 'buildId'])) {
            throw new InvalidArgumentException('Artifact manifest contains an unrecognized field.');
        }
        if (!is_array($document->artifacts ?? null) || $document->artifacts === [] || count($document->artifacts) > self::MAX_ARTIFACTS) {
            throw new InvalidArgumentException('Artifact manifest must contain 1 to 64 artifact entries.');
        }

        $artifacts = [];
        $roles = [];
        $total = 0;
        foreach ($document->artifacts as $index => $entry) {
            try {
                $artifact = self::entry($entry);
                $role = $artifact['role'];
                if (isset($roles[$role])) {
                    throw new InvalidArgumentException('Duplicate artifact role ' . $role . '.');
                }
                $roles[$role] = true;
                $total += $artifact['sizeBytes'];
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new InvalidArgumentException('Total declared artifact size exceeds 2 GiB.');
                }
                $artifacts[] = $artifact;
            } catch (InvalidArgumentException $error) {
                throw new InvalidArgumentException('Artifact entry ' . ($index + 1) . ': ' . $error->getMessage(), 0, $error);
            }
        }

        $manifest = new self($artifacts, self::provenance($document));
        if (strlen($manifest->json()) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Artifact manifest exceeds 60 KiB.');
        }
        return $manifest;
    }

    private static function entry(mixed $entry): array
    {
        if (!$entry instanceof stdClass || array_diff(array_keys((array) $entry), ['role', 'uri', 'sha256', 'sizeBytes', 'sourceRevision', 'buildId'])) {
            throw new InvalidArgumentException('Expected an artifact object with known fields.');
        }
        if (!is_string($entry->role ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $entry->role)) {
            throw new InvalidArgumentException('Invalid artifact role.');
        }
        if (!is_string($entry->sha256 ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $entry->sha256)) {
            throw new InvalidArgumentException('Expected a lowercase hexadecimal SHA-256 digest.');
        }
        if (!is_int($entry->sizeBytes ?? null) || $entry->sizeBytes < 0 || $entry->sizeBytes > self::MAX_ARTIFACT_BYTES) {
            throw new InvalidArgumentException('sizeBytes must be an integer between 0 and 1 GiB.');
        }
        if (!is_string($entry->uri ?? null)) {
            throw new InvalidArgumentException('Missing artifact URI.');
        }
        ArtifactUri::validate($entry->uri);

        $fields = ['role' => $entry->role, 'uri' => $entry->uri, 'sha256' => $entry->sha256, 'sizeBytes' => $entry->sizeBytes];
        return $fields + self::provenance($entry);
    }

    private static function provenance(stdClass $entry): array
    {
        $fields = [];
        foreach (['sourceRevision', 'buildId'] as $key) {
            if (property_exists($entry, $key)) {
                if (!is_string($entry->$key) || strlen($entry->$key) > 256 || trim($entry->$key) === ''
                    || preg_match('/[\x00-\x1f\x7f]/', $entry->$key)) {
                    throw new InvalidArgumentException($key . ' must be nonempty text of at most 256 bytes without control characters.');
                }
                $fields[$key] = $entry->$key;
            }
        }
        return $fields;
    }

    public function document(): array
    {
        return ['schemaVersion' => self::VERSION, 'artifacts' => $this->artifacts] + $this->provenance;
    }

    public function json(): string
    {
        return json_encode($this->document(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
