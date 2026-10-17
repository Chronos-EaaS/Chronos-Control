<?php
declare(strict_types=1);

namespace Chronos\Http;

final class Request
{
    public const MAX_BODY_BYTES = 1048576;
    public readonly string $path;
    public readonly array $query;

    public function __construct(
        public readonly string $method,
        string $uri,
        private readonly array $headers = [],
        private readonly string $rawBody = '',
    ) {
        $parts = parse_url($uri);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            throw new HttpError(400, 'invalid_uri', 'Invalid request URI.');
        }
        $rawPath = $parts['path'] ?? '/';
        if (preg_match('/%2f|%5c|%00/i', $rawPath) || str_contains($rawPath, '\\')) {
            throw new HttpError(400, 'invalid_uri', 'Invalid request path.');
        }
        $this->path = rtrim(rawurldecode($rawPath), '/') ?: '/';
        parse_str($parts['query'] ?? '', $query);
        $this->query = $query;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        $headers['content-type'] = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            throw new HttpError(413, 'body_too_large', 'Request body is too large.');
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $_SERVER['REQUEST_URI'] ?? '/',
            $headers,
            (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1),
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function json(): array
    {
        if (strlen($this->rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpError(413, 'body_too_large', 'Request body is too large.');
        }
        if ($this->rawBody === '' && $this->method === 'DELETE') {
            return [];
        }
        if (strtolower(trim(explode(';', $this->header('content-type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required', 'Use Content-Type: application/json.');
        }
        try {
            $value = json_decode($this->rawBody, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'invalid_json', 'Request body must contain valid JSON.');
        }
        if (!$value instanceof \stdClass) {
            throw new HttpError(400, 'invalid_json', 'Request body must be a JSON object.');
        }
        $arrays = static function (mixed $entry) use (&$arrays): mixed {
            if ($entry instanceof \stdClass) {
                $entry = get_object_vars($entry);
            }
            return is_array($entry) ? array_map($arrays, $entry) : $entry;
        };
        return $arrays($value);
    }

    public function assertMethod(array $allowed): void
    {
        if (!in_array($this->method, $allowed, true)) {
            throw new HttpError(405, 'method_not_allowed', 'This method is not supported.', [], ['Allow' => implode(', ', $allowed)]);
        }
    }

    public function assertSameOrigin(string $scheme, string $host): void
    {
        if ($this->header('sec-fetch-site') === 'cross-site') {
            throw new HttpError(403, 'cross_origin', 'Cross-origin requests are not allowed.');
        }
        $origin = $this->header('origin');
        if ($origin !== '' && strtolower($origin) !== strtolower($scheme . '://' . $host)) {
            throw new HttpError(403, 'cross_origin', 'Cross-origin requests are not allowed.');
        }
    }

    public function assertCsrf(string $expected): void
    {
        $token = $this->header('x-csrf-token');
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            throw new HttpError(403, 'csrf_invalid', 'The session token is missing or expired. Reload the session and try again.');
        }
    }
}
