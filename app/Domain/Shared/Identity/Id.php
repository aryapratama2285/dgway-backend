<?php

declare(strict_types=1);

namespace App\Domain\Shared\Identity;

use App\Domain\Shared\Exception\InvariantViolation;
use JsonSerializable;

/**
 * Entity identity: a UUID (any version; new ids are UUIDv7).
 *
 * Database identity is always a UUID so records can be moved to Project B
 * (or merged from several operators) without key collisions. Human-readable
 * codes (SKU, partner code, node code) are separate value objects.
 */
final class Id implements JsonSerializable
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvariantViolation::because('ID_INVALID', "'{$value}' is not a valid identifier.");
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, strtolower(trim($value))) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
