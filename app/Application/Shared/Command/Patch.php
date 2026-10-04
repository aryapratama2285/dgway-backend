<?php

declare(strict_types=1);

namespace App\Application\Shared\Command;

/**
 * The fields a PATCH request actually sent.
 *
 * Distinguishes "field absent" (leave unchanged) from "field sent as null"
 * (clear it), which a plain DTO with nullable properties cannot do.
 */
final class Patch
{
    /** @param array<string, mixed> $fields */
    public function __construct(private readonly array $fields)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function string(string $field): ?string
    {
        $v = $this->get($field);

        return $v === null ? null : (string) $v;
    }

    /** @return array<string, mixed>|null */
    public function array(string $field): ?array
    {
        $v = $this->get($field);

        return is_array($v) ? $v : null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }
}
