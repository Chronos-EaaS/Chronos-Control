<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function body(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->fields !== []) {
            $error['fields'] = $this->fields;
        }
        return ['error' => $error];
    }
}
