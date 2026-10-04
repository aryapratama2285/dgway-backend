<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contact;

use App\Domain\Shared\Exception\InvariantViolation;
use JsonSerializable;

final class EmailAddress implements JsonSerializable
{
    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $raw): self
    {
        $value = mb_strtolower(trim($raw));
        if ($value === '' || mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw InvariantViolation::because('EMAIL_INVALID', "'{$raw}' is not a valid email address.", ['field' => 'email']);
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
