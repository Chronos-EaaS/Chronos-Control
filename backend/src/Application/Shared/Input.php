<?php
declare(strict_types=1);

namespace Chronos\Application\Shared;

use Chronos\Http\HttpError;
use Chronos\Domain\Configuration\CdlDocument;

final class Input
{
    public static function invalid(string $field, string $message): never
    {
        throw new HttpError(422, 'validation_failed', 'Please check the submitted values.', [$field => $message]);
    }

    public static function fields(array $body, array $allowed): void
    {
        foreach (array_keys($body) as $field) {
            if (!in_array($field, $allowed, true)) {
                self::invalid((string) $field, 'This field is not supported.');
            }
        }
    }

    public static function text(mixed $value, string $field, int $max, bool $required = true): string
    {
        if (!is_string($value) || !CdlDocument::acceptsText($value) || str_contains($value, "\x7f")) {
            self::invalid($field, 'Enter a valid text value.');
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $max || ($max >= 65535 && strlen($value) > 65535)) {
            self::invalid($field, $required ? 'Enter a value of at most ' . $max . ' characters.' : 'This value is too long.');
        }
        return $value;
    }

    public static function id(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || strlen((string) $value) > 10 || (int) $value > 2147483647) {
            self::invalid($field, 'Choose a valid positive identifier.');
        }
        return (int) $value;
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            self::invalid($field, 'Use true or false.');
        }
        return $value;
    }

    public static function queryBoolean(mixed $value, string $field): bool
    {
        if (!in_array($value, ['true', 'false', '1', '0'], true)) {
            self::invalid($field, 'Use true or false.');
        }
        return $value === 'true' || $value === '1';
    }

    public static function integer(mixed $value, string $field, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)
            || strlen((string) $value) > 10 || (int) $value < $min || (int) $value > $max) {
            self::invalid($field, 'Enter a number between ' . $min . ' and ' . $max . '.');
        }
        return (int) $value;
    }

    public static function listOptions(array $query, array $sorts, string $defaultSort = 'id'): array
    {
        self::fields($query, ['page', 'pageSize', 'search', 'sort', 'archived', 'all']);
        $sort = $query['sort'] ?? $defaultSort;
        if (!is_string($sort) || !isset($sorts[ltrim($sort, '-')]) || str_starts_with($sort, '--')) {
            self::invalid('sort', 'Choose a supported sort column.');
        }
        $archived = $query['archived'] ?? 'false';
        if (!in_array($archived, ['false', 'true', 'all'], true)) {
            self::invalid('archived', 'Use false, true or all.');
        }
        return [
            'page' => self::integer($query['page'] ?? '1', 'page', 1, 1000000),
            'pageSize' => self::integer($query['pageSize'] ?? '20', 'pageSize', 1, 100),
            'search' => self::text($query['search'] ?? '', 'search', 250, false),
            'order' => $sorts[ltrim($sort, '-')] . (str_starts_with($sort, '-') ? ' DESC' : ' ASC'),
            'archived' => $archived,
            'all' => self::queryBoolean($query['all'] ?? 'false', 'all'),
        ];
    }

    public static function identity(array $body, bool $required): array
    {
        $out = [];
        foreach (['firstName' => 'firstname', 'lastName' => 'lastname', 'email' => 'email'] as $field => $column) {
            if ($required || array_key_exists($field, $body)) {
                $text = self::text($body[$field] ?? null, $field, $field === 'email' ? 100 : 50);
                if ($field === 'email' ? !filter_var($text, FILTER_VALIDATE_EMAIL) : is_numeric($text)) {
                    self::invalid($field, $field === 'email' ? 'Enter a valid email address.' : 'Enter a name.');
                }
                $out[$column] = $text;
            }
        }
        if ($required || array_key_exists('gender', $body)) {
            $out['gender'] = self::integer($body['gender'] ?? null, 'gender', 0, 2);
        }
        return $out;
    }

    public static function password(mixed $value, mixed $confirmation, int $min, int $max, bool $complex): string
    {
        if (!is_string($value) || strlen($value) < $min || strlen($value) > min($max, 72)
            || str_contains($value, "\0") || ($complex && (!preg_match('/[a-z]/', $value) || !preg_match('/[A-Z]/', $value) || !preg_match('/[0-9]/', $value)))) {
            self::invalid('password', 'The password does not meet the configured requirements.');
        }
        if (!is_string($confirmation) || !hash_equals($value, $confirmation)) {
            self::invalid('passwordConfirmation', 'Passwords must match.');
        }
        return $value;
    }
}
