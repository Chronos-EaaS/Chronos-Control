#!/usr/bin/env php
<?php
declare(strict_types=1);

use Chronos\Domain\Artifacts\ArtifactManifest;
use Chronos\Domain\Artifacts\ArtifactUri;
use Chronos\Infrastructure\Artifacts\ArtifactCache;
use Chronos\Infrastructure\Artifacts\ArtifactFiles;
use Chronos\Infrastructure\Artifacts\ArtifactResolver;
use Chronos\Infrastructure\Artifacts\ArtifactSource;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Build and setup hosts can run this command without the Control web dependencies.
$src = dirname(__DIR__, 2) . '/backend/src';
foreach (['Domain/Artifacts/ArtifactUri', 'Domain/Artifacts/ArtifactManifest', 'Infrastructure/Artifacts/ArtifactFiles',
    'Infrastructure/Artifacts/ArtifactSource', 'Infrastructure/Artifacts/ArtifactCache', 'Infrastructure/Artifacts/ArtifactResolver'] as $classFile) {
    require_once $src . '/' . $classFile . '.php';
}

function readArtifactInput(string $path, int $limit): string
{
    $input = ArtifactFiles::openRegular(str_starts_with($path, '/') ? $path : getcwd() . '/' . $path);
    try {
        $bytes = stream_get_contents($input, $limit + 1);
        if ($bytes === false || strlen($bytes) > $limit || !feof($input)) {
            throw new RuntimeException('Cannot read the input as a file of at most ' . $limit . ' bytes.');
        }
        return $bytes;
    } finally {
        fclose($input);
    }
}

function generateArtifactManifest(string $directory, array $inputs): ArtifactManifest
{
    if (count($inputs) > ArtifactManifest::MAX_ARTIFACTS) {
        throw new InvalidArgumentException('Specify at most 64 ROLE=FILE pairs.');
    }
    $source = new ArtifactSource(str_starts_with($directory, '/') ? $directory : getcwd() . '/' . $directory);
    $entries = [];
    $metadata = [];
    foreach (['sourceRevision' => 'CHRONOS_SOURCE_REVISION', 'buildId' => 'CHRONOS_BUILD_ID'] as $key => $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            $metadata[$key] = $value;
        }
    }

    $remaining = ArtifactManifest::MAX_TOTAL_BYTES;
    foreach ($inputs as $input) {
        if (!str_contains($input, '=')) {
            throw new InvalidArgumentException('Specify each artifact as ROLE=FILE.');
        }
        [$role, $uri] = explode('=', $input, 2);
        ArtifactUri::validate($uri);
        $file = $source->openLocal($uri);
        try {
            $identity = ArtifactFiles::measure($file, min($remaining, ArtifactManifest::MAX_ARTIFACT_BYTES));
            $remaining -= $identity['sizeBytes'];
        } finally {
            fclose($file);
        }
        $entries[] = (object) (['role' => $role, 'uri' => $uri] + $identity + $metadata);
    }

    return ArtifactManifest::fromDocument((object) ['schemaVersion' => ArtifactManifest::VERSION, 'artifacts' => $entries]);
}

function manifestFromCemResponse(string $path): ArtifactManifest
{
    // Other response fields, including the XML CDL, share this envelope.
    $envelope = json_decode(readArtifactInput($path, 8 * 1024 * 1024), false, 32, JSON_THROW_ON_ERROR);
    $status = $envelope->status->code ?? null;
    if ($status !== 200) {
        $suffix = is_string($envelope->status->message ?? null) ? ' ' . $envelope->status->message : '';
        throw new RuntimeException('CEM setup response must have status code 200.' . $suffix);
    }
    if (!isset($envelope->response->artifactManifest)) {
        throw new RuntimeException('CEM setup response is missing artifactManifest. Verify the CEM capability and system setup setting.');
    }
    return ArtifactManifest::fromDocument($envelope->response->artifactManifest);
}

function artifactUsage(): string
{
    return <<<'USAGE'
Usage:
  artifacts.php generate BUILD_DIR ROLE=FILE [ROLE=FILE ...]
  artifacts.php validate MANIFEST
  artifacts.php register MANIFEST CACHE SOURCE
  artifacts.php setup CEM_RESPONSE CACHE SOURCE

SOURCE is an absolute local directory or an approved HTTPS base URL.
Loopback HTTP (127.0.0.1) is supported for local builds and setup tests.
USAGE;
}

try {
    [$script, $command] = $argv + [1 => ''];
    $args = array_slice($argv, 2);
    $count = count($args);
    if ($command === '--help' && $count === 0) {
        echo artifactUsage(), "\n";
    } elseif ($command === 'generate' && $count >= 2) {
        echo generateArtifactManifest($args[0], array_slice($args, 1))->json();
    } elseif ($command === 'validate' && $count === 1) {
        echo ArtifactManifest::parse(readArtifactInput($args[0], ArtifactManifest::MAX_BYTES))->json();
    } elseif (in_array($command, ['register', 'setup'], true) && $count === 3) {
        [$input, $directory, $base] = $args;
        $manifest = $command === 'setup'
            ? manifestFromCemResponse($input)
            : ArtifactManifest::parse(readArtifactInput($input, ArtifactManifest::MAX_BYTES));
        $cache = new ArtifactCache($directory);
        $artifacts = (new ArtifactResolver(new ArtifactSource($base), $cache))->prepare($manifest);
        $json = $manifest->json();
        $manifestSha256 = hash('sha256', $json);
        $result = ['schemaVersion' => ArtifactManifest::VERSION, 'manifestSha256' => $manifestSha256, 'artifacts' => $artifacts];
        if ($command === 'register') {
            $result['manifestPath'] = $cache->store($manifestSha256, strlen($json), static function ($out) use ($json): void {
                if (@fwrite($out, $json) !== strlen($json)) {
                    throw new RuntimeException('Cannot stage the registered manifest.');
                }
            });
        }
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } else {
        fwrite(STDERR, artifactUsage() . "\n");
        exit(2);
    }
} catch (InvalidArgumentException | RuntimeException | JsonException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
