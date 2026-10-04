<?php

declare(strict_types=1);

namespace App\Domain\Shared\Time;

use App\Domain\Shared\Exception\InvariantViolation;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;

/**
 * The calendar day a transaction belongs to, in the business time zone
 * (Asia/Jakarta for DG Way).
 *
 * Timestamps are stored in UTC; reports group by BusinessDate. Without this,
 * a sale at 06:30 WIB (23:30 UTC the previous day) would land in the wrong
 * daily report.
 */
final class BusinessDate implements JsonSerializable
{
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    private function __construct(private readonly DateTimeImmutable $date)
    {
    }

    /** From an ISO date string 'YYYY-MM-DD'. */
    public static function fromString(string $isoDate): self
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $isoDate) {
            throw InvariantViolation::because('BUSINESS_DATE_INVALID', "Invalid business date '{$isoDate}'.");
        }

        return new self($parsed);
    }

    /** The business day on which the given instant falls, in the given zone. */
    public static function fromInstant(DateTimeInterface $instant, string $timezone = self::DEFAULT_TIMEZONE): self
    {
        $local = DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone($timezone));

        return self::fromString($local->format('Y-m-d'));
    }

    public function toString(): string
    {
        return $this->date->format('Y-m-d');
    }

    /** Compact form used inside document numbers and batch codes: YYMMDD. */
    public function compact(): string
    {
        return $this->date->format('ymd');
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function isBefore(self $other): bool
    {
        return $this->toString() < $other->toString();
    }

    public function addDays(int $days): self
    {
        return new self($this->date->modify(($days >= 0 ? '+' : '').$days.' days'));
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
