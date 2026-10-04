<?php

declare(strict_types=1);

namespace App\Domain\Partner;

use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Exception\InvariantViolation;

/**
 * The customer side of a business partner (entity inside the
 * BusinessPartner aggregate).
 *
 * A simple cash customer needs nothing but a type: terms default to cash.
 */
final class CustomerRole
{
    private function __construct(
        private CustomerType $type,
        private PaymentTerms $terms,
        private ?string $notes,
        private bool $active,
    ) {
    }

    public static function start(CustomerType $type, ?PaymentTerms $terms = null, ?string $notes = null): self
    {
        $terms ??= PaymentTerms::cash();
        self::assertConsistent($type, $terms);

        return new self($type, $terms, Notes::clean($notes), true);
    }

    public static function reconstitute(CustomerType $type, PaymentTerms $terms, ?string $notes, bool $active): self
    {
        return new self($type, $terms, $notes, $active);
    }

    /** @internal via BusinessPartner */
    public function changeTerms(PaymentTerms $terms): bool
    {
        self::assertConsistent($this->type, $terms);
        if ($this->terms->equals($terms)) {
            return false;
        }
        $this->terms = $terms;

        return true;
    }

    /** @internal via BusinessPartner */
    public function reclassify(CustomerType $type): bool
    {
        self::assertConsistent($type, $this->terms);
        if ($this->type === $type) {
            return false;
        }
        $this->type = $type;

        return true;
    }

    /** @internal via BusinessPartner */
    public function changeNotes(?string $notes): bool
    {
        $notes = Notes::clean($notes);
        if ($this->notes === $notes) {
            return false;
        }
        $this->notes = $notes;

        return true;
    }

    /** @internal via BusinessPartner */
    public function setActive(bool $active): bool
    {
        if ($this->active === $active) {
            return false;
        }
        $this->active = $active;

        return true;
    }

    public function type(): CustomerType
    {
        return $this->type;
    }

    public function terms(): PaymentTerms
    {
        return $this->terms;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'customer_type' => $this->type->value,
            'payment_term_days' => $this->terms->termDays,
            'credit_limit' => $this->terms->creditLimit?->toStorage(),
            'notes' => $this->notes,
            'is_active' => $this->active,
        ];
    }

    /** Walk-in customers are anonymous counter sales: always cash, no credit. */
    private static function assertConsistent(CustomerType $type, PaymentTerms $terms): void
    {
        if ($type === CustomerType::WALK_IN && (! $terms->isCash() || $terms->creditLimit !== null)) {
            throw InvariantViolation::because('WALK_IN_MUST_BE_CASH', 'Walk-in customers are cash only (no tempo, no credit limit).');
        }
    }
}
