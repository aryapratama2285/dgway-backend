<?php

declare(strict_types=1);

namespace App\Domain\Shared\Code;

use App\Domain\Shared\Exception\InvariantViolation;
use JsonSerializable;

/**
 * Human-facing unique code of a master record: SKU, category code, partner
 * code, location code, node code.
 *
 * Rules (same for every kind of code, so operators learn one convention):
 * - stored upper-case; input is normalised ("tlr-a" → "TLR-A")
 * - 1–40 characters: letters, digits, '-', '_' and '.'
 * - must start with a letter or digit
 *
 * Uniqueness is guaranteed by the database (UNIQUE constraint), not by a
 * "check then insert" in code, so it holds under concurrent requests.
 *
 * Codes are immutable once a record is created: other systems, printed
 * labels and spreadsheets refer to them. Names can change; codes do not.
 */
final class BusinessCode implements JsonSerializable
{
    public const MAX_LENGTH = 40;
    private const PATTERN = '/^[A-Z0-9][A-Z0-9._\-]{0,39}$/';

    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $value, string $label = 'Code'): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvariantViolation::because(
                'CODE_INVALID',
                "{$label} '{$value}' is invalid. Use 1–40 letters, digits, '-', '_' or '.', starting with a letter or digit.",
                ['field' => $label],
            );
        }

        return new self($normalized);
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
