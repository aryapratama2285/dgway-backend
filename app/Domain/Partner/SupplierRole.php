<?php

declare(strict_types=1);

namespace App\Domain\Partner;

use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Exception\InvariantViolation;

/**
 * The supplier side of a business partner (entity inside the
 * BusinessPartner aggregate; only changed through the partner).
 *
 * Purchase prices are deliberately NOT stored here: they change daily and are
 * recorded per purchase / goods receipt in A3, which gives price history and
 * supplier analytics (average price, last price, effective HPP).
 */
final class SupplierRole
{
    private function __construct(
        private PaymentTerms $terms,
        private ?string $notes,
        private bool $active,
    ) {
    }

    public static function start(PaymentTerms $terms, ?string $notes = null): self
    {
        self::assertNoCreditLimit($terms);

        return new self($terms, Notes::clean($notes), true);
    }

    public static function reconstitute(PaymentTerms $terms, ?string $notes, bool $active): self
    {
        return new self($terms, $notes, $active);
    }

    /** @internal via BusinessPartner */
    public function changeTerms(PaymentTerms $terms): bool
    {
        self::assertNoCreditLimit($terms);
        if ($this->terms->equals($terms)) {
            return false;
        }
        $this->terms = $terms;

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
        return ['payment_term_days' => $this->terms->termDays, 'notes' => $this->notes, 'is_active' => $this->active];
    }

    private static function assertNoCreditLimit(PaymentTerms $terms): void
    {
        if ($terms->creditLimit !== null) {
            throw InvariantViolation::because('SUPPLIER_CREDIT_LIMIT_NOT_APPLICABLE', 'A credit limit applies to customers, not suppliers.');
        }
    }
}
