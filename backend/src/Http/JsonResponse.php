<?php
declare(strict_types=1);

namespace Chronos\Http;

final class JsonResponse
{
    public static function success(mixed $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => ['data' => $data]];
    }
}
