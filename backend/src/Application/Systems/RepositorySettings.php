<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Infrastructure\VersionControl\Repository;

use Chronos\Application\Shared\Input;

final class RepositorySettings
{
    public static function creation(mixed $value): array
    {
        $repository = DefinitionValues::arrays($value);
        if (!is_array($repository)) Input::invalid('repository', 'Submit repository settings.');
        Input::fields($repository, ['url', 'branch', 'type', 'username', 'password']);
        $type = $repository['type'] ?? null;
        if (!in_array($type, ['git', 'hg'], true)) Input::invalid('repository.type', 'Choose Git or Mercurial.');
        $url = Input::text($repository['url'] ?? null, 'repository.url', 256);
        $branch = Input::text($repository['branch'] ?? null, 'repository.branch', 50);
        $username = self::credential($repository['username'] ?? '', 'username', 50);
        $password = self::credential($repository['password'] ?? '', 'password', 256);
        if (preg_match('/[\x00-\x1f\x7f]/', $url . $branch . $username . $password) || str_starts_with($branch, '-') || trim($branch) !== $branch) Input::invalid('repository', 'Repository values must not contain control characters or option-like branch names.');
        $url = self::url($url);
        return ['vcsType' => $type, 'vcsUrl' => $url, 'vcsBranch' => $branch, 'vcsUser' => $username, 'vcsPassword' => $password];
    }

    public static function changes(mixed $value): array
    {
        $repository = DefinitionValues::arrays($value);
        if (!is_array($repository)) Input::invalid('repository', 'Submit repository settings.');
        Input::fields($repository, ['url', 'branch', 'type', 'username', 'password']);
        $changes = [];
        foreach (['url' => ['vcsUrl', 256], 'branch' => ['vcsBranch', 50], 'username' => ['vcsUser', 50], 'password' => ['vcsPassword', 256]] as $key => [$column, $max]) {
            if (!array_key_exists($key, $repository)) continue;
            $changes[$column] = in_array($key, ['username', 'password'], true)
                ? self::credential($repository[$key], $key, $max)
                : Input::text($repository[$key], 'repository.' . $key, $max, $key === 'branch');
        }
        if (isset($changes['vcsUrl']) && $changes['vcsUrl'] !== '') $changes['vcsUrl'] = self::url($changes['vcsUrl']);
        if (isset($repository['type'])) {
            if (!in_array($repository['type'], ['git', 'hg'], true)) Input::invalid('repository.type', 'Choose Git or Mercurial.');
            $changes['vcsType'] = $repository['type'];
        }
        return $changes;
    }

    private static function credential(mixed $value, string $field, int $max): string
    {
        // Credentials are opaque values. Validate the original text and keep
        // significant whitespace identical in the creation and update paths.
        Input::text($value, 'repository.' . $field, $max, false);
        if (mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            Input::invalid('repository.' . $field, 'Use a credential without control characters of at most ' . $max . ' characters.');
        }
        return $value;
    }

    private static function url(string $url): string
    {
        $parts = parse_url($url);
        $networkUrl = is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'ssh'], true) && ($parts['host'] ?? '') !== '' && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
        if ($networkUrl && isset($parts['user']) && strtolower($parts['scheme']) !== 'ssh') $networkUrl = false;
        $scpUrl = preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[^\\s]+$#D', $url) === 1;
        if ((!$networkUrl && !$scpUrl) || preg_match('/\\s/', $url) || preg_match('/[\\x00-\\x1f\\x7f]/', $url) || is_array($parts) && (isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))) Input::invalid('repository.url', 'Use an HTTP(S) or SSH repository address without embedded credentials, query strings or fragments.');
        return $url;
    }
}
