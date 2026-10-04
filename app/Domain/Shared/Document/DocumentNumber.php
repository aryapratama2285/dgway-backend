<?php

declare(strict_types=1);

namespace App\Domain\Shared\Document;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Time\BusinessDate;
use JsonSerializable;

/**
 * Human-readable, unique document identifier: PREFIX-YYMMDD-NNN.
 *
 * Examples: SO-261003-001, GR-261003-012, EGG-261003-001, EGG-BDG-261003-01x.
 *
 * - The prefix is data, not code: it may contain several segments
 *   (EGG, EGG-BDG, BWM …), so batch codes are not locked to eggs (D14).
 * - The sequence restarts per prefix per business date and is padded to at
 *   least 3 digits; it simply grows wider past 999 rather than overflowing.
 * - A document number is a display identifier. Database identity is always a
 *   UUID, so numbering can change in Project B without breaking references.
 */
final class DocumentNumber implements JsonSerializable
{
    public const MIN_PADDING = 3;
    private const PREFIX_PATTERN = '/^[A-Z][A-Z0-9]{0,9}(-[A-Z][A-Z0-9]{0,9}){0,2}$/';

    private function __construct(
        public readonly string $prefix,
        public readonly BusinessDate $date,
        public readonly int $sequence,
    ) {
    }

    public static function compose(string $prefix, BusinessDate $date, int $sequence): self
    {
        $prefix = self::normalizePrefix($prefix);
        if ($sequence < 1) {
            throw InvariantViolation::because('DOCUMENT_SEQUENCE_INVALID', 'Document sequence must start at 1.');
        }

        return new self($prefix, $date, $sequence);
    }

    public static function parse(string $value): self
    {
        if (preg_match('/^(?<prefix>.+)-(?<date>\d{6})-(?<seq>\d{3,})$/', $value, $m) !== 1) {
            throw InvariantViolation::because('DOCUMENT_NUMBER_INVALID', "Invalid document number '{$value}'.");
        }

        $date = BusinessDate::fromString(sprintf('20%s-%s-%s', substr($m['date'], 0, 2), substr($m['date'], 2, 2), substr($m['date'], 4, 2)));

        return self::compose($m['prefix'], $date, (int) $m['seq']);
    }

    public static function normalizePrefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix));
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw InvariantViolation::because(
                'DOCUMENT_PREFIX_INVALID',
                "Invalid document prefix '{$prefix}'. Use 1–3 uppercase segments separated by '-', e.g. SO or EGG-BDG.",
            );
        }

        return $prefix;
    }

    public function toString(): string
    {
        return sprintf('%s-%s-%s', $this->prefix, $this->date->compact(), str_pad((string) $this->sequence, self::MIN_PADDING, '0', STR_PAD_LEFT));
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
