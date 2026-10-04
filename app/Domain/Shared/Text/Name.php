<?php

declare(strict_types=1);

namespace App\Domain\Shared\Text;

use App\Domain\Shared\Exception\InvariantViolation;
use JsonSerializable;

/**
 * A display name (product name, partner name, location name …):
 * trimmed, internal whitespace collapsed, 1–150 characters.
 */
final class Name implements JsonSerializable
{
    public const MAX_LENGTH = 150;

    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $value, string $label = 'Name'): self
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($clean === '') {
            throw InvariantViolation::because('NAME_REQUIRED', "{$label} is required.", ['field' => $label]);
        }
        if (mb_strlen($clean) > self::MAX_LENGTH) {
            throw InvariantViolation::because('NAME_TOO_LONG', "{$label} must be at most ".self::MAX_LENGTH.' characters.', ['field' => $label]);
        }

        return new self($clean);
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
