<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contact;

use App\Domain\Shared\Exception\InvariantViolation;
use JsonSerializable;

/**
 * Phone number normalised to E.164 (+62…).
 *
 * Accepts the formats operators actually type for Indonesian numbers:
 * "0812-3456-7890", "62 812 3456 7890", "+62812…", "(021) 555 1234".
 * Normalising matters: the WhatsApp-paste parser (A4) matches customers by
 * phone, and "0812…" vs "+62812…" must be the same customer.
 */
final class PhoneNumber implements JsonSerializable
{
    private function __construct(public readonly string $e164)
    {
    }

    public static function of(string $raw): self
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        if (str_starts_with($digits, '+')) {
            $normalized = $digits;
        } elseif (str_starts_with($digits, '62')) {
            $normalized = '+'.$digits;
        } elseif (str_starts_with($digits, '0')) {
            $normalized = '+62'.substr($digits, 1);
        } else {
            $normalized = '+'.$digits;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $normalized) !== 1) {
            throw InvariantViolation::because('PHONE_INVALID', "'{$raw}' is not a valid phone number.", ['field' => 'phone']);
        }

        return new self($normalized);
    }

    /** Local display form for Indonesian numbers (0812…); E.164 otherwise. */
    public function local(): string
    {
        return str_starts_with($this->e164, '+62') ? '0'.substr($this->e164, 3) : $this->e164;
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function jsonSerialize(): string
    {
        return $this->e164;
    }
}
